# EC2 → ECS Dependency Audit

**Date:** 2026-07-13
**Branch:** `feature/ecs-readiness-foundation`
**Scope:** Read-only inventory of the current application's runtime assumptions, produced before any containerization work. No application code, migrations, or tenant-security logic is changed in this document.

This audit inventories every dependency the application (as it exists on `main` at the time this branch was cut) has on its current EC2 hosting environment, and classifies each as:

- **already container-safe** — works unmodified in ECS
- **requires configuration** — works in ECS with env/task-definition changes only
- **requires code change** — needs an application change (made in this branch, see cross-references)
- **requires external AWS service** — needs a real AWS resource provisioned (RDS, ElastiCache, S3, ECR, etc.) before it can be exercised for real
- **blocker** — must be resolved before staging deployment
- **deferred** — real integration doesn't exist yet in the app (it's simulated/stubbed), so there is nothing to migrate yet; flagged for the team building that feature

## 0. How this audit was produced

Direct inspection of `composer.json`, `composer.lock`, every `config/*.php` file, `bootstrap/app.php`, `routes/`, and a full-repository grep sweep (`storage_path(`, `public_path(`, `file_put_contents(`, `fopen(`, `Storage::`, `shell_exec(`, `exec(`, `proc_open(`, `Process::`, `env(` outside `config/`, absolute local paths, supervisor/systemd/cron/nginx/fpm references, AWS SDK usage) across `app/`, `config/`, and the repository root. No production system was queried — this branch has no access to the live EC2 host, so "current EC2 assumptions" below are inferred from what the repository requires, not observed from the running instance. Any EC2-specific operational detail (actual supervisor config, actual cron table, actual nginx vhost) that isn't captured in this repository is called out explicitly as **unknown / needs EC2-side confirmation** rather than guessed.

## 1. Runtime versions

| Item | Value | Classification |
|---|---|---|
| PHP | `^8.3` (composer.json), running 8.3.6 in this environment | already container-safe — pin exact minor in Dockerfile base image |
| Laravel Framework | `^13.8` | already container-safe |
| Filament | `^4.0` (admin panel) | already container-safe |
| Composer | 2.7.1 present in this environment | already container-safe — pin in Dockerfile build stage |
| Node | v18.19.1 present in this environment | requires configuration — pin explicitly in Dockerfile build stage (no `.nvmrc` in repo to inherit from) |
| npm | 9.2.0 | requires configuration — pinned via Node base image |
| Frontend build | Vite 8 + Tailwind 4 (`vite build`) via `package.json` | already container-safe — standard `npm ci && npm run build` |

**Required PHP extensions** (confirmed present and loaded in this environment, all standard `docker-php-ext-install`/PECL targets): `pdo_pgsql`, `pgsql`, `redis`, `bcmath`, `gd`, `intl`, `zip`, `opcache`, `pcntl`, `posix`, `sockets`, `soap`, `sodium`, `exif`, `ffi`, `igbinary`. `pcntl` and `posix` are already present, which matters directly for graceful SIGTERM handling in queue workers (see [graceful-shutdown.md](graceful-shutdown.md)). Classification: **already container-safe** — all are standard Alpine/Debian PHP extensions with no exotic system library beyond `libpq`, `libsodium`, `libzip`, `freetype`/`libjpeg` (gd), `icu` (intl).

No binary runtime tools are required (no `wkhtmltopdf`, `imagick`, `ghostscript`, `libreoffice`, `unoconv` referenced anywhere in `app/` — see §6, Document Generation).

## 2. Web server / application entry point

**Unknown / needs EC2-side confirmation:** the repository contains no nginx vhost, no php-fpm pool config, no Apache config, no supervisor config, and no systemd unit files. `composer.json`'s `dev` script uses `php artisan serve` (Laravel's built-in dev server) — this is explicitly not intended for production use and there is no evidence in the repository of what serves production traffic on the current EC2 host today (could be php-fpm+nginx, could be `php artisan serve` behind a reverse proxy, could be Octane — nothing in-repo indicates which).

