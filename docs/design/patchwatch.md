# Patchwatch design

Status: living document. The architecture is a hypothesis: each decision lists what would make us change it.

## 1. Requirements

**Functional**

- Take dependency inventories from services (heartbeat, SBOM, image scan) and from watched GitHub repositories (push → scan).
- Grade every dependency and service Red / Yellow / Green / Unknown from OSV, the release registries, and endoflife.date.
- Alert (mail plus webhook) when a service turns Red or a malicious package appears.
- Review PRs (`patchwatch-bot` check and comment), and open upgrade PRs when a service opts in.
- Show security news, with what touches the fleet pinned first.

**Non-functional, in priority order**

1. **Never a false Green.** An upstream outage, a dropped page or a timeout must fail loudly, never read as "no advisories".
2. **Alerts are never lost.** A Red transition sends exactly one alert, even if later steps crash.
3. **Freshness.** Time from an advisory being published to a Red alert. Today it's at most 60 minutes plus queue drain.
4. Speed of a single evaluation, so it stays well inside the job timeout (85 s).

**Out of scope:** multi-tenant SaaS, multi-region, and real-time (sub-minute) alerting. It's an in-house tool.

## 2. Scale (assumed; replace with real numbers)

| Quantity                  | Assumption                                         | Consequence                                                             |
| ------------------------- | -------------------------------------------------- | ----------------------------------------------------------------------- |
| Services                  | 30                                                 | 30 evaluation jobs an hour                                              |
| Dependencies per service  | ~1,500 (npm lockfiles)                             | 45k dependency rows, ~6k unique packages                                |
| OSV calls per evaluation  | 2 querybatch + new vuln records (cached 1 day)     | Cheap once warm                                                         |
| Registry "latest" lookups | 1,500 per service, pooled 25 at a time, cached 6 h | Cold: ~60 rounds × ~0.3 s ≈ 18 s. Warm: ~0                              |
| Worker time per hour      | 30 × 10–40 s                                       | 5–20 min. One worker copes, but the :00 burst means up to 20 min of lag |

Postgres and the database queue are nowhere near their limits. **The binding constraints are latency per job and freshness, not throughput.**

## 3. Current architecture

```
services ──heartbeat/SBOM/image──▶ API (Sanctum + IP allowlist) ──▶ EvaluateService (queue)
GitHub ──push/PR webhooks──────────▶ WebhookController ──▶ ScanRepository / ReviewPullRequest
scheduler ──hourly──▶ EvaluateService × N,  FetchSecurityNews

EvaluateService (critical, must finish): OSV + registries + EOL → grade → DB transaction → alert
        └──dispatch──▶ EnrichService (best effort): KEV/EPSS, supply chain, upgrade plan, Jev triage, PRs
FetchSecurityNews: 6 RSS feeds → news_articles (+ CVE/GHSA ids) → Jev ranking
News page: articles × fleet advisories (osv_id/aliases) × KEV cache
```

Single Postgres, database queue, one worker, one scheduler, all in Docker.

## 4. Decisions

| Decision                                                                                     | Solves                                                                                           | Worsens                                                                             | Change when                                                                        |
| -------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| Upstream clients throw on failure. Only enrichment swallows errors.                          | A false Green during outages                                                                     | Evaluations fail and retry during outages                                           | Never, for grading inputs                                                          |
| Prefetch with `Http::pool`, 25 wide, caching only real answers                               | Cold-cache timeouts on big manifests                                                             | Burst load on registries                                                            | Registries rate-limit us. Then lower the width or add a token bucket               |
| **Grading and enrichment split into two jobs** (this change)                                 | A slow GitHub/Jev/deps.dev call can no longer time out a grade or delay its alert                | Badges, triage and PRs land a few seconds after the grade. One more job per service | Enrichment needs data from the same transaction. It doesn't today                  |
| Alert sent from the grading job, after commit, before hand-off                               | An alert lost to a later crash (retries see Red and stay silent)                                 | Nothing found                                                                       | Moving to a transactional outbox if alerts ever must survive a crash _during_ send |
| Database queue (not Redis)                                                                   | One less moving part. Jobs are durable in Postgres                                               | Polling latency (~1 s), and `retry_after` couples to timeouts                       | Over ~100 jobs/s, or when queue priorities need sub-second pickup                  |
| Hourly full sweep                                                                            | Simple, and it catches everything eventually                                                     | Up to 60 min freshness. 30 jobs burst at :00                                        | **Next step**: delta-driven re-evaluation (§6)                                     |
| News matched to the fleet by CVE/GHSA id (deterministic), with Jev ranking only for the rest | A security signal you can audit ("billing carries CVE-…"), and no LLM guesswork on what's urgent | Articles that name a product but no id don't match                                  | A curated product→package map pays off                                             |

