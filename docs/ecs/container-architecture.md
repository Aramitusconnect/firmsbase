# Container Architecture

**Date:** 2026-07-13
**Branch:** `feature/ecs-readiness-foundation`

## Principle: one image, many commands

There is exactly **one** application image (`Dockerfile` at repo root). Every ECS role — web, general queue worker, critical queue worker, scheduler, migration task, maintenance task — runs the **same image digest**, differing only in:

- the container **command** (`docker/commands/*.sh`)
- the ECS **task IAM role** attached
- the ECS **task CPU/memory**
- the ECS **autoscaling policy**

This is enforced structurally: the image build produces no role-specific artifacts, and `docker/entrypoint.sh` (the image's `ENTRYPOINT`) dispatches purely on `$1` (the command passed at `docker run`/ECS task `command`). There is no separate Dockerfile, no `ARG ROLE` baked into a layer, and no role-conditional `COPY`. A digest promoted from staging to production is bit-for-bit identical regardless of which service is running it.

## Base image and PHP runtime

- Build stage base: `composer:2.7` (pinned) for the Composer install stage, `node:18-bookworm-slim` (pinned to the Node 18.19 line already used by this environment — no `.nvmrc` exists in-repo to inherit a version from) for the frontend asset build stage.
- Runtime stage base: `php:8.3-cli-bookworm` (pinned to the 8.3 line the app already requires via `composer.json`'s `"php": "^8.3"`; `-cli` not `-fpm`, because FrankenPHP embeds its own PHP SAPI and does not use php-fpm — see below). Debian `bookworm`, not Alpine: `pdo_pgsql`/`pgsql` build against `libpq`, `intl` against ICU, and `gd` against `freetype`/`libjpeg`/`libpng` — all of these are meaningfully easier to get right and keep patched on Debian than on musl/Alpine, and the app has no static-binary-size requirement that would justify Alpine's added extension-build friction.
- PHP extensions installed at build time via `install-php-extensions` (the extension installer bundled in the `dunglas/frankenphp` base image): `pdo_pgsql pgsql redis bcmath gd intl zip opcache pcntl posix sockets soap sodium exif igbinary` — every extension the application's dependency audit found loaded and relevant (§1 of the [dependency audit](ec2-dependency-audit.md)). A few extensions present in the reference dev environment but not required by any application code (`ffi`, `calendar`, `gettext`, `shmop`, `sysvmsg`/`sysvsem`/`sysvshm`, `ftp`, `readline`) are intentionally left out of the runtime image to keep the attack surface and image size smaller; add them back if a future dependency needs one.
- `pcntl` and `posix` are non-negotiable: they are what let `docker/entrypoint.sh` and `docker/commands/worker.sh` install real signal handlers and `exec` correctly (see Signal Handling below).

## Web server choice: FrankenPHP (classic mode)

The [dependency audit](ec2-dependency-audit.md) (§2) found no in-repo evidence of the current EC2 web-server setup (no nginx/php-fpm/Apache config committed). Rather than reverse-engineer an unknown production setup, this branch makes an explicit, documented choice for the container image:

**FrankenPHP**, in **classic (non-worker) mode**, chosen over nginx+php-fpm for one reason that matters specifically for ECS: FrankenPHP is a single static binary/process that terminates `SIGTERM` itself and drains in-flight HTTP requests before exiting. nginx+php-fpm requires *two* supervised long-running processes in one container (typically via `supervisord`), which the mission's entrypoint requirements explicitly rule out ("do not start multiple unrelated long-running processes in one container," "avoid hidden background daemons"). Running nginx and php-fpm as two separate ECS containers in the same task is the alternative that would preserve single-process-per-container, but it doubles the number of containers, log streams, and health checks to manage for no behavioral benefit over FrankenPHP.

**Classic mode, not worker mode, deliberately**: FrankenPHP's worker mode (like Octane) keeps the Laravel application booted in memory across requests for performance, but requires an application-level audit for state leaking between requests (static properties, container singletons holding request-scoped data) that this mission's boundaries do not authorize (`laravel/octane` isn't even a dependency today). Classic mode boots a fresh PHP request lifecycle per HTTP request — behaviorally identical to php-fpm — so it is a true drop-in with zero application code changes required. If Octane/worker-mode performance is wanted later, that is a separate, deliberate follow-on decision with its own compatibility audit, not something to slip in here.

FrankenPHP serves `public/index.php` per a `docker/web/Caddyfile` that: listens on `:8080` (not 80 — non-root processes cannot bind <1024, see below), sets `root public/`, enables `php_server`, and disables Caddy's automatic HTTPS/cert-fetching (TLS terminates at the ALB, not the container).

## Frontend asset build

Multi-stage: a `frontend` build stage (`node:18-bookworm-slim`) runs `npm ci` (not `npm install` — deterministic, lockfile-verified) then `npm run build` (Vite), producing `public/build/`. Only the compiled `public/build/` output is copied into the runtime stage — no `node_modules`, no source `resources/js`/`resources/css`, no Vite config, no Node binary ship in the final image.

## Composer install

A separate `vendor` build stage runs `composer install --no-dev --optimize-autoloader --no-interaction --no-ansi --no-progress` against `composer.json`/`composer.lock` only (copied before the rest of the application source, so Docker layer caching skips a full reinstall when only application code changes). `--no-dev` excludes `fakerphp/faker`, `laravel/pail`, `laravel/pao`, `laravel/pint`, `mockery/mockery`, `nunomaduro/collision`, `phpunit/phpunit` from the runtime image entirely. Only `vendor/` is copied into the runtime stage — the Composer binary itself, `~/.composer/cache`, and `composer.lock`'s download cache never reach the runtime layer.

## Non-root execution, file permissions, writable directories

The runtime stage creates an unprivileged `app` user/group (fixed UID/GID `1000`, chosen to be predictable for ECS/EFS permission mapping if ever needed) and runs as that user for every role — web, worker, scheduler, migrate, maintenance alike. Ownership: application source (`/var/www/html`) is owned `root:root`, mode `0755`/`0644` (read-only from the app user's perspective, consistent with "immutable image" expectations); only the directories Laravel actually writes to at runtime are `chown`'d to `app:app`:

- `storage/framework/{cache,sessions,testing,views}`
- `storage/logs` (present for local/dev parity even though production logs go to stdout — see [observability.md](observability.md))
- `bootstrap/cache`

No other path under the application root is writable by the runtime user. This is enforced at image-build time (`chown` happens once, in the Dockerfile, not by the entrypoint on every container start) and re-asserted defensively by `docker/entrypoint.sh` failing fast if any of these paths is not writable at startup (cheap `is_writable()` check, not a `chmod` — a container that can't write its own cache directories has a build/deploy defect, not something to silently patch at runtime).

FrankenPHP is configured to bind `:8080`, which unprivileged users can do without `CAP_NET_BIND_SERVICE` — no capability grants are needed.

## Temp directory handling

PHP's `sys_temp_dir` and Laravel's `storage_path('framework/cache/data')`/`storage_path('framework/sessions')` are the only "temp-ish" writes the framework itself performs (see [dependency audit](ec2-dependency-audit.md) §7), and both are redirected off local disk entirely once `CACHE_STORE`/`SESSION_DRIVER` point at Redis in staging/production. What remains on local disk is bounded, ephemeral, framework-internal, and requires nothing beyond "container-local ephemeral storage" — no EFS/persistent volume is mounted or needed. Nothing under `storage/` holds the only copy of business data (confirmed by the dependency audit: no application code currently writes real business files to any disk path at all).

## Health checks

Two distinct concepts, both added in this branch (see [Phase 4 work](../../app/Http/Controllers/HealthController.php) and [graceful-shutdown.md](graceful-shutdown.md) for the operational detail):

- **Liveness** — the pre-existing Laravel `/up` route (`bootstrap/app.php:12`). Answers "is the PHP process alive and can it return a response at all." No dependency checks. Cheap enough for a container-level `HEALTHCHECK`/ECS container health check.
- **Readiness** — a new `/readyz` route added by this branch. Answers "can this task safely receive ALB traffic right now": checks DB connectivity (lightweight `SELECT 1`) and, when a Redis-backed driver is configured, Redis connectivity (`PING`). No business data is touched, no tenant context is established, no database rows are written. This is deliberately kept separate from the pre-existing `HealthCheckRegistry`/`HealthCheckService` system, which persists rows on every run and is meant for periodic business-health monitoring, not a load balancer polling every 15-30 seconds per task — see [dependency audit](ec2-dependency-audit.md) §15.

Only the **web** role serves either endpoint (they're HTTP routes). Worker/scheduler/migration/maintenance tasks have no HTTP listener at all — their "liveness" for ECS purposes is simply "the process is still running" (ECS's own container-level process-exit detection), documented per-role in [Phase 8 task definitions](infrastructure-architecture.md).

## Signal handling

`docker/entrypoint.sh` is the image `ENTRYPOINT`, invoked as PID 1. It performs startup validation (below) then **`exec`s** the role-specific command script (`docker/commands/{web,worker,scheduler,migrate,maintenance}.sh`) — `exec`, not a bare invocation, so the role script *replaces* PID 1 rather than running as its child. This matters because ECS/Docker sends `SIGTERM` to PID 1 only; without `exec`, a shell wrapper left running as PID 1 would absorb the signal and the actual application process underneath it would never see it.

- **web**: FrankenPHP receives `SIGTERM` directly (it is PID 1 after `exec`), stops accepting new connections, drains in-flight requests up to its configured grace period, then exits. See [graceful-shutdown.md](graceful-shutdown.md) for the exact timeout budget.
- **worker**: `docker/commands/worker.sh` execs `php artisan queue:work` directly. Laravel's queue worker already installs its own `SIGTERM`/`SIGINT` handlers when `pcntl` is loaded (confirmed present, §1) and the `--no-interaction` classic worker loop checks for a "should quit" signal between jobs, not mid-job — it does not kill an in-progress job, it finishes the current job then exits without pulling the next one. Combined with `retry_after`/visibility-timeout settings documented in [queue-and-redis-architecture.md](queue-and-redis-architecture.md), this satisfies the mission's "do not partially complete payment or trust operations" requirement without any application code change, because Laravel's worker signal handling already behaves this way — it's a matter of running it correctly (`queue:work`, never `queue:listen`, and never with `--no-stop-when-empty` misconfigured against the ECS stop timeout).
- **scheduler**: `docker/commands/scheduler.sh` execs `php artisan schedule:work`, which similarly loops and checks for shutdown signals between scheduled ticks, never mid-command.
- **migrate/maintenance**: one-shot commands; `exec`'d directly, no special signal handling needed beyond not swallowing a non-zero exit code (both scripts propagate the artisan process's exit code as their own, required so ECS one-off task `stopCode`/`exitCode` correctly reflects success/failure for CI/CD gating — see [ci-cd pipeline](../../.github/workflows/ecs-pipeline.yml)).

## Entrypoint behavior

`docker/entrypoint.sh`, in order:

1. **Fail fast on missing required environment variables** — a fixed list (`APP_KEY`, `APP_ENV`, `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, plus `REDIS_HOST` when `CACHE_STORE`/`SESSION_DRIVER`/`QUEUE_CONNECTION` is `redis`) is checked with `: "${VAR:?required}"`; any missing variable aborts the container immediately with a clear message on stderr, before any Laravel bootstrap runs. This is deliberately shell-level, not a `php artisan` command, so it fails in milliseconds without needing PHP/Composer autoload to even load.
2. **Does not run migrations automatically** on web/worker/scheduler startup — migrations only ever run via the dedicated `docker/commands/migrate.sh` command, invoked as its own ECS one-off task. A web task restarting (deploy, scale-out, task replacement) never touches schema.
3. **Does not cache configuration until required secrets are confirmed present** — `config:cache`/`route:cache`/`view:cache` are *not* run in the entrypoint at all in this branch; they are run once at image-build time in the Dockerfile's runtime stage against build-time-safe defaults are **not** baked in (no secrets exist at build time) — instead, `bootstrap/cache/*.php` is left absent from the image and Laravel falls back to reading `config/*.php` + real env at request time. (Baking `config:cache` into the image would require build-time secrets, which the mission explicitly forbids copying into the image; running it in the entrypoint after the env-var check would add per-task-start latency without much benefit at this app's current size. This trade-off is revisited in [staging-readiness-report.md](staging-readiness-report.md) as a "Ready with configuration" item — enabling build-time `config:cache` against **non-secret** config only, once the config tree is confirmed to contain no secret defaults, is a safe follow-up.)
4. **Supports role-specific commands** via `exec "docker/commands/${1}.sh"`, where `$1` is the ECS task's `command[0]` (`web`/`worker`/`scheduler`/`migrate`/`maintenance`). Unknown `$1` values exit non-zero with a clear error rather than silently doing nothing.
5. **Logs to stdout/stderr only** — nothing in the entrypoint or command scripts redirects output to a file.
6. **No hidden background daemons** — no `&`-backgrounded process anywhere in the entrypoint or command scripts; every script's last action is `exec`.

## Configuration validation

Beyond the required-env-var check (entrypoint step 1), `docker/commands/web.sh` additionally runs `php artisan config:show database.connections.pgsql >/dev/null` style non-mutating sanity checks are intentionally **not** added — config *validation* beyond "the required variables exist" is what `/readyz` is for at runtime (actual DB/Redis reachability), and duplicating that logic at container-start time would just be a slower, less observable copy of the same check. The one exception is `migrate.sh`, which runs `php artisan migrate:status` before `migrate --force` purely for the log trail (see [database-migrations.md](database-migrations.md)).

## Image tagging and immutable digest promotion

- Every build is tagged with the **Git commit SHA** (`ghcr`/ECR tag: `<repo>:<git-sha>`), never `latest` as a deployable identity. `latest` may additionally be pushed for human convenience when browsing the registry, but no task definition or deploy script ever references it.
- The image is pushed to ECR, and the **immutable `imageDigest`** (`sha256:...`) ECR returns is what's threaded through the pipeline into the ECS task definition's `image` field — not the mutable tag — so "what's running" is always traceable to an exact, unchangeable set of bytes. See [Phase 13 CI/CD](../../.github/workflows/ecs-pipeline.yml).
- Build labels (`org.opencontainers.image.revision=<git-sha>`, `org.opencontainers.image.source=<repo-url>`, `org.opencontainers.image.created=<build-timestamp>`) are set via Dockerfile `LABEL`/build-arg so the digest is self-describing even without the pipeline's external metadata.
- Promotion from staging to production (when that is later authorized — **not part of this mission**) is digest re-tagging/re-referencing only, never a rebuild — the same bytes that passed staging smoke tests are what production would run.
