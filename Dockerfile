# syntax=docker/dockerfile:1

# FrankenPHP serves the app directly (Caddy + PHP in one process); no nginx/php-fpm pair to wire up.
FROM dunglas/frankenphp:1-php8.5-trixie AS base

WORKDIR /app

RUN install-php-extensions pdo_pgsql pcntl intl zip \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Baked-in production defaults. DB_CONNECTION is pinned to PostgreSQL; the app refuses to boot otherwise.
ENV APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=pgsql \
    LOG_CHANNEL=stderr \
    SERVER_NAME=:8080

# ---------------------------------------------------------------------------
# Build: PHP is needed next to Node because the Wayfinder Vite plugin runs artisan.
FROM base AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:24-trixie-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-trixie-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY . .
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && composer check-platform-reqs --no-dev \
    && npm run build \
    && rm -rf node_modules

# ---------------------------------------------------------------------------
FROM base AS app

ARG UID=10001
ARG GID=10001

# Code is owned by root and read-only to the app; only runtime caches are writable by it.
RUN groupadd --gid "${GID}" laravel \
    && useradd --uid "${UID}" --gid "${GID}" --no-create-home --shell /usr/sbin/nologin laravel

COPY --from=build --chown=root:root /app /app

RUN chown -R laravel:laravel storage bootstrap/cache /data/caddy /config/caddy \
    && chmod -R ug=rwX,o= storage bootstrap/cache

USER laravel

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD ["php", "-r", "exit(@file_get_contents('http://127.0.0.1:8080/up') === false ? 1 : 0);"]

# Config/route/view caches depend on runtime env, so they are built at start, not at image build.
ENTRYPOINT ["sh", "-c", "php artisan optimize && exec \"$@\"", "--"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