## 5. Failure modes

| Failure                           | Behaviour                                                                                                                          |
| --------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| OSV or a registry down            | Grading throws and retries 3× with 60 s backoff. The service keeps its last grade. Nothing turns Green.                            |
| GitHub, Jev, KEV or EPSS down     | Only `EnrichService` degrades: badges, triage and PR state go stale until the next hour. Grades and alerts are unaffected.         |
| Job exceeds 85 s                  | Grading: retried (alert-safe, see §4). Enrichment: dropped (`tries = 1`) and re-queued by the next evaluation.                     |
| Queue worker or scheduler stopped | Nothing is graded. The dashboard's failed-jobs and "last evaluated" banners are the signal. **Gap:** nothing pages anyone. See §7. |
| A feed is down or malformed       | That feed is skipped and the others still land.                                                                                    |
| Postgres down                     | Everything stops. Single point of failure, accepted for an in-house tool (backups plus restore runbook).                           |

## 6. Roadmap (ordered by security value per unit of effort)

1. **Delta-driven re-evaluation (freshness 60 min → ~10 min).** Every 10 min, read the newest rows of OSV's per-ecosystem `modified_id.csv` (npm, Go) since a stored high-water mark, with a small overlap. Fetch those records, take the affected package names, and dispatch `EvaluateService` only for services carrying them. Keep a full sweep every 6 h as the safety net (the CSV has no deletion events). MAL- (malware) records land within minutes this way, and that's where speed matters most.
2. **Dead-man's switch.** The scheduler pings a heartbeat URL each minute, or the dashboard shows a Red banner when the newest `evaluated_at` is more than 2 h old. Today a stopped worker is silent.
3. **Queue priorities.** `EvaluateService` on a `grading` queue, worker run with `--queue=grading,default`, so news, enrichment and PR reviews never sit ahead of a grade. This needs the compose `queue:work` command changed in the same deploy, or graded jobs sit unprocessed.
4. **deps.dev for project health.** Licences and the OpenSSF Scorecard of the source repo, as Yellow-only signals in `EnrichService` (inform, never grade).
5. **News:** product/package mention matching for fleet packages with distinctive names (`lodash`, `axios`; not `next`, `react`), shown as "mentions a package you run" (weaker than an id match).

## 7. Score (failure-modes quick diagnostic)

| Dimension                 | Score | Note                                                       |
| ------------------------- | ----- | ---------------------------------------------------------- |
| Requirements and scope    | 4/5   | Priorities explicit. Scale numbers are assumptions         |
| Correctness under failure | 4/5   | Fails closed everywhere grading reads                      |
| Freshness                 | 2/5   | **Weakest.** Hourly polling. Fix: §6.1                     |
| Operability               | 3/5   | Failed jobs visible, but a dead worker pages nobody (§6.2) |
| Simplicity                | 5/5   | One DB, one queue, no extra infrastructure                 |

**Current bottleneck:** freshness (hourly sweep), not compute. **At 10× (300 services, 450k dependency rows):** the hourly burst stops fitting in one worker (≈3 h of work per hour). Delta-driven evaluation (§6.1) removes most of that work, and then a second worker plus queue priorities. The database queue and Postgres still hold.

## Sources

- OSV data export and `modified_id.csv` incremental sync: <https://google.github.io/osv.dev/data>, <https://oneuptime.com/blog/post/2026-07-23-mirror-update-osv-database/view>
- deps.dev API v3: <https://docs.deps.dev/api/v3/>
- CISA ended RSS for KEV updates (we read the KEV JSON feed): <https://www.cisa.gov/news-events/alerts/2025/05/12/update-how-cisa-shares-cyber-related-alerts-and-notifications>. The alerts feed `https://www.cisa.gov/cybersecurity-advisories/alerts.xml` was checked live on 2026-11-19.
