# Queue, Redis, Session, Cache, and Distributed-Lock Architecture

**Date:** 2026-07-13
**Branch:** `feature/ecs-readiness-foundation`

## Current state (confirmed by direct config inspection, see [ec2-dependency-audit.md](ec2-dependency-audit.md) §4-5)

All four concerns — queue, cache, session, maintenance-mode — are already fully env-driven in `config/queue.php`, `config/cache.php`, `config/session.php`, and `config/app.php`. No application code change was needed for env-driven configuration; this branch's changes are limited to:

1. Adding a dedicated `queue` Redis connection/database-index entry to `config/database.php` (isolating queue traffic from the `default` connection used for locks/rate-limiting and the `cache` connection used for the cache store), and
2. Documenting the exact environment variable set staging/production ECS tasks must set (this document + [env.ecs.example](env.ecs.example)), since the *defaults* baked into `.env.example` remain correctly oriented at local development (sqlite/`database` driver — zero external dependencies to start developing) and must not be changed.

## No ECS task may rely on file sessions, local cache, local queue, or local lock files

This is enforced by configuration, not code: every driver (`CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION`) defaults to `database` in `.env.example` — **not** `file` — with the single exception of `APP_MAINTENANCE_DRIVER`, which defaults to `file` and **must** be overridden to `cache` in every ECS environment (a `file`-driven maintenance flag is task-local; one task going into maintenance mode would not be visible to the others). ECS task definitions set:

```
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
APP_MAINTENANCE_DRIVER=cache
REDIS_QUEUE_CONNECTION=queue
```

`database`-backed cache/session/queue would also technically work across tasks (RDS is shared, unlike local disk) but adds avoidable load to the primary database for something Redis is purpose-built for — Redis is the target, `database` is not a "blocker" state, just not the destination configuration.

## Distributed locks, rate limiting, scheduler overlap protection

None of these need new code. Laravel's `Cache::lock()`, the `RateLimiter` facade, and `Schedule::withoutOverlapping()` all use whichever store `CACHE_STORE` resolves to. Once `CACHE_STORE=redis`, all three are automatically backed by Redis's atomic `SET NX`-based locks — correct and safe across every ECS task sharing the same ElastiCache endpoint. The `redis` cache store's `lock_connection` (`config/cache.php:84`, defaults to `default`) intentionally uses the `default` Redis connection rather than `cache` or the new `queue` connection, so lock traffic is never contending with cache eviction or queue `BLPOP` polling on the same logical database.

## Queue design

### Current jobs (from [ec2-dependency-audit.md](ec2-dependency-audit.md) §4)

`app/Jobs/` has exactly 4 classes today (`DispatchNotificationJob`, `RunHealthChecksJob`, `ScanDocumentJob`, `WebhookDispatchJob`), **none of which declare `->onQueue()` or a `$queue` property** — all run on the implicit `default` queue. This mission does not add queue names to jobs that don't declare one — that would be an application-code behavior change (which queue a job runs on can affect prioritization/isolation guarantees the owning team should decide deliberately), not an infrastructure change. What this mission *does* do is prepare the queue **topology** so that when jobs are assigned to named queues, the worker infrastructure to consume them already exists.

### Prepared named-queue topology

Following the isolation the codebase's own domain boundaries already imply (trust/payment services are kept structurally separate from everything else — see `TenantSafeTrustPolicyService`, `TrustConcurrencyLockService`, etc.), the worker task definitions in [infrastructure-architecture.md](infrastructure-architecture.md) are prepared for these queue names, without inventing speculative ones beyond what the codebase's domain split already justifies:

| Queue | Purpose | ECS worker service | Priority/isolation rationale |
|---|---|---|---|
| `default` | Anything not yet assigned a specific queue (all 4 current jobs, today) | general worker | Catch-all; safe to share capacity |
| `documents` | Document generation/scanning work (`ScanDocumentJob` and future document-processing jobs) | general worker | CPU/memory profile may differ once real PDF/scan work exists (see [ec2-dependency-audit.md](ec2-dependency-audit.md) §1 on binary tool dependencies) — kept as its own queue name so it can be split to its own worker service later without a job-code change |
| `notifications` | `DispatchNotificationJob` and future notification delivery | general worker | High volume, low individual criticality — good candidate for aggressive `--max-jobs` recycling |
| `integrations` | `WebhookDispatchJob` and future outbound webhook/integration work | general worker | External-network-dependent; isolated so a slow/failing third-party endpoint doesn't starve unrelated work |
| `billing` | Future billing/invoicing background work | general worker | Financially adjacent but not itself a trust operation |
| `trust` | Future trust-accounting/payment-adjacent queued work (none exists yet — `PlatformPaymentService`/`ManualPaymentService` are called synchronously today, see audit §6/§13) | **critical worker** (separate ECS service, dedicated capacity, never scaled to zero, alarmed independently — see [infrastructure-architecture.md](infrastructure-architecture.md)) | Mission requirement: "critical queue worker service where required." Trust/payment correctness must never be capacity-starved by an unrelated queue backing up. No trust-queue job exists yet — this is the isolation lane prepared for when one does. |
| `low-priority` | Maintenance-style background work (report generation, cleanup) | general worker, lowest weight | Explicitly allowed to lag behind other queues under load |

**No queue beyond this set is invented.** This mirrors exactly the domain boundaries the codebase already enforces at the service layer (`TenantSafeTrustPolicyService`, `TenantSafeWebhookPolicyService`, `TenantSafeEmailPolicyService`, etc.) — the queue topology follows the application's own existing isolation intent rather than adding new distinctions.

### Worker task definitions

