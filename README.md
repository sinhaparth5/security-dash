# Patchwatch

Patchwatch is an intranet dashboard that shows whether your deployed services are safe to keep running. Services report the runtime and dependency versions they actually run, and Patchwatch checks those versions against upstream release feeds and the [OSV](https://osv.dev) vulnerability database:

| Status                        | Meaning                                                             |
| ----------------------------- | ------------------------------------------------------------------- |
| **Patch required** (red)      | A known advisory (CVE/GHSA) affects the running version             |
| **Update available** (yellow) | A newer release exists, but no advisory affects the running version |
| **Up to date** (green)        | Latest release, no known advisory                                   |
| **Not evaluated**             | Untracked ecosystem, or no upstream data yet                        |

A service takes the grade of its worst dependency. When a service first turns red, every dashboard user is emailed.

## How it works

```
services ──heartbeat──▶ POST /api/v1/telemetry/heartbeat ─┐
CI (Trivy) ─scan──────▶ POST /api/v1/telemetry/image-scan ─┤
                                                           ▼
                              EvaluateService job (queued; also hourly)
                                ├─ OSV querybatch      → advisories
                                ├─ npm registry / Go proxy → latest package versions
                                └─ go.dev / nodejs.org → latest runtime releases
                                                           ▼
                                  database ──▶ dashboard (polls every minute)
```

- **Go** modules and the **Go runtime** are checked against OSV and `proxy.golang.org`/`go.dev`.
- **npm** packages are checked against OSV and the npm registry.
- The **Node runtime** is checked against the `nodejs.org` release index. A newer _security_ release in the same major line counts as red.
- Runtimes are compared within their release line (Go minor, Node major), so a healthy LTS install isn't flagged just because a newer major exists.
- **Container images** keep the grade their latest Trivy scan gave them.
- Upstream responses are cached. If an upstream is unavailable, the evaluation fails and retries, and the last grade stays in place. An outage is never read as "no advisories".

## Requirements

- PHP 8.3+ (CI uses 8.5) with `pdo_sqlite` for development and `pdo_pgsql` for production
- Composer 2
- Node.js 22+
- PostgreSQL in production. The app refuses to boot in production on anything else.
- A [GitHub OAuth app](https://github.com/settings/developers) for sign-in, with the callback URL `${APP_URL}/auth/github/callback`

## Local development

```bash
composer setup     # install deps, create .env, generate the app key, migrate, build assets
composer dev       # app server, queue worker and Vite together
```

Then fill in `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET` and `GITHUB_ALLOWED_LOGINS` (comma-separated GitHub usernames) in `.env`. To get demo services on the dashboard, run `php artisan db:seed`.

### Checks

```bash
composer test                                    # Pint, PHPStan (level 7), Pest
composer ci:check                                # everything CI runs, frontend lint and types included
php artisan test --filter=HeartbeatTest          # a single test
composer lint                                    # fix PHP formatting
npm run check:fix                                # fix frontend formatting and lint
```

## Reporting telemetry

### 1. Issue a service token

```bash
php artisan service:token billing-api production
```

This registers the service if it's new and prints a token once. Store it as a secret in the service's environment.

### 2. Send heartbeats

Each heartbeat sends the **full** dependency manifest. Anything left out is removed. If a version changed, that dependency resets to "Not evaluated" until it's checked again.

```bash
curl -X POST https://patchwatch.example.com/api/v1/telemetry/heartbeat \
  -H "Authorization: Bearer $PATCHWATCH_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "dependencies": [
      { "ecosystem": "runtime", "name": "go",   "version": "go1.25.3" },
      { "ecosystem": "go",      "name": "golang.org/x/net", "version": "v0.40.0" },
      { "ecosystem": "runtime", "name": "node", "version": "v22.11.0" },
      { "ecosystem": "npm",     "name": "express", "version": "4.21.2" }
    ]
  }'
```

- `ecosystem` must be one of `runtime`, `go` or `npm`. Runtime names that get graded are `go` and `node`.
- `v` and `go` version prefixes are fine.
- A single heartbeat accepts up to 5,000 dependencies.
- A successful heartbeat returns `204 No Content`.
- A service that hasn't reported for 24 hours is flagged as stale.

### 3. Report container image scans from CI

Post the unmodified JSON output of `trivy image`. Any finding in the report turns the image red, so filter in CI:

```bash
trivy image --format json --severity HIGH,CRITICAL --ignore-unfixed \
  registry.example.com/billing-api:1.4.2 > scan.json

curl -X POST https://patchwatch.example.com/api/v1/telemetry/image-scan \
  -H "Authorization: Bearer $PATCHWATCH_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  --data-binary @scan.json
```

### API behaviour

| Status | When                                                                            |
| ------ | ------------------------------------------------------------------------------- |
| `204`  | Report accepted                                                                 |
| `401`  | Missing or invalid token                                                        |
| `403`  | The token doesn't belong to a service (for example, a dashboard user's session) |
| `422`  | Invalid payload. The body lists the field errors.                               |
| `429`  | More than 60 requests per minute for this token. Respect `Retry-After`.         |

## Deployment

Every push to `master` runs `.github/workflows/ci.yml`:

1. **check:** `composer ci:check`, the same checks as local development.
2. **image:** builds the [FrankenPHP](https://frankenphp.dev) image and pushes it to `ghcr.io/<owner>/<repo>` as `:latest` and `:<short sha>`.
3. **deploy:** connects to the VPS over SSH, pulls that exact tag, runs `php artisan migrate --force` in a one-off container, then replaces three containers built from the same image:

| Container              | Role                                                                                                                    |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `patchwatch-web`       | The app on `127.0.0.1:18092`. Put a TLS-terminating reverse proxy in front of it, and make it send `X-Forwarded-Proto`. |
| `patchwatch-queue`     | `queue:work`: evaluations and alert emails                                                                              |
| `patchwatch-scheduler` | `schedule:work`: the hourly re-evaluation                                                                               |

If the migration fails, the deploy stops and the old containers keep running. To roll back, re-run the deploy for an older commit, or `docker run` its `:<short sha>` tag.

**Server setup (one time)**

- **Env file:** `/opt/patchwork/patchwatch.env`, owned by the deploy user with mode `600`. Start from `.env.production.example`. Docker reads it literally, so use no quotes and no `${VAR}` references. Generate `APP_KEY` with `php artisan key:generate --show`.
- **Database:** PostgreSQL in the `goc-postgres` container. The containers join its Docker network, so `DB_HOST=goc-postgres`.
- **Deploy user:** must be in the `docker` group.
- **Repository secrets:** `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `GHCR_USERNAME`, and `GHCR_TOKEN` (a token with `read:packages`).

`compose.yaml` still describes a self-contained stack, with its own PostgreSQL, for running the production image somewhere else.

### Email alerts (Cloudflare Email Service)

Red alerts are sent through [Cloudflare Email Service](https://developers.cloudflare.com/email-service/) over SMTP, using Laravel's built-in `smtp` mailer:

1. Onboard your sending domain under **Email Sending** in the Cloudflare dashboard (or run `npx wrangler email sending enable yourdomain.com`). This adds the SPF and DKIM records. Sending to arbitrary recipients requires the Workers Paid plan.
2. Create an account API token with the **Email Sending: Edit** permission.
3. Set the mail variables:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps            # Cloudflare only accepts implicit TLS on 465; STARTTLS on 587 is not supported
MAIL_HOST=smtp.mx.cloudflare.net
MAIL_PORT=465
MAIL_USERNAME=api_token      # the literal string
MAIL_PASSWORD=<api token>
MAIL_FROM_ADDRESS=patchwatch@yourdomain.com   # must be on the onboarded domain
```

Alerts go to each user's email address from their profile settings, and are queued, so the **queue** container must be running. Limits: 50 recipients per message and 5 MiB per message. The daily quota starts conservative and grows with your sending reputation.

## Tech stack

- **Backend:** Laravel 13, Sanctum (service tokens), Socialite (GitHub sign-in), queued jobs and the scheduler
- **Frontend:** Inertia v3, Vue 3, TypeScript, Tailwind CSS v4, shadcn-vue (reka-ui), Wayfinder for typed routes
- **Quality:** Pest, PHPStan/Larastan level 7, Pint, vite-plus lint and format, vue-tsc
