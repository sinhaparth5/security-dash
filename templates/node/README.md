# Patchwatch reporter for Node

[`patchwatch.mjs`](patchwatch.mjs) is a single file with no dependencies. It needs Node 18 or newer. It sends a heartbeat containing two things:

- the Node runtime version (`process.version`)
- every production package in your lockfile, including transitive ones

It reads the lockfiles of npm, pnpm, Yarn and Bun:

| Package manager | Lockfile                                   | Versions                     |
| --------------- | ------------------------------------------ | ---------------------------- |
| npm             | `package-lock.json`, `npm-shrinkwrap.json` | lockfile v2/v3 (npm 7+)      |
| pnpm            | `pnpm-lock.yaml`                           | lockfile 5.x, 6.0, 9.0       |
| Yarn            | `yarn.lock`                                | classic (v1) and Berry (v2+) |
| Bun             | `bun.lock`                                 | text lockfile (Bun 1.2+)     |

It looks for them in the working directory in that order and uses the first one it finds.

## 1. Get the URL and token

1. In Patchwatch, click **Register service**, or open an existing service and click **Issue new token**.
2. Copy the **Heartbeat URL** and the **Token**.
3. Set both as environment variables (secrets) on the service:

```bash
PATCHWATCH_URL=https://patchwatch.example.com/api/v1/telemetry/heartbeat
PATCHWATCH_TOKEN=12|xxxxxxxxxxxxxxxxxxxxxxxx
```

Every service posts to the same URL. The token identifies which service is reporting, so each service and environment needs its own token.

| Variable              | Required | Default                                |
| --------------------- | -------- | -------------------------------------- |
| `PATCHWATCH_URL`      | yes      |                                        |
| `PATCHWATCH_TOKEN`    | yes      |                                        |
| `PATCHWATCH_LOCKFILE` | no       | auto-detected in the working directory |

## 2. Add the file

Copy `patchwatch.mjs` into your project, for example as `src/patchwatch.mjs`.

## 3. Send heartbeats

### Long-running server (Express, Fastify, Nest, plain `http`)

Start the reporter once when the server boots. It reports immediately, then every 6 hours:

```js
import { startPatchwatch } from './patchwatch.mjs';

startPatchwatch();
```

Failures are logged as warnings and never thrown, so if Patchwatch is down your app keeps running. The timer is `unref()`'d, so it won't keep the process alive on shutdown. To change the interval, pass `startPatchwatch({ intervalMs: 60 * 60 * 1000 })`.

### Astro

- **SSR with `@astrojs/node`:** start the reporter from `src/middleware.ts`. Module-level code in middleware runs once, when the server starts:

    ```ts
    import { defineMiddleware } from 'astro:middleware';
    import { startPatchwatch } from './patchwatch.mjs';

    startPatchwatch();

    export const onRequest = defineMiddleware((_context, next) => next());
    ```

- **Static build (`output: 'static'`):** nothing runs on a server, so report from your deploy pipeline instead (see below).

### Cron, CI or a deploy step

Run the file directly. It sends one heartbeat and exits non-zero on failure:

```bash
node patchwatch.mjs
```

For example, in GitHub Actions after deploying:

```yaml
- run: node src/patchwatch.mjs
  env:
      PATCHWATCH_URL: ${{ secrets.PATCHWATCH_URL }}
      PATCHWATCH_TOKEN: ${{ secrets.PATCHWATCH_TOKEN }}
```

A service that hasn't reported for 24 hours shows as **Stale**. If you only report on deploy, expect that flag between releases, or run the command from a daily cron job.

## Docker

The reporter reads the lockfile at runtime, so copy it into the final image next to `package.json`:

```dockerfile
# npm
COPY package.json package-lock.json ./
RUN npm ci --omit=dev

# pnpm
COPY package.json pnpm-lock.yaml ./
RUN corepack enable && pnpm install --prod --frozen-lockfile

# Yarn (Berry)
COPY package.json yarn.lock .yarnrc.yml ./
RUN corepack enable && yarn workspaces focus --production

# Bun
COPY package.json bun.lock ./
RUN bun install --production --frozen-lockfile
```

## Notes

- **Dev dependencies are skipped**, since they aren't deployed. npm and pnpm 5/6 lockfiles mark them. For pnpm 9, Yarn and Bun, the reporter starts from your `dependencies` and `optionalDependencies` and follows the lockfile from there.
- **One version per package name.** If a package is installed at several versions, only the copy closest to your own dependencies is reported.
- **Yarn workspaces:** only the `package.json` next to `yarn.lock` is followed. In a monorepo, point `PATCHWATCH_LOCKFILE` at the root lockfile and list the service's dependencies in the root `package.json`, or report from CI. npm, pnpm and Bun cover all workspaces.
- **`bun.lockb`** (the binary lockfile from Bun before 1.2) can't be read. Run `bun install --save-text-lockfile` to get `bun.lock`.
- **Yarn Plug'n'Play** is fine. The reporter only reads `yarn.lock`, not `node_modules`.
- **Container images** are reported separately, from CI with Trivy. See [Report container image scans from CI](../../README.md#3-report-container-image-scans-from-ci).

## Troubleshooting

| Response | Fix                                                                            |
| -------- | ------------------------------------------------------------------------------ |
| `401`    | Token missing, mistyped or revoked. Issue a new one on the service page.       |
| `422`    | Payload rejected. The response body names the field.                           |
| `429`    | More than 60 requests a minute. The default 6-hour interval is far below that. |