**Decision made for this branch:** rather than guess at the current EC2 web-server setup, the container image adopts **FrankenPHP in classic (non-worker) mode** as the web server — a single static binary that embeds both Caddy and the PHP runtime, serves `public/index.php` per-request exactly like php-fpm+nginx would, requires zero application code changes (no Octane package, no long-lived worker compatibility audit needed), and — critically for ECS — runs as **one process** that receives `SIGTERM` directly and drains in-flight requests before exiting. This avoids running two supervised daemons (nginx + php-fpm) in one container, which the mission's entrypoint requirements rule out. Full rationale in [container-architecture.md](container-architecture.md). Classification: **requires code change** — none to application code, but is a new infrastructure decision this branch makes explicit.

## 3. Process model (web / queue / scheduler)

| Concern | Current evidence | Classification |
|---|---|---|
| Queue workers | No supervisor/systemd config in repo. `composer.json` `dev` script runs `php artisan queue:listen --tries=1 --timeout=0` for local dev only. | **unknown / needs EC2-side confirmation** for production; **requires code change** for ECS — this branch adds `docker/commands/worker.sh` running `queue:work` (not `queue:listen`, which re-bootstraps the framework on every job and is not recommended for production) with an explicit `--tries`, `--timeout`, `--max-jobs`, `--max-time` policy (see [queue-and-redis-architecture.md](queue-and-redis-architecture.md)). |
| Scheduler | `routes/console.php` has **no `Schedule::` registrations at all** — only the stock `inspire` Artisan command. `app/Services/SchedulerHealthService.php`'s own docblock confirms "a scheduled command (wired up outside this phase's scope)... no schedule entries are added here." `app/Jobs/RunHealthChecksJob.php` is designed to be scheduler-triggered but has zero dispatch call sites anywhere in `app/`. | **blocker (pre-existing, not introduced by this branch)** — there is currently no cron/scheduler mechanism running in this application at all, on EC2 or otherwise. ECS's scheduler pattern (a long-running `schedule:work` task, see [container-architecture.md](container-architecture.md)) will run Laravel's scheduler loop correctly once it exists, but **no `Schedule::` entries exist to run**. This branch adds the ECS task definition and container command capable of running `schedule:work`, and flags the absence of registered schedule entries as a product-code gap for a separate change, not something to add silently here (it is out of this mission's boundary to decide which commands should be scheduled and how often — that's an application-behavior decision, not infrastructure). |
| Migrations | `composer.json`'s `setup`/`post-create-project-cmd` scripts run `artisan migrate --force`/`--graceful` at install time; no production migration runbook exists in-repo. | requires code change (this branch adds a dedicated one-off migration ECS task command, see [database-migrations.md](database-migrations.md)) — migrations must never run as a side effect of the web container starting (see Phase 3 entrypoint requirements). |
| Maintenance commands | No existing maintenance script/cron in repo. | requires configuration — this branch adds `docker/commands/maintenance.sh` as a generic one-off `artisan <command>` runner for the ECS maintenance task. |

## 4. Queue driver

`config/queue.php:16` — `QUEUE_CONNECTION` env-driven, defaults to `database`. Redis connection block already fully defined (`config/queue.php:67-74`), env-driven (`REDIS_QUEUE_CONNECTION`, `REDIS_QUEUE`, `REDIS_QUEUE_RETRY_AFTER`). SQS connection block also already present (`config/queue.php:56-65`).

Classification: **already container-safe** (env-driven) for the config layer. **requires configuration** to actually point at ElastiCache Redis in ECS (set `QUEUE_CONNECTION=redis`, `REDIS_HOST=<elasticache-endpoint>`). Currently only 4 jobs exist in `app/Jobs/` (`DispatchNotificationJob`, `RunHealthChecksJob`, `ScanDocumentJob`, `WebhookDispatchJob`), none of which declare an explicit `$queue`/`->onQueue()` — all run on the implicit `default` queue today. See [queue-and-redis-architecture.md](queue-and-redis-architecture.md) for the proposed named-queue split (document/notifications/integrations/billing/trust/low-priority) prepared for when the application starts using it — **deferred**, since forcing queue names onto jobs that don't yet do real work would be scope creep on application code this mission shouldn't make unilaterally.

## 5. Cache / session / distributed locks

