# Observability

**Date:** 2026-07-13
**Branch:** `feature/ecs-readiness-foundation`

## Logging

All application logs go to stdout/stderr — no log file on local disk in any ECS environment. This requires exactly one environment variable change, no code change (see [ec2-dependency-audit.md](ec2-dependency-audit.md) §10): `LOG_CHANNEL=stderr`, which resolves to a channel already defined in `config/logging.php:97-106` (`Monolog\Handler\StreamHandler` → `php://stderr`). ECS's `awslogs` log driver (configured per-container in every `docker/commands/*` role via `modules/ecs_service`'s `logConfiguration` block) picks up stdout/stderr and ships it to the per-role CloudWatch log group (`/ecs/<prefix>/{web,worker,critical-worker,scheduler,migrate,maintenance}`, one group per role so a single noisy role never drowns out another's log stream in the same group).

Access logs (FrankenPHP/Caddy) are configured to `output stdout` with `format json` in `docker/web/Caddyfile` — structured JSON, one line per request, alongside the application's own log lines in the same stream.

### What must never appear in a log line

Per the mission requirement, logs must avoid: document contents, trust-account numbers, payment details, authentication secrets, API keys, private client data, uncontrolled request bodies. This branch does not add any new logging statement to application code (no service in `app/Services/` was modified for logging purposes) — the requirement here is about what ECS/infrastructure-level logging introduces, and the answer is: nothing new. FrankenPHP's access log (JSON, method/path/status/duration/bytes — standard Caddy access-log fields) does not log request or response bodies by default, and this branch does not configure it to. `docker/entrypoint.sh` and `docker/commands/*.sh` log only role name, artisan subcommand names, and generic pass/fail status — never environment variable *values* (the fail-fast check in `docker/entrypoint.sh` lists which variable *names* are missing, never what any variable is *set to*).

`ReadinessController` (see [container-architecture.md](container-architecture.md) "Health checks") is a second line of defense here too: its `catch (Throwable)` blocks deliberately discard the exception object entirely rather than logging or returning `$e->getMessage()`, specifically because a DB/Redis connection exception's message commonly embeds the hostname, port, and sometimes username — exactly the kind of infrastructure detail an unauthenticated ALB-probed endpoint must never surface. Verified directly (see [staging-readiness-report.md](staging-readiness-report.md) local verification section) with a simulated exception containing a fake hostname and password string — confirmed absent from the HTTP response.

### Request/correlation IDs

Laravel does not add a correlation ID to every log line by default. Adding one (e.g., binding a `X-Request-Id` header value, generating one with `Str::uuid()` when absent, and including it via a Monolog processor so every log line in a request's lifecycle carries it) is valuable and explicitly allowed by the mission ("without changing tenant-security semantics") — **not implemented in this branch**, because doing it well means either a new middleware (touching the global middleware stack, currently empty in `bootstrap/app.php`) or a Monolog tap, and this mission's boundary list explicitly excludes changing "tenant middleware semantics" without separate review, and a new global middleware — even a narrowly-scoped one — is exactly the kind of change that boundary asks to be flagged rather than made silently. **Flagged as a "Ready with configuration" follow-up** in [staging-readiness-report.md](staging-readiness-report.md): ALB already generates a trace ID (`X-Amzn-Trace-Id`) on every request for free, which is a lower-risk starting point (log it, don't need to invent one) before considering an application-level correlation ID.

### Custom application metrics (CloudWatch, namespace `FirmsBase`)

Prepared (task-role IAM grant for `cloudwatch:PutMetricData` scoped to this namespace — see [iam-matrix.md](iam-matrix.md)) but **not emitted by any code today**. `QueueHealthService` and `SchedulerHealthService` (`app/Services/`) already compute the exact numbers these metrics would carry (`pendingJobsCount()`, `failedJobsCount()`, `oldestPendingJobAgeSeconds()`, `lastHeartbeatAt()`) but only ever persist them to Postgres/cache, never call `PutMetricData`. Wiring that emission is a small, well-scoped application change (not infrastructure) — deliberately not made in this branch, since this mission's boundary is containerization/infra readiness, and adding new production monitoring instrumentation to existing business services is exactly the kind of code change that should be reviewed on its own, not bundled into an infra branch. The alarms that depend on this (see [alarm-inventory.md](alarm-inventory.md)) are gated behind `enable_custom_metric_alarms = false` in Terraform for exactly this reason.

## Metrics (AWS-native, available today without any code change)

Every alarm in [alarm-inventory.md](alarm-inventory.md) that is **not** gated behind `enable_custom_metric_alarms` uses a metric AWS emits automatically the moment the corresponding resource exists: ALB request/latency/5xx metrics, ECS service CPU/running-task-count, RDS CPU/connections/storage, ElastiCache memory/connections. No application code involvement required for these.

## Dashboards

Not built in this branch. A CloudWatch dashboard is a thin visualization layer over the metrics/alarms already defined in Terraform — building one is low-risk and a natural next step, but wasn't included here to keep this branch's scope to the mission's explicit deliverable list (which asks for "CloudWatch logging, metrics, and alarm definitions," not dashboards specifically). Flagged as a quick follow-up in [staging-readiness-report.md](staging-readiness-report.md).