Two ECS services, both running the *same image* with the *same* `docker/commands/worker.sh` (see [container-architecture.md](container-architecture.md)), differing only in the `WORKER_QUEUES` environment variable and task sizing:

- **general worker** — `WORKER_QUEUES=default,documents,notifications,integrations,billing,low-priority` (comma-separated list passed straight to `queue:work --queue=`, which Laravel drains in listed order — `default`/`documents`/etc. before `low-priority`). Autoscales on queue depth (see [observability.md](observability.md)).
- **critical worker** — `WORKER_QUEUES=trust`. Fixed minimum task count (never scales to zero, even when the `trust` queue is empty — a trust-adjacent job must never wait for a cold-start worker), separate CloudWatch alarms on oldest-pending-job-age with a tighter threshold than the general worker.

### Retry, timeout, backoff, failed-job handling

| Setting | Value | Source / rationale |
|---|---|---|
| `--tries` | 3 (general), configurable per worker via `WORKER_TRIES` | `docker/commands/worker.sh` default. No job currently overrides `$tries`, so this is purely a worker-CLI-level policy today. |
| `--timeout` | 90s (general) | Must stay comfortably below the ECS `stopTimeout` for the worker task (see [graceful-shutdown.md](graceful-shutdown.md)) so a genuinely stuck job is killed by Laravel's own timeout before ECS forcibly kills the container. |
| `--backoff` | `10,30,60` (three-step exponential-ish backoff, `WORKER_BACKOFF` env) | Matches the shape (not values) of the existing pure-calculator `WebhookRetryPolicyService` (`base_delay_seconds=30`, `multiplier=2`) already in the codebase for webhook-specific retry — worker-level backoff and domain-level webhook retry are two different layers and are not conflated: worker backoff governs "when does Laravel's queue system re-attempt a *job*," `WebhookRetryPolicyService` governs "when does the *domain logic* re-attempt a webhook delivery," which may itself be implemented as several small idempotent job dispatches rather than one job retrying. |
| `--max-jobs` | 500 (general), `WORKER_MAX_JOBS` | Bounds memory growth from any per-request leak accumulating across a long-lived worker process; ECS restarts the task automatically when the process exits cleanly at this limit. |
| `--max-time` | 3600s (general), `WORKER_MAX_TIME` | Same rationale, time-bounded instead of count-bounded — whichever limit hits first. |
| `--memory` | 256M (general), `WORKER_MEMORY` | Matches task container memory headroom; worker self-exits before OOM-killing, which would otherwise abort a job mid-execution ungracefully. |
| Failed-job driver | `database-uuids` (`config/queue.php:124`, unchanged) | Already correct — writes to the `failed_jobs` table, already queryable by `QueueHealthService::failedJobsCount()`, already surfaced through `HealthCheckRegistry`'s `FailedJobs` check. |
| Dead-letter strategy | Laravel's `failed_jobs` table **is** the dead-letter store today — no SQS DLQ needed while `QUEUE_CONNECTION=redis`/`database` (SQS's DLQ concept is specific to the `sqs` driver, which is configured but unused, see audit §4). If a future decision moves to `QUEUE_CONNECTION=sqs`, `config/queue.php`'s existing `sqs` block already has everything needed except a real DLQ ARN — out of scope here. | |
| Idempotency | No job today performs a non-idempotent side effect on retry (see per-job analysis in [ec2-dependency-audit.md](ec2-dependency-audit.md) §4) — `DispatchNotificationJob` just records a DB row, `WebhookDispatchJob` never rethrows (so Laravel's automatic job-level retry never even fires for it; retry is domain-layer via `WebhookRetryPolicyService`), `ScanDocumentJob`/`RunHealthChecksJob` are naturally idempotent given fixed inputs. **This property must be preserved** as real (non-simulated) implementations replace these — flagged for the owning team as a design constraint, not something this branch enforces in code. | |

### Worker memory recycling and restart behavior

Already covered by `--max-jobs`/`--max-time`/`--memory` above — ECS's own service scheduler (`desiredCount` maintained by the ECS service) replaces an exited worker task automatically, so a clean self-exit at a recycling limit is equivalent to "restart," not an outage.

### Visibility timeout requirements

For the `redis` queue driver, `retry_after` (`config/queue.php:71`, env `REDIS_QUEUE_RETRY_AFTER`, default 90s) is Laravel's equivalent of SQS's visibility timeout: if a worker doesn't finish (and thus delete) a job within `retry_after` seconds, Laravel assumes the worker died and makes the job available again. **This must be set strictly greater than `--timeout`** (worker CLI kill threshold) — otherwise a job still legitimately running could be picked up by a second worker before the first one's timeout even fires, causing double-execution. Current defaults (`retry_after=90`, worker `--timeout=90`) are **equal, not strictly greater** — this is corrected for ECS via env var: set `REDIS_QUEUE_RETRY_AFTER=150` (comfortably above the 90s worker timeout) in every ECS worker task environment. This is a configuration item, not a code change, and is called out explicitly in [staging-readiness-report.md](staging-readiness-report.md) as a required non-default env var.

## Rate limiting

Uses `RateLimiter` facade → `CACHE_STORE` (see above) — no additional configuration beyond `CACHE_STORE=redis`.

## Scheduler locks

`Schedule::withoutOverlapping()` (when the owning team adds `Schedule::` entries — none exist today, see audit §3) uses the same `CACHE_STORE`-backed atomic lock. Combined with the scheduler running as a single-instance ECS service (`desiredCount=1`, see [container-architecture.md](container-architecture.md) and [infrastructure-architecture.md](infrastructure-architecture.md)), overlap protection has two independent layers: only one scheduler process exists at all, and any individual scheduled command additionally protects itself via the cache lock if it declares `withoutOverlapping()`.