- `config/cache.php:18` — `CACHE_STORE` env-driven, defaults to `database`. Redis store fully defined (`config/cache.php:81-85`), env-driven.
- `config/session.php:21` — `SESSION_DRIVER` env-driven, defaults to `database`.
- `config/app.php:121-124` — maintenance-mode driver env-driven, defaults to `file`. **The `file` driver does not work correctly across multiple ECS tasks** (maintenance-mode state is task-local) — this is a **requires configuration** item: ECS environments must set `APP_MAINTENANCE_DRIVER=cache` so `artisan down`/`artisan up` is visible to every task, not just the one that ran the command.
- Distributed locks / rate limiting: Laravel's `Cache::lock()` and `RateLimiter` both ride on whatever the default cache store is — already safe once `CACHE_STORE=redis` is set; no code change needed.

Classification: **already container-safe** at the config layer; **requires configuration** (env vars pointing at ElastiCache) to actually be stateless across tasks; **blocker if left at defaults** — `file` maintenance driver and `database`-backed cache/session/queue are *functionally* fine across ECS tasks sharing one RDS instance (the `database` driver is not task-local, unlike `file`), but they add load to RDS that Redis is meant to absorb, and `APP_MAINTENANCE_DRIVER=file` specifically is task-local and must not be left at its default in ECS. See [queue-and-redis-architecture.md](queue-and-redis-architecture.md).

## 6. Filesystem / document storage

