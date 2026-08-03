# Graceful Shutdown

**Date:** 2026-07-13
**Branch:** `feature/ecs-readiness-foundation`

ECS sends `SIGTERM` to a task's PID 1, waits up to the task/service `stopTimeout`, then sends `SIGKILL` if the process hasn't exited. Every command script in `docker/commands/` is `exec`'d by `docker/entrypoint.sh` (see [container-architecture.md](container-architecture.md) "Signal handling"), so the role's real process — not a shell wrapper — is PID 1 and receives `SIGTERM` directly.

## What was actually verified locally, and how

No Docker daemon is available in the environment this branch was authored in (see [staging-readiness-report.md](staging-readiness-report.md)), so FrankenPHP's own SIGTERM handling could not be exercised directly. What **was** verified directly, on real PHP 8.3 with `pcntl` loaded, against a real reachable Redis:

- **`php artisan queue:work redis --queue=default --tries=1 --timeout=5 --sleep=1`**, started, given 2 seconds to reach its idle-sleep loop, sent `SIGTERM` — exited with code `0` in **16ms**.
- **`php artisan schedule:work`**, started (with zero `Schedule::` entries registered, so it logged "Running scheduled tasks" and idled), sent `SIGTERM` after 2 seconds — exited with code `0` in **12ms**.
- **`docker/entrypoint.sh`**'s fail-fast required-env-var check — confirmed it correctly refuses to start and lists every missing variable when none are set.
- **`docker/entrypoint.sh`**'s role dispatch — confirmed an unknown role (`bogus_role`) is correctly rejected with a clear error referencing the exact missing `docker/commands/bogus_role.sh` path, and confirmed a valid role (`maintenance`) correctly resolves to `docker/commands/maintenance.sh` and execs `php artisan --version` with the right arguments (visible in the script's own log line before it hit the expected `/var/www/html`-does-not-exist-outside-the-real-image failure, which is correct behavior for a path that only exists inside the built container).

What was **not** verified (requires a real Docker build — see [staging-readiness-report.md](staging-readiness-report.md)): FrankenPHP's actual HTTP-request-draining behavior on `SIGTERM`, and the full entrypoint→command-script chain running end-to-end inside the real `/var/www/html` filesystem layout.

## Web tasks

FrankenPHP (Caddy-based) stops accepting new connections and drains in-flight requests on `SIGTERM` by default — this is Caddy's standard graceful-shutdown behavior, not something `docker/web/Caddyfile` needs to configure explicitly. Budget:

| Setting | Value | Rationale |
|---|---|---|
| PHP `max_execution_time` | 60s (`docker/php/production.ini`) | Upper bound on any single request's processing time. |
| ECS web task `stopTimeout` | 90s | Must exceed `max_execution_time` so a request already running when `SIGTERM` arrives can finish rather than being killed mid-response; ECS's hard ceiling on `stopTimeout` is 120s, so 90s leaves headroom without hitting it. |
| ALB deregistration delay | 30s | ALB stops sending new requests to a draining target well before ECS's `stopTimeout` fires, so "no new work" (ALB-level) happens first, then "finish in-flight work" (container-level) — the two timeouts are intentionally staggered, not equal, so there's a clean handoff rather than a race. See [infrastructure-architecture.md](infrastructure-architecture.md) for the target-group setting. |

## Queue workers

Verified above: Laravel's `queue:work` (via `pcntl`, confirmed loaded) checks for a shutdown signal **between** jobs, not mid-job — it will finish whatever job is currently executing, then exit without pulling another. This is the entire mechanism behind "do not partially complete payment or trust operations": the job runs to completion or the worker is still gracefully waiting, never killed mid-job by `SIGTERM` alone.

| Setting | Value | Rationale |
|---|---|---|
| Worker `--timeout` | 90s (general), tunable via `WORKER_TIMEOUT` | Laravel's own hard per-job kill threshold — a job that runs longer than this is killed by Laravel itself (via a `pcntl` alarm), independent of ECS. |
| ECS worker task `stopTimeout` | 120s (the ECS maximum) | Must exceed worker `--timeout` by a meaningful margin: `SIGTERM` arrives, the worker finishes its current job (bounded by `--timeout`) and exits cleanly on its own — ECS's `SIGKILL` at `stopTimeout` should, in the overwhelming majority of cases, never actually be needed. Set to the ECS ceiling specifically because "silently truncating a trust/payment-adjacent job" is worse than "a deploy takes slightly longer to finish draining." |
| `REDIS_QUEUE_RETRY_AFTER` (visibility-timeout equivalent) | 150s (see [queue-and-redis-architecture.md](queue-and-redis-architecture.md)) | Must exceed worker `--timeout` (90s) — otherwise a second worker could pick up a job before the first worker's own timeout fires, causing double-execution. 150s > 90s satisfies this with margin. |

**Long-running job risk**: if a future job's real work (once real document generation/import processing exists — see [ec2-dependency-audit.md](ec2-dependency-audit.md) §11) can legitimately exceed 90s, `WORKER_TIMEOUT` and `REDIS_QUEUE_RETRY_AFTER` must be raised together for that queue's worker task definition (never one without the other — see the relationship above), and the job should be evaluated for chunking/idempotent-resumability rather than raising timeouts indefinitely. This is a per-job design decision for the owning team when such a job is built, not something resolved generically here.

