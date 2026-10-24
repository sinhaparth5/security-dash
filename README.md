<div align="center">

<img src="public/favicon.svg" alt="" width="72" height="72">

# Patchwatch

**Know which of your deployed services need a security patch, and why.**

[![CI](https://github.com/sinhaparth5/security-dash/actions/workflows/ci.yml/badge.svg)](https://github.com/sinhaparth5/security-dash/actions/workflows/ci.yml)
![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![Vue 3](https://img.shields.io/badge/Vue-3-4FC08D?logo=vuedotjs&logoColor=white)
![PHPStan level 7](https://img.shields.io/badge/PHPStan-level%207-2563eb)
![Patchwatch status](https://security-dash.astrareconslabs.com/badge/V6JI3sy18ShUMURouAsxsrvLgi82g2L7Nr133gsp.svg)

[Quick start](#quick-start) · [Reporting telemetry](#reporting-telemetry) · [Integrations](#integrations) · [Configuration](#configuration) · [Deployment](#deployment)

</div>

---

Patchwatch is an intranet dashboard for the services you run. Each service reports the runtime and dependency versions it is actually running. Patchwatch checks them against upstream release feeds and the [OSV](https://osv.dev) vulnerability database, then grades every service:

| Status                        | Meaning                                                                                     |
| ----------------------------- | ------------------------------------------------------------------------------------------- |
| **Patch required** (red)      | A known advisory (CVE/GHSA) affects the running version, or the runtime is past end of life |
| **Update available** (yellow) | A newer release exists, but no advisory affects the running version                         |
| **Up to date** (green)        | Latest release, no known advisory                                                           |
| **Not evaluated**             | Untracked ecosystem, or no upstream data yet                                                |

A service takes the grade of its worst dependency.

## Contents

- [Features](#features)
- [How it works](#how-it-works)
- [Quick start](#quick-start)
- [Reporting telemetry](#reporting-telemetry)
- [Using the dashboard](#using-the-dashboard)
- [Integrations](#integrations)
- [Configuration](#configuration)
- [Deployment](#deployment)
- [Security](#security)
- [Development](#development)
- [Tech stack](#tech-stack)

## Features

| Area           | What you get                                                                                                                                 |
| -------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| Grading        | Go modules, npm packages, the Go and Node runtimes, and container images, graded hourly and on every report                                  |
| Prioritising   | CISA KEV "Exploited in the wild" badges, EPSS scores, a fix-by date per advisory, and optional AI triage                                     |
| Fixing         | Copyable upgrade commands, one-click upgrade pull requests, or PRs opened automatically                                                      |
| Supply chain   | `MAL-` malware advisories raised above everything else, and warnings for npm names one typo away from a popular package                      |
| Fleet view     | A **Packages** page showing which services run each package, and on which versions                                                           |
| News           | Security news from five public feeds, refreshed hourly, with Jev ranking what matters for your stack                                         |
| Alerts         | Email when a service turns red, the same alert in Slack or Discord, and a weekly digest                                                      |
| Sharing        | A README status badge per service                                                                                                            |
| Accountability | Risk acceptances with a reason and re-check date, and an audit log of every sign-in, token, allowlist, risk, repository, PR and badge change |

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
                                ├─ TypeSafe Jev (optional) → triage suggestions
                                └─ GitHub (optional)   → upgrade PRs and their state
                                                           ▼
                                  database ──▶ dashboard (polls every minute)
                                           ├─▶ email, Slack / Discord alerts
                                           └─▶ README badge (/badge/<token>.svg)

FetchSecurityNews job (hourly) ── RSS feeds → news articles ── TypeSafe Jev (optional) → importance
```

### What gets checked

| Source           | Checked against                                             | Notes                                                                                     |
| ---------------- | ----------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| Go modules       | OSV, `proxy.golang.org`                                     |                                                                                           |
| npm packages     | OSV, the npm registry                                       |                                                                                           |
| Go runtime       | `go.dev` releases, [endoflife.date](https://endoflife.date) | Compared within its minor line                                                            |
| Node runtime     | The `nodejs.org` release index, endoflife.date              | Compared within its major line. A newer _security_ release in the same line counts as red |
| Container images | Your latest Trivy scan                                      | Keep the grade the scan gave them                                                         |

Comparing runtimes within their release line means a healthy LTS install isn't flagged just because a newer major exists. A Go or Node runtime past end of life is red, and one within 180 days of it is flagged on the service page.

### When upstreams fail

Upstream responses are cached. If an upstream is unavailable, the evaluation fails and retries, and the last grade stays in place, so an outage is never read as "no advisories". KEV, EPSS, AI triage, PR status, chat alerts and news feeds are the exception: they only add information, so an outage there hides it until the next evaluation and never blocks grading.

If no evaluation has finished for two hours, or queued jobs have failed, the dashboard shows a warning. Check the queue worker and the scheduler.

## Quick start

You need:

- PHP 8.3+ (CI uses 8.5) with `pdo_sqlite` for development and `pdo_pgsql` for production
- Composer 2
- Node.js 22+
- PostgreSQL in production. The app refuses to boot in production on anything else.
- A [GitHub OAuth app](https://github.com/settings/developers) for sign-in, with the callback URL `${APP_URL}/auth/github/callback`

```bash
composer setup     # install deps, create .env, generate the app key, migrate, build assets
composer dev       # app server, queue worker and Vite together
```

Then fill in `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET` and `GITHUB_ALLOWED_LOGINS` (comma-separated GitHub usernames) in `.env`. To get demo services on the dashboard, run `php artisan db:seed`.

## Reporting telemetry

### 1. Register the service

On the dashboard, click **Register service** and enter a name and environment. Patchwatch creates the service and shows its token (once), the heartbeat and image-scan URLs, and copyable heartbeat and Trivy examples. Store the token as a secret in the service's environment. Registering an existing name and environment issues an extra token and leaves the old ones working.

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

- `ecosystem` must be one of `runtime`, `go` or `npm`. The runtimes that get graded are `go` and `node`.
- `v` and `go` version prefixes are fine.
- A single heartbeat accepts up to 5,000 dependencies.
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

Instead of listing npm and Go packages in the heartbeat, CI can post a [CycloneDX](https://cyclonedx.org) JSON SBOM. Components with `pkg:npm/…` or `pkg:golang/…` package URLs replace the service's npm and Go dependencies, and the rest are skipped. Runtime rows from heartbeats and image scans are left alone.

```bash
syft dir:. -o cyclonedx-json > sbom.json   # or cdxgen, cyclonedx-gomod, cyclonedx-npm

curl -X POST https://patchwatch.example.com/api/v1/telemetry/sbom \
  -H "Authorization: Bearer $PATCHWATCH_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  --data-binary @sbom.json
```

### API responses

| Status | When                                                                                                                                         |
| ------ | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `204`  | Report accepted                                                                                                                              |
| `401`  | Missing or invalid token                                                                                                                     |
| `403`  | The token doesn't belong to a service (for example, a dashboard user's session), or the request came from outside the service's IP allowlist |
| `422`  | Invalid payload. The body lists the field errors.                                                                                            |
| `429`  | More than 60 requests per minute for this token. Respect `Retry-After`.                                                                      |

## Using the dashboard

### Service page

Each row on the dashboard opens the service's page, which groups its dependencies by status. Vulnerable dependencies are shown as cards with every advisory, its severity, KEV and EPSS badges, and the version that fixes it. Package names link to npm or pkg.go.dev and advisory IDs link to osv.dev. On outdated rows, the latest version links to the registry's version history, so you can see what changed before upgrading.

The **Fix the advisories** box lists the `npm install` and `go get` commands that move every vulnerable package to the lowest version clearing all of its advisories.

### Fix-by dates

Every advisory that still counts gets a fix-by date, measured from when Patchwatch first saw it. The date survives hourly re-grading and new image scans.

| Advisory                       | Fix within |
| ------------------------------ | ---------- |
| Malware, or listed in CISA KEV | 1 day      |
| Critical                       | 2 days     |
| High                           | 7 days     |
| Moderate, medium or unrated    | 30 days    |
| Low                            | 90 days    |

The dashboard shows how many advisories each service has overdue.

### Accepting a risk

**Accept risk** on an advisory records why it doesn't apply, with an optional re-check date. While the acceptance is active, the advisory no longer turns the dependency red. Once it expires, the advisory counts again.

### Malware and lookalike packages

A package OSV reports as malware (`MAL-` advisories) is flagged on the dashboard and the service page, and triggers an alert the first time it appears, even if the service was already red. npm names one typo or one swapped letter pair away from a popular package (`expres`, `lodahs`) get a **Looks like …** warning. The warning is a hint and never changes a grade.

### News

**News** in the sidebar collects security news from [The Hacker News](https://thehackernews.com), [BleepingComputer](https://www.bleepingcomputer.com), [SecurityWeek](https://www.securityweek.com), [Krebs on Security](https://krebsonsecurity.com) and [Dark Reading](https://www.darkreading.com). The scheduler reads their public RSS feeds every hour, so no API key is needed. Each article shows its image (or a placeholder when the feed has none), title, a short summary, the source and when it was published, and links to the full story. Articles from the last 14 days are shown, and anything older than 30 days is deleted.

With `TYPESAFE_API_KEY` set, Jev scores each new article from 0 (background) to 3 (act now) for the software your services run. Patchwatch sends it the article's source, title and summary, plus the fleet's ecosystems, runtimes and 100 most shared packages. The page then groups articles:

| Section   | Articles                                                          |
| --------- | ----------------------------------------------------------------- |
| Featured  | The three highest scored articles with a score of 1.5 or more     |
| Important | The remaining articles with a score of 1.5 or more, highest first |
| Latest    | Everything else, newest first                                     |

Without a key, every article is listed under **All news**, newest first. A feed that is down is skipped until the next hour, and articles Jev couldn't score are asked about again on the next run.

### Packages

**Packages** in the sidebar lists every package across the fleet, most widely used first, grouped by version with the services running each one. Search it when a new advisory lands to see who is affected.

### Tokens and IP allowlists

To rotate a token, open **Tokens** in the sidebar, select the service, click **Issue new token** (optionally with an expiry), deploy the new token, then click **Revoke** on the old one. The same page shows the heartbeat and image-scan URLs. Each token shows when it was created, last used and expires, and tokens unused for 30 days are flagged, so you can tell which one is live. A revoked or expired token gets `401` on its next request, and expired tokens are pruned daily.

The same page has an **IP allowlist** per service: the IPs or CIDR ranges it reports from. A token used from anywhere else gets `403`. The client IP comes from `X-Forwarded-For`, so the reverse proxy must set it.

### Audit log

The **Audit log** records every sign-in, registration, token change, allowlist change, accepted risk, repository link, upgrade PR (marked when opened automatically) and badge rotation, with who did it and from which IP.

## Integrations

All integrations are optional. Each one stays off until its setting is filled in.

### Upgrade pull requests

With `GITHUB_PR_TOKEN` set, link a service to its repository (`owner/name`) under **Repository and badge** on the service page. Each vulnerable npm or Go dependency then gets an **Open PR** button.

The PR branches off the default branch as `patchwatch/<package>-<version>` and changes one manifest:

- A direct dependency is bumped in the first `package.json` (keeping `^`/`~`) or `go.mod` that lists it. The repository root is checked first, then subfolders, skipping `node_modules` and `vendor`.
- A transitive npm dependency is pinned with an override in the shallowest `package.json`, using the field its package manager reads: `pnpm.overrides`, `resolutions` (yarn) or `overrides` (npm, bun). The package manager is picked from the lockfile next to the manifest.

The PR lists the advisories it fixes and the install command (`pnpm`/`yarn`/`bun`/`npm install` or `go mod tidy`) to run on the branch so the lockfile catches up.

Each evaluation checks the PRs Patchwatch opened, so the service page shows them as open, merged (waiting for deploy) or closed. Tick **Open upgrade PRs automatically** to have evaluations open the PR themselves when a dependency turns red and a fix is known. A failed attempt is retried once a day.

Use a [fine-grained token](https://github.com/settings/personal-access-tokens/new) limited to the service repositories, with **Contents: Read and write** and **Pull requests: Read and write**. It is separate from the sign-in OAuth app.

### AI triage (TypeSafe Jev)

With `TYPESAFE_API_KEY` set, every evaluation asks [Jev](https://docs.typesafe.ai) two things about each advisory that still counts: how soon to fix it (routine, soon, urgent, emergency) and the best next step (upgrade, check if affected, likely safe to accept, remove or replace). The answer appears as a badge on the advisory, marked "(unsure)" when Jev's confidence is below 50%. It is a suggestion only and never changes a grade.

Patchwatch sends the service name and environment, and for each advisory the package, ecosystem, versions, OSV ID, severity, summary, KEV and EPSS. Answers are cached for 7 days per identical input, so hourly re-evaluations don't re-bill. `TYPESAFE_MODEL` defaults to `jev-latest`.

### Email alerts

When a service first turns red, or a malware package first shows up, every dashboard user is emailed the affected dependencies and the versions that fix them. Every user also gets a weekly digest on Monday at 08:00 (app timezone) with services that turned red or recovered, overdue advisories per service, and open upgrade PRs.

Mail goes to each user's address from their profile settings and is queued, so the queue container must be running. The digest also needs the scheduler. Production sends through [Cloudflare Email Service](https://developers.cloudflare.com/email-service/) over SMTP, using Laravel's built-in `smtp` mailer:

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

Cloudflare allows 50 recipients and 5 MiB per message. The daily quota starts conservative and grows with your sending reputation.

### Slack and Discord alerts

Set `ALERT_WEBHOOK_URL` to a Slack [incoming webhook](https://api.slack.com/messaging/webhooks) or a Discord webhook (channel settings → Integrations → Webhooks) to post the same alerts as the red email to a channel.

Package names come from the services themselves, so Patchwatch escapes Slack formatting and disables Discord mentions. A dependency name can't ping `@channel` or slip in a link. A failed post is logged and never blocks grading.

### README status badge

**Create badge** under **Repository and badge** on the service page gives a public SVG showing the service's status, and the Markdown to paste into a README:

```markdown
![Patchwatch status](https://patchwatch.example.com/badge/<token>.svg)
```

The URL is the only secret: anyone who has it can see the service's status and nothing else. **Rotate badge URL** replaces it, and the old URL returns `404`. The image is cached for 5 minutes, so a README shows a new status shortly after it changes.

## Configuration

Settings live in `.env` locally and in the server's env file in production (see [Deployment](#deployment)).

| Variable                                   | Required   | Purpose                                                                             |
| ------------------------------------------ | ---------- | ----------------------------------------------------------------------------------- |
| `APP_KEY`, `APP_URL`                       | Yes        | Laravel app key and public URL                                                      |
| `DB_*`                                     | Yes        | SQLite locally, PostgreSQL in production                                            |
| `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET` | Yes        | The GitHub OAuth app used for sign-in                                               |
| `GITHUB_ALLOWED_LOGINS`                    | Yes        | Comma-separated GitHub usernames allowed to sign in                                 |
| `MAIL_*`                                   | Production | Alert and digest email ([setup](#email-alerts)). Locally, mail goes to the log.     |
| `SESSION_ENCRYPT`, `SESSION_SECURE_COOKIE` | Production | Set both to `true`                                                                  |
| `GITHUB_PR_TOKEN`                          | No         | [Upgrade pull requests](#upgrade-pull-requests), their status, and automatic PRs    |
| `TYPESAFE_API_KEY`, `TYPESAFE_MODEL`       | No         | [AI triage](#ai-triage-typesafe-jev) badges on advisories and [news](#news) ranking |
| `ALERT_WEBHOOK_URL`                        | No         | [Slack or Discord alerts](#slack-and-discord-alerts) alongside the red email        |
| `SANCTUM_TOKEN_PREFIX`                     | No         | Service token prefix, `pw_` by default (see [Security](#security))                  |

The optional integrations (`GITHUB_PR_TOKEN`, `TYPESAFE_API_KEY`, `TYPESAFE_MODEL` and `ALERT_WEBHOOK_URL`) can also be set under **Settings → Integrations**. Values saved there are encrypted with `APP_KEY` and win over the env file. The page only shows the last four characters of a secret, and the audit log records which fields changed but never their values. The alerts panel has a **Send test** button.

## Deployment

Every push to `master` runs `.github/workflows/ci.yml`:

1. **check:** `composer ci:check`, the same checks as local development.
2. **image:** builds the [FrankenPHP](https://frankenphp.dev) image and pushes it to `ghcr.io/<owner>/<repo>` as `:latest` and `:<short sha>`.
3. **deploy:** connects to the VPS over SSH, pulls that exact tag, runs `php artisan migrate --force` in a one-off container, then replaces three containers built from the same image:

| Container              | Role                                                                                                                    |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `patchwatch-web`       | The app on `127.0.0.1:18092`. Put a TLS-terminating reverse proxy in front of it, and make it send `X-Forwarded-Proto`. |
| `patchwatch-queue`     | `queue:work`: evaluations, alert emails and the weekly digest                                                           |
| `patchwatch-scheduler` | `schedule:work`: the hourly re-evaluation and news fetch, and the Monday digest                                         |

If the migration fails, the deploy stops and the old containers keep running. To roll back, re-run the deploy for an older commit, or `docker run` its `:<short sha>` tag.

### Server setup (one time)

1. Create the env file `/opt/patchwork/patchwatch.env`, owned by the deploy user with mode `600`. Start from `.env.production.example`. Docker reads it literally, so use no quotes and no `${VAR}` references. Generate `APP_KEY` with `php artisan key:generate --show`.
2. Use PostgreSQL in the `goc-postgres` container. The app containers join its Docker network, so `DB_HOST=goc-postgres`.
3. Add the deploy user to the `docker` group.
4. Add the repository secrets `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `GHCR_USERNAME`, and `GHCR_TOKEN` (a token with `read:packages`).

`compose.yaml` describes a self-contained stack, with its own PostgreSQL and a `backup` container that writes a daily `pg_dump` to `./backups` (kept 14 days), for running the production image somewhere else.

### Backups

The dumps hold every service's dependency list. On the VPS, dump the database daily from cron and copy the file off the box:

```cron
15 3 * * * docker exec goc-postgres pg_dump -U security_dash -Fc security_dash > /opt/patchwork/backups/patchwatch-$(date +\%F).dump && find /opt/patchwork/backups -name '*.dump' -mtime +14 -delete
```

Restore with `pg_restore --clean -d security_dash <file>`.

## Security

- Sign-in is GitHub OAuth only, limited to `GITHUB_ALLOWED_LOGINS`.
- Service tokens look like `42|pw_…`, can expire, and work only from the service's IP allowlist when one is set. To have GitHub block a leaked token, add a [custom secret scanning pattern](https://docs.github.com/en/code-security/how-tos/secure-your-secrets/customize-leak-detection/define-custom-patterns) `[0-9]+\|pw_[A-Za-z0-9]{48}` and enable it in push protection. Tokens issued before the prefix existed keep working.
- Every response carries a nonce-based Content-Security-Policy, `X-Frame-Options: DENY`, `nosniff`, a strict referrer policy, and HSTS when served over HTTPS.
- Secrets such as `GITHUB_PR_TOKEN` and the mail token belong in the server's env file, never in the image or the repository.

## Development

```bash
composer test                                    # Pint, PHPStan (level 7), Pest
composer ci:check                                # everything CI runs, frontend lint and types included
php artisan test --filter=HeartbeatTest          # a single test
composer lint                                    # fix PHP formatting
npm run check:fix                                # fix frontend formatting and lint
```

## Tech stack

| Layer    | Tools                                                                                    |
| -------- | ---------------------------------------------------------------------------------------- |
| Backend  | Laravel 13, Sanctum (service tokens), Socialite (GitHub sign-in), queued jobs, scheduler |
| Frontend | Inertia v3, Vue 3, TypeScript, Tailwind CSS v4, shadcn-vue (reka-ui), Wayfinder routes   |
| Quality  | Pest, PHPStan/Larastan level 7, Pint, vite-plus lint and format, vue-tsc                 |
| Runtime  | FrankenPHP, PostgreSQL, Docker                                                           |