`config/filesystems.php` defines real `local`, `public`, and `s3` disks; the `s3` disk is fully env-driven and ready to point at a real bucket. **However, a full-repository grep found zero real filesystem writes anywhere in `app/`** — every document-adjacent service (`DocumentGenerationService`, `ExportPackageService`, `ExportJobService`, `BackupRestore/FakeBackupRestoreDrillRunner`, `VirusScan/FakeVirusScanner`, `PdfAnnotationService`, `LicenseFileSigningService`) is explicitly simulated/metadata-only by design (their own docblocks state this: "no real PDF/DOCX binary is produced," "No real ZIP file is ever written to disk," "no daemon, no network or filesystem I/O"). `league/flysystem-aws-s3-v3` is present transitively in `composer.lock` but `aws/aws-sdk-php` and direct AWS SDK usage (`Aws\`, `S3Client`, etc.) do not appear anywhere in `app/` or `config/`.

Classification: **deferred** — there is no existing local-disk business-data dependency to migrate off of, because no code writes real files yet. This is a significant, favorable finding: the app is *already* filesystem-stateless with respect to business data, simply because real document I/O hasn't been built. The S3 disk configuration is ready (**requires external AWS service** — an actual bucket, `AWS_BUCKET`/`AWS_ACCESS_KEY_ID`/etc. or, preferably, an IAM task role with no static keys — see [storage-readiness.md](storage-readiness.md) and [iam-matrix.md](iam-matrix.md)) for whenever real document generation/upload is implemented. **This audit does not implement real S3-backed document storage** — doing so would be a product feature change outside this mission's boundary (containerization/infra readiness, not building document I/O). What this branch does do: confirm the storage config is container-correct (env-driven, no hardcoded local paths) and document the S3 target state so the eventual feature work has a ready target.

Import services (`ImportApplyService.php:89`, `ImportRollbackService.php:28-31`) load full unbounded result sets into memory without chunking; not currently queued. Classification: **deferred** (pre-existing application-code performance concern, not an ECS-container-specific blocker at current scale — flagged for the owning team, not fixed here since it's unrelated to containerization).

## 7. Generated / temporary / cache files

`storage/framework/{cache,sessions,views}`, `storage/logs`, `bootstrap/cache` (config/route/event cache) are the only local-disk write targets Laravel itself uses regardless of driver config. All are either (a) fully reproducible framework caches or (b) redirected off local disk by driver configuration (`CACHE_STORE=redis`/`database`, `SESSION_DRIVER=redis`/`database`). Classification: **already container-safe** — these directories may live on the container's ephemeral, non-persistent filesystem layer; nothing under `storage/` needs to survive task replacement once cache/session/queue point at Redis/database. `storage/logs/laravel.log` (the `single`/`daily` log channels' default path) must be repointed to stdout/stderr for ECS (see §10) rather than relying on local log files surviving.

## 8. Shell execution / absolute paths / local-machine assumptions

Zero hits for `shell_exec(`, `exec(`, `proc_open(`, `Process::`, `system(`, `passthru(`, `popen(` anywhere in `app/`. Zero hits for `/home/ubuntu`, `/var/www`, or other hardcoded absolute Unix paths outside Laravel's `storage_path()`/`base_path()`/`public_path()` helpers. Classification: **already container-safe**.

## 9. `env()` usage / config caching

Zero real `env()` calls exist outside `config/*.php` (one false-positive grep hit is a docblock string literal, not code — `app/Services/SecurityBaselineMappingService.php:105`). Classification: **already container-safe** — `php artisan config:cache` is safe to run at image-build/deploy time without missing any app-level `env()` reads. (The entrypoint still avoids caching config *before* required secrets are confirmed present at container start — see [container-architecture.md](container-architecture.md) — as a defense-in-depth measure, not because this codebase currently violates the convention.)

## 10. Logging

`config/logging.php` default channel is `stack` → `single` (`storage_path('logs/laravel.log')`, local file). A `stderr` channel (Monolog `StreamHandler` → `php://stderr`) is already defined in the stock config (`config/logging.php:97-106`) but not the default. Classification: **requires configuration** — ECS task definitions set `LOG_CHANNEL=stderr` (or `stack` with `LOG_STACK=stderr`) via environment variable; no code change needed, the channel already exists. See [observability.md](observability.md).

## 11. Email

`config/mail.php` default mailer is `env('MAIL_MAILER', 'log')` — no real SMTP/API driver wired up today. `EmailSyncService`/`EmailAccountService` are entirely fixture/fake-driven (`FakeEmailProviderClient` is the only implementation of `EmailProviderClient`) — no IMAP/OAuth polling loop, no long-lived connection. Classification: **deferred** — nothing here is EC2-specific or stateful; whenever a real mail driver (SES is the natural choice given AWS target) is configured, it is a pure env-var change with no code impact.

## 12. Webhooks

`WebhookDispatchJob` uses `FakeWebhookTransport` (in-memory recorder, no real HTTP call) as its only transport implementation. `WebhookDestinationValidationService` already blocks the `169.254.169.254` cloud metadata address and other private/loopback destinations at subscribe time (relevant since ECS/Fargate tasks sit behind IMDSv2 at that same address) but does not re-validate at send time — flagged as a **pre-existing SSRF-relevant gap for the owning team**, not something this infra-focused mission should silently patch (it's a webhook-domain code change, not a container/infra change). Classification: **deferred** — no real outbound HTTP transport exists yet to worry about ECS network egress/security-group rules for; documented as a forward-looking requirement in [infrastructure-architecture.md](infrastructure-architecture.md) (egress security group rule needed once real webhook delivery is built).

## 13. Payments (Stripe)

Only `FakeStripeGateway` implements `StripeGateway` — no real Stripe SDK call exists anywhere. No inbound Stripe webhook receiver exists (`grep -rln stripe app/Http routes/` empty). `PlatformPaymentService::attemptPayment()` has no queued-job or webhook caller — it's invoked synchronously, so there is no double-charge-on-retry risk today. `ManualPaymentService::submit()` does implement real DB-level idempotency (`(firm_id, idempotency_key)` partial unique index). Classification: **deferred** — nothing to containerize around yet; the payment-processing queue isolation prepared in [queue-and-redis-architecture.md](queue-and-redis-architecture.md) is forward-looking for when a real gateway/webhook receiver lands.

## 14. Secrets and environment configuration

`.env.example` lists all expected variables; no secret values are committed. `.env`, `.env.backup`, `.env.production`, `.env.testing` are all gitignored. `.rls-test-secrets/` (used by `tools/rls-test/`) is gitignored and, correctly, does not exist in this worktree. Classification: **already container-safe** at the repo layer. **requires external AWS service** for staging: `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD` (if set), and any mail/webhook signing secrets must be sourced from AWS Secrets Manager / SSM Parameter Store and injected via ECS task-definition `secrets` blocks — never baked into the image or passed as plain task-definition `environment` values. See [iam-matrix.md](iam-matrix.md).

## 15. Health endpoints

`bootstrap/app.php:12` already registers Laravel 11+'s built-in `health: '/up'` route — a basic liveness probe (framework boots, returns 200) with no dependency checks. A rich but **request-inappropriate-for-ALB** health subsystem already exists at the application layer: `HealthCheckService`, `HealthCheckRegistry`, `QueueHealthService`, `SchedulerHealthService` — these persist database rows on every invocation (`HealthCheckService::runAllAndRecord()`) and are designed for periodic/business monitoring, not a load balancer hitting them every 15-30 seconds per task. Classification: **requires code change** — this branch adds a lightweight, dependency-light `/readyz` endpoint (DB + Redis reachability only, no DB writes) alongside the existing `/up`, keeping the existing business health-check system untouched. See [Phase 4 work](container-architecture.md) and the new health controller/tests.

## 16. Backups

`app/Services/BackupRestore/FakeBackupRestoreDrillRunner.php` is the only implementation of `BackupRestoreDrillRunner` — "no real infrastructure I/O," deterministic in-memory result only. Classification: **deferred** — no real backup mechanism exists in the application layer to design container compatibility around. RDS automated backups/snapshots are an AWS-managed concern independent of the application container and are addressed at the infrastructure level in [database-migrations.md](database-migrations.md) (schema backup before migration task) and [infrastructure-architecture.md](infrastructure-architecture.md) (RDS backup retention), **requires external AWS service** / **requires human approval** for retention windows and restore testing cadence.

## 17. Tenant context / RLS (boundary note, not a change)

`app/Services/TenantContextService.php` and `TenantContextResolver.php`, and `app/Http/Middleware/{ApplyTenantDatabaseContext,EstablishFirmTenantContext}.php` exist and are extensively covered by the current RLS rollout (see git history — Section 39A-3x). **This audit does not propose any change to tenant-context or RLS semantics.** The one place containerization touches this area at all is informational: the new `/readyz` endpoint (§15) deliberately does **not** invoke `TenantContextService` or read tenant data — it only checks raw DB/Redis connectivity — specifically to avoid any interaction with tenant-scoped session state on an infrastructure-level probe. No dependency requiring a tenant-context change was found. If one is discovered later, it will be documented separately for the tenant-isolation mission rather than changed here, per this mission's boundaries.

## Summary table

| # | Dependency | Classification |
|---|---|---|
| 1 | PHP/Composer/Node/Filament/Vite versions | already container-safe |
| 2 | PHP extensions | already container-safe |
| 3 | Web server process | requires code change (FrankenPHP chosen, this branch) |
| 4 | Queue worker process | requires code change (this branch adds `queue:work` command script) |
| 5 | Scheduler | blocker (pre-existing — no `Schedule::` entries exist at all); this branch adds the *capability* to run one |
| 6 | Migrations | requires code change (this branch adds one-off migration task) |
| 7 | Queue driver | already container-safe (config) / requires configuration (env for Redis) |
| 8 | Cache/session driver | already container-safe (config) / requires configuration (env for Redis) |
| 9 | Maintenance-mode driver | requires configuration (must not default to `file` in ECS) |
| 10 | Document/file storage | deferred (no real I/O exists yet) / requires external AWS service (S3 bucket, when real I/O is built) |
| 11 | Framework cache/log/session local dirs | already container-safe |
| 12 | Shell exec / absolute paths | already container-safe (none found) |
| 13 | `env()` outside config/ | already container-safe (none found) |
| 14 | Logging destination | requires configuration (`LOG_CHANNEL=stderr`) |
| 15 | Email | deferred (fake driver only) |
| 16 | Webhooks outbound transport | deferred (fake transport only) |
| 17 | Payments | deferred (fake gateway only) |
| 18 | Secrets | requires external AWS service (Secrets Manager/SSM) |
| 19 | Health endpoints | requires code change (this branch adds `/readyz`) |
| 20 | Backups | deferred / requires external AWS service |
| 21 | Tenant context / RLS | no change required — out of scope, confirmed untouched |

No item above rises to a **blocker** for *this branch's* deliverables except the pre-existing absence of any scheduler registration, which is an application-behavior gap this mission does not have the authority to fill (deciding what should run on a schedule and how often is a product decision). It is called out again in [staging-readiness-report.md](staging-readiness-report.md) as a required decision before the scheduler ECS service can do anything useful in production.