**Critical (trust) worker**: identical mechanism, same `--timeout`/`stopTimeout`/visibility-timeout relationship — the critical worker's isolation (see [queue-and-redis-architecture.md](queue-and-redis-architecture.md)) is about *capacity* (never scaled to zero, dedicated alarms), not a different shutdown mechanism. No trust-queue job exists yet to have partial-completion risk from at all (see audit §13) — this budget is prepared for when one does.

## Scheduler

Verified above: `schedule:work` exits cleanly on `SIGTERM` in milliseconds when idle. Running as a single-instance ECS service (`desiredCount=1`, no autoscaling — see [infrastructure-architecture.md](infrastructure-architecture.md)) means there is never a second scheduler instance to overlap with during a deploy; ECS's own deployment rules (`minimumHealthyPercent`/`maximumPercent`, see below) determine whether a brief gap or a brief overlap is possible during the swap from old to new task.

| Setting | Value | Rationale |
|---|---|---|
| ECS scheduler task `stopTimeout` | 30s | Idle-loop process, no in-flight work of consequence to drain (confirmed near-instant exit above) — no reason for a long budget. |
| Deployment configuration | `minimumHealthyPercent=0`, `maximumPercent=100` for this one service only | With `desiredCount=1`, the default rolling-update behavior (`100/200`) would briefly run two scheduler instances simultaneously during a deploy. Since no `Schedule::` entries exist yet (see audit §3) there is no overlap risk to actually observe today, but the setting is prepared correctly now rather than left at a default that would cause duplicate-execution risk once schedule entries exist. `withoutOverlapping()` (cache-lock-based, see [queue-and-redis-architecture.md](queue-and-redis-architecture.md)) is the second, independent layer of protection for individual scheduled commands regardless of this setting. |

## SES consumer

`ses:consume-events` (`App\Console\Commands\ConsumeSesEventsCommand`) is a plain `Illuminate\Console\Command` long-polling an SQS queue — it is not `queue:work`/`schedule:work`, so it does not inherit either of those Laravel components' built-in signal handling. It installs its own: `pcntl_async_signals(true)` + `pcntl_signal(SIGTERM, ...)`/`pcntl_signal(SIGINT, ...)` register a handler that only ever sets an in-memory `$shouldStop` flag (never touches the database, SQS, or logs anything beyond a generic "signal received" line — no message content, no recipient, no secret). That flag is checked in exactly two places:

1. The top of the outer receive loop — never starts another `receiveMessage()` cycle once set.
2. Immediately after each message in a received batch finishes processing (and, if applicable, is deleted) — stops advancing to the next message in that same batch as soon as possible, but never abandons a message already being processed mid-way.

Verified directly (`tests/Feature/Notifications/ConsumeSesEventsCommandTest.php`): a real `SIGTERM` sent to the test process itself (`posix_kill(posix_getpid(), SIGTERM)`) from inside a mocked message-processing callback, mid-batch, proves — via Mockery's own call-count expectations, not a re-implementation of the logic — that a second message in the same batch is never processed and a second `receiveMessage()` call never happens, while the first message's successful processing still results in its SQS message being deleted (delete-only-after-durable-success is unaffected by the shutdown signal).

The handler is reset to the OS default (`SIG_DFL`) in a `finally` block after the loop exits, purely as defensive hygiene — this is a single, per-process handler for this command's own one-shot execution (the process exits right after), so there is no cross-invocation listener-leak risk in normal ECS operation the way a shared long-lived process' event listeners could have.

| Setting | Value | Rationale |
|---|---|---|
| `SES_EVENTS_WAIT_TIME_SECONDS` | 20 (SQS's own maximum) | Long-poll wait per `receiveMessage()` call — bounds the worst-case delay before the loop next checks the shutdown flag, even in the theoretical case where the signal isn't delivered until the blocking call itself returns (pcntl async signals are expected to interrupt it directly in the common case). |
| ECS `ses-consumer` task `stopTimeout` | 30s (`var.ses_consumer_stop_timeout`) | The realistic drain time is bounded by a single message's own processing time (fast: a handful of DB reads/writes), not a full batch, since the flag is checked between messages — 30s leaves ample headroom without approaching ECS's 120s ceiling, matching the scheduler role's own budget for the same "idle/lightweight loop" reasoning. |

Redelivery safety after a shutdown-induced early exit (or any other undeleted message) is unaffected: `SesEventReceipt`'s unique `idempotency_key` constraint makes reprocessing the same event a safe, cheap no-op (see `SesEventConsumerService::recordReceipt()`), and `SuppressionService`/`PlatformNotificationCorrelationService`'s own upsert-or-guard writes mean no duplicate suppression action is ever produced by redelivery.

## Deployment drain behavior (summary across roles)

1. New task definition revision registered (new image digest, see [container-architecture.md](container-architecture.md) "Image tagging").
2. ECS starts new tasks, waits for them to pass the container health check / (for web) ALB target-group health check before marking them healthy.
3. ALB begins routing to new web tasks; old web tasks are deregistered from the target group (30s deregistration delay — no new requests, existing ones continue).
4. `SIGTERM` sent to old tasks (web: after deregistration delay; worker/scheduler: per the ECS deployment's own timing) — each role drains per the table above.
5. `SIGKILL` sent to any task still running at its `stopTimeout`. Web/worker task budgets above are chosen specifically so this should not be the normal path — a task still running at `stopTimeout` indicates a hung request or job, not a shutdown-mechanism failure, and is itself alarm-worthy (see [alarm-inventory.md](alarm-inventory.md)).

The migration and maintenance one-off tasks have no "drain" concept — they are `RunTask` invocations expected to run to completion and exit; see [database-migrations.md](database-migrations.md).
