# REVIEW.md

How to review changes in this repository.

## The angle

Don't ask what's wrong with the code. Ask **what can break because of it.** For every change, work out which input, state, timing or upstream response makes it fail, and what the user sees when it does. If you can't name a concrete failure scenario, leave the comment out.

Report in this order:

1. **Breaks:** wrong grade, data loss, crash, security hole, failed build or deploy.
2. **Will break later:** a breaking edge case, a race, an assumption that only holds on SQLite or with today's data.
3. **Cleanups:** only when they remove real complexity or duplication. Leave formatting to Pint and `vp check`, and don't point it out.

## What breaks here

**Grading**
- An upstream failure must not be read as "all clear". `Osv` throws on purpose. Any new client feeding Red/Yellow/Green has to fail loudly too. Only enrichment (KEV, EPSS, Jev, PR state, news) may swallow errors.
- Advisories are rebuilt on each evaluation. Anything stored on an advisory row is lost unless it's carried over by `osv_id`, the way `first_seen_at` is.
- A version change resets the dependency to `Unknown` and clears its `pull_request_url`. New per-dependency state needs the same reset.
- `ServiceStatus::worst()` ignores `Unknown`. Check that a new status or ecosystem doesn't quietly turn a service Green.
- Risk acceptances have to apply in every place that decides Red, image rows included.
- Version comparisons: check `v`/`go` prefixes (`Releases::normalize()`), pre-releases, and runtimes compared outside their release line.

**Jobs and timing**
- `EvaluateService` is unique per service and also runs hourly. Look for double sends (a `ServiceTurnedRed` mail, a duplicate PR) and for writes that assume no other worker is running.
- Nothing is graded without the queue worker and the scheduler running. A feature that quietly needs a third process will break in prod.

**Security and auth**
- On `routes/api.php`, `$request->user()` is a `Service`. Code that treats it as a `User`, or a token issued to anything other than a `Service`, is a bug.
- New API routes need `auth:sanctum` and `service.ip`.
- Service-reported strings (names, versions, purls) are untrusted. Check where they end up: mail, webhook text, links, SVG badges, shell, or GitHub API paths.
- Inline `<script>` in Blade without `nonce="{{ Vite::cspNonce() }}"` is blocked in prod, even though it works with the dev server running.
- Integration secrets have to be read with `Setting::config()`. Using `config()` ignores the value saved in Settings.
- New sensitive actions need `AuditEvent::record()` and a label in `pages/audit/Index.vue`.

**Database**
- Prod runs on PostgreSQL. Check raw SQL, JSON columns, case-sensitive `LIKE`, and type casts that SQLite tolerates.
- Migrations run against live data. A non-null column without a default, or a destructive change, can fail the `migrate` container.

**Frontend**
- Importing Wayfinder routes for disabled Fortify or Passkeys features (`@/routes/register`, `password`, `two-factor`, …) fails the build.
- A status shown by colour alone is a bug. Use `lib/status.ts` or the ASCII `GLYPH`s.
- Canvas scenes that bypass `useAsciiCanvas` skip off-screen pausing and reduced motion.
- shadcn-vue CLI additions: check that `cn` is imported from `@/lib/utils` and not from an npm package.

## Tests

Every behaviour change that can break needs one test that fails when it breaks. Upstream calls are faked with `Http::fake()` and guarded with `Http::preventStrayRequests()`; a real network call in a test is a bug. `composer ci:check` must pass.

## Comments

Keep them few. A main function or class gets a short line on its purpose, and the non-obvious spots get a line on *why*: a deliberate trade-off, an upstream quirk, a security reason. Flag comments that just restate the code. Deliberate limits keep the `// ponytail:` form, naming the limit and how to upgrade.
