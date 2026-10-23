# Patchwatch

Patchwatch is an intranet dashboard that shows whether your deployed services are safe to keep running. Services report the runtime and dependency versions they actually run, and Patchwatch checks those versions against upstream release feeds and the [OSV](https://osv.dev) vulnerability database:

| Status                        | Meaning                                                                                     |
| ----------------------------- | ------------------------------------------------------------------------------------------- |
| **Patch required** (red)      | A known advisory (CVE/GHSA) affects the running version, or the runtime is past end of life |
| **Update available** (yellow) | A newer release exists, but no advisory affects the running version                         |
| **Up to date** (green)        | Latest release, no known advisory                                                           |
| **Not evaluated**             | Untracked ecosystem, or no upstream data yet                                                |

A service takes the grade of its worst dependency. When a service first turns red, or a package OSV reports as **malware** (`MAL-` advisories) first shows up, every dashboard user is emailed.

Advisories in CISA's [Known Exploited Vulnerabilities](https://www.cisa.gov/known-exploited-vulnerabilities-catalog) catalog are marked **Exploited in the wild**, and each CVE shows its [EPSS](https://www.first.org/epss/) score (the chance of exploitation in the next 30 days), so you know what to fix first. On a service's page, **Accept risk** records why an advisory doesn't apply (with an optional re-check date); while accepted it no longer turns the dependency red.

## How it works

```
services ──heartbeat──▶ POST /api/v1/telemetry/heartbeat ─┐
CI (SBOM) ──CycloneDX─▶ POST /api/v1/telemetry/sbom ───────┤
CI (Trivy) ─scan──────▶ POST /api/v1/telemetry/image-scan ─┤
                                                           ▼
                              EvaluateService job (queued; also hourly)
                                ├─ OSV querybatch      → advisories
                                ├─ npm registry / Go proxy → latest package versions
                                ├─ go.dev / nodejs.org → latest runtime releases
                                ├─ endoflife.date      → runtime end-of-life dates
                                ├─ CISA KEV / FIRST EPSS → exploitation signals
                                └─ TypeSafe Jev (optional) → triage suggestions
                                                           ▼
                                  database ──▶ dashboard (polls every minute)
```

- **Go** modules and the **Go runtime** are checked against OSV and `proxy.golang.org`/`go.dev`.
- **npm** packages are checked against OSV and the npm registry.
- The **Node runtime** is checked against the `nodejs.org` release index. A newer _security_ release in the same major line counts as red.
- Runtimes are compared within their release line (Go minor, Node major), so a healthy LTS install isn't flagged just because a newer major exists.
- A **Go or Node runtime past end of life** (from [endoflife.date](https://endoflife.date)) is red, and one within 180 days of it is flagged on the service page.
- **Container images** keep the grade their latest Trivy scan gave them.
- Upstream responses are cached. If an upstream is unavailable, the evaluation fails and retries, and the last grade stays in place. An outage is never read as "no advisories". KEV and EPSS are the exception: they only add badges, so an outage there just hides them until the next evaluation.
- If no evaluation has finished for two hours, or queued jobs have failed, the dashboard shows a warning: check the queue worker and the scheduler.

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

### 1. Register the service

On the dashboard, click **Register service** and enter a name and environment. Patchwatch creates the service and shows its token (once), the heartbeat and image-scan URLs, and copyable heartbeat and Trivy image-scan examples. Store the token as a secret in the service's environment. Registering an existing name and environment issues an extra token and leaves the old ones working.

To rotate a token, open **Tokens** in the sidebar, select the service, click **Issue new token** (optionally with an expiry), deploy the new token, then click **Revoke** on the old one. The same page shows the heartbeat and image-scan URLs. Each token shows when it was created, last used and expires, and tokens unused for 30 days are flagged, so you can tell which one is live. A revoked or expired token gets `401` from its next request; expired ones are pruned daily.

The same page has an **IP allowlist** per service: IPs or CIDR ranges it reports from. A token used from anywhere else gets `403`. The client IP comes from `X-Forwarded-For`, so the reverse proxy must set it.

Tokens look like `42|pw_…` (set by `SANCTUM_TOKEN_PREFIX`). To have GitHub block a leaked one, add a [custom secret scanning pattern](https://docs.github.com/en/code-security/how-tos/secure-your-secrets/customize-leak-detection/define-custom-patterns) `[0-9]+\|pw_[A-Za-z0-9]{48}` and enable it in push protection. Tokens issued before the prefix existed keep working.

Every sign-in, registration, token change, allowlist change and accepted risk is recorded in the **Audit log**.

From a shell, `php artisan service:token billing-api production` does the same (on the VPS: `docker exec patchwatch-web php artisan service:token …`).

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

Ready-made reporters: [Node](templates/node/README.md) · [Go](templates/go/README.md)

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

### 4. Or send a CycloneDX SBOM from CI

Instead of listing npm and Go packages in the heartbeat, CI can post a [CycloneDX](https://cyclonedx.org) JSON SBOM. Components with `pkg:npm/…` or `pkg:golang/…` package URLs replace the service's npm and Go dependencies; others are skipped. Runtime rows from heartbeats and image scans are left alone.

```bash
syft dir:. -o cyclonedx-json > sbom.json   # or cdxgen, cyclonedx-gomod, cyclonedx-npm

curl -X POST https://patchwatch.example.com/api/v1/telemetry/sbom \
  -H "Authorization: Bearer $PATCHWATCH_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  --data-binary @sbom.json
```

### API behaviour

| Status | When                                                                                                                                         |
| ------ | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `204`  | Report accepted                                                                                                                              |
| `401`  | Missing or invalid token                                                                                                                     |
| `403`  | The token doesn't belong to a service (for example, a dashboard user's session), or the request came from outside the service's IP allowlist |
| `422`  | Invalid payload. The body lists the field errors.                                                                                            |
| `429`  | More than 60 requests per minute for this token. Respect `Retry-After`.                                                                      |

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

`compose.yaml` still describes a self-contained stack, with its own PostgreSQL and a `backup` container that writes a daily `pg_dump` to `./backups` (kept 14 days), for running the production image somewhere else.

**Backups:** on the VPS, dump the database daily from cron and copy the file off the box (the dumps hold every service's dependency list):

```cron
15 3 * * * docker exec goc-postgres pg_dump -U security_dash -Fc security_dash > /opt/patchwork/backups/patchwatch-$(date +\%F).dump && find /opt/patchwork/backups -name '*.dump' -mtime +14 -delete
```

Restore with `pg_restore --clean -d security_dash <file>`.

**Security headers:** every response carries a nonce-based Content-Security-Policy, `X-Frame-Options: DENY`, `nosniff`, a strict referrer policy, and HSTS when served over HTTPS. Set `SESSION_ENCRYPT=true` and `SESSION_SECURE_COOKIE=true` in production (both are in `.env.production.example`).

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

### AI triage (TypeSafe Jev, optional)

With `TYPESAFE_API_KEY` set, every evaluation asks [Jev](https://docs.typesafe.ai) two things about each advisory that still counts: how soon to fix it (routine, soon, urgent, emergency) and the best next step (upgrade, check if affected, likely safe to accept, remove or replace). The answer shows as a badge on the advisory, marked "(unsure)" when Jev's confidence is below 50%. It is a suggestion only and never changes a grade.

What is sent: service name and environment, and per advisory the package, ecosystem, versions, OSV id, severity, summary, KEV and EPSS. Answers are cached for 7 days per identical input, so hourly re-evaluations don't re-bill. An outage only hides the badges. `TYPESAFE_MODEL` defaults to `jev-latest`.

### Upgrade pull requests (optional)

With `GITHUB_PR_TOKEN` set, link a service to its repo (`owner/name`) under **Fix the advisories** on the service page. Each vulnerable npm or Go dependency then gets an **Open PR** button that branches off the default branch (`patchwatch/<package>-<version>`), bumps the version in the root `package.json` (keeping `^`/`~`) or `go.mod`, and opens a PR listing the advisories it fixes.

Use a [fine-grained token](https://github.com/settings/personal-access-tokens/new) limited to the service repos, with **Contents: Read and write** and **Pull requests: Read and write**. It is separate from the sign-in OAuth app.

Only the manifest is changed. The PR body says to run `npm install` or `go mod tidy` on the branch so the lockfile catches up. Transitive dependencies (not listed in the manifest) can't be bumped this way.

## Tech stack

- **Backend:** Laravel 13, Sanctum (service tokens), Socialite (GitHub sign-in), queued jobs and the scheduler
- **Frontend:** Inertia v3, Vue 3, TypeScript, Tailwind CSS v4, shadcn-vue (reka-ui), Wayfinder for typed routes
- **Quality:** Pest, PHPStan/Larastan level 7, Pint, vite-plus lint and format, vue-tsc
