# Jobs and Scheduler

## 1. Scheduled commands (4 entries, `bootstrap/app.php`, `withSchedule()`)

| Command | Cadence | Overlap guard | Purpose |
|---|---|---|---|
| `integrations:outbox:dispatch` | `everyMinute()` | `withoutOverlapping()` | Enumerates active firms, dispatches one `OutboxDispatchJob` per firm |
| `integrations:sync:retry-poll` | `everyThreeMinutes()` | `withoutOverlapping()` | Dispatches `SyncRetryPollJob` per firm for `FailedRetryable` sync items |
| `integrations:retention:sweep` | `daily()` | `withoutOverlapping()` | Runs the platform-owned webhook-receipts sweep directly, then dispatches one `RetentionSweepJob` per active firm |
| `integrations:platform-overview:refresh` | `everyFiveMinutes()` | `withoutOverlapping()` | Dispatches `RefreshIntegrationPlatformOverviewSummaryJob` per firm, refreshing the no-RLS `integration_platform_overview_summaries` snapshot table |

Every scheduled command is a plain, cheap, non-tenant Artisan command that enumerates active firms from the non-RLS `firms` table and dispatches one per-firm queued job each — it is never itself a `ShouldQueue` job. Actually running Laravel's scheduler (`schedule:work` or a cron/systemd-timer invoking `schedule:run` every minute) in a real environment is a disclosed, non-blocking **operational** dependency this application-code framework cannot itself satisfy — it must be handled by whoever deploys it. See [runbooks/integration-deployment-checklist.md](runbooks/integration-deployment-checklist.md).

Exactly one scheduler process must run per environment — `withoutOverlapping()`'s cache lock is scoped per-scheduler-process, so running the scheduler in more than one place simultaneously would defeat the overlap guard.

## 2. Jobs (7 total)

| Job | Location | Queue-eligible | Purpose |
|---|---|---|---|
| `OutboxDispatchJob` | `app/Jobs/` | `ShouldQueue` | Claims and processes outbox events for a firm |
| `PullSyncJob` | `app/Jobs/` | `ShouldQueue` | Pull-direction sync run for a connection |
| `PushSyncJob` | `app/Jobs/` | `ShouldQueue` | Push-direction sync run for a connection |
| `SyncRetryPollJob` | `app/Jobs/` | `ShouldQueue` | Retries `FailedRetryable` sync items for a firm |
| `RetentionSweepJob` | `app/Jobs/` | `ShouldQueue` | Per-firm retention sweep across tenant-owned integration tables |
| `RefreshIntegrationPlatformOverviewSummaryJob` | `app/Jobs/` | `ShouldQueue` | Refreshes one firm's row in `integration_platform_overview_summaries` |
| `RefreshIntegrationToken` | `app/Integrations/Jobs/` | `ShouldQueue` | OAuth token refresh for a connection (see [oauth.md](oauth.md)) |

All 7 use `App\Support\TenantAwareJobContext` for tenant-context propagation, and constructors carry only bare, non-secret integer FKs (never a hydrated model, never a token/credential) — required because the target tables are FORCE-RLS'd and a fresh worker process has zero tenant context until it establishes it from those bare IDs.

`App\Jobs\WebhookDispatchJob` is **not** part of this framework — it belongs to the pre-existing, unrelated `webhook_subscriptions`/`WebhookDelivery` system and is excluded from this count.

## 3. `RetentionSweepJob` internals (worth calling out separately)

Sweeps, in order: sync items, sync runs, outbox events, OAuth states, resolved conflicts, then processed webhook events (redact-then-delete). The ordering (sync items before sync runs) is a best-effort optimization, not the actual correctness mechanism — a `NOT EXISTS` cascade-hazard guard is what's actually load-bearing. Each batch iteration opens its own fresh transaction via `TenantContextService::runWithFirmContext()`, called **once per batch, never once per firm** — wrapping an entire firm's sweep in one `runWithFirmContext()` call would put every batch inside one giant transaction, losing crash-resumability (a mid-firm crash would roll back every already-processed batch, not just the in-flight one). Every eligibility predicate uses `statement_timestamp()`, never `now()`, matching `IntegrationOutboxEventService::claim()`'s own discipline. Every DELETE/UPDATE uses the `WITH candidate AS (...FOR UPDATE SKIP LOCKED) DELETE/UPDATE ... RETURNING id` CTE shape.

## 4. Zero-downtime deploy consideration (documented, never exercised)

Because every migration in this framework is purely additive (see `runbooks/integration-deployment-checklist.md`), old and new application code can coexist safely during a hypothetical rolling deploy — old workers ignore new columns/tables, and every job here is idempotent, so at-least-once redelivery after a worker restart is safe. This has never been exercised against a real environment; see [README.md](README.md#deployment-authorization).
