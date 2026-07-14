#!/bin/bash
# Image ENTRYPOINT — runs as PID 1 in every container regardless of role.
# See docs/ecs/container-architecture.md "Entrypoint behavior" for the full
# rationale behind each step below.
set -euo pipefail

cd /var/www/html

log() {
  # Plain stderr line, no timestamp/formatting added here — Laravel and
  # FrankenPHP add their own structured output; this is just startup
  # diagnostics before either is running. See docs/ecs/observability.md.
  echo "[entrypoint] $*" >&2
}

fail() {
  log "FATAL: $*"
  exit 1
}

# ---------------------------------------------------------------------------
# 1. Fail fast on missing required environment variables.
# Shell-level, deliberately before any PHP/Composer autoload runs, so a
# misconfigured task fails in milliseconds with a clear message rather than
# a confusing Laravel boot error.
# ---------------------------------------------------------------------------
required_vars=(APP_KEY APP_ENV DB_CONNECTION DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD)

# Redis is only required when something is actually configured to use it —
# a task running with CACHE_STORE/SESSION_DRIVER/QUEUE_CONNECTION still at
# their `database` defaults has no Redis dependency to validate.
if [[ "${CACHE_STORE:-}" == "redis" || "${SESSION_DRIVER:-}" == "redis" || "${QUEUE_CONNECTION:-}" == "redis" ]]; then
  required_vars+=(REDIS_HOST)
fi

missing=()
for var in "${required_vars[@]}"; do
  if [[ -z "${!var:-}" ]]; then
    missing+=("$var")
  fi
done

if [[ ${#missing[@]} -gt 0 ]]; then
  fail "missing required environment variable(s): ${missing[*]}"
fi

# ---------------------------------------------------------------------------
# 2. Defensive re-assertion that the writable paths the image prepared at
# build time are actually writable at runtime (catches a bad volume mount,
# a wrong ECS task role... no, a wrong filesystem mode, or a build defect —
# NOT something this script silently repairs with chmod).
# ---------------------------------------------------------------------------
writable_paths=(
  storage/framework/cache/data
  storage/framework/sessions
  storage/framework/testing
  storage/framework/views
  storage/logs
  bootstrap/cache
)

for path in "${writable_paths[@]}"; do
  if [[ ! -w "$path" ]]; then
    fail "required writable path '$path' is not writable by the current user (uid=$(id -u)) — this indicates a broken image build or filesystem mount, not something to work around here"
  fi
done

# ---------------------------------------------------------------------------
# 3. Deliberately does NOT run migrations and does NOT run config:cache here.
# Migrations run only via `docker/commands/migrate.sh` as its own one-off
# ECS task. Config caching is intentionally left to build-time-safe defaults
# only (see docs/ecs/container-architecture.md) — no secret-bearing
# config:cache is generated at container start.
# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# 4. Dispatch to the role-specific command script. `exec` replaces this
# script's process (PID 1) with the target process, so SIGTERM sent by
# ECS/Docker reaches the real application process directly instead of being
# absorbed by a shell wrapper.
# ---------------------------------------------------------------------------
role="${1:-}"

if [[ -z "$role" ]]; then
  fail "no role/command given — expected one of: web, worker, scheduler, migrate, maintenance"
fi

command_script="docker/commands/${role}.sh"

if [[ ! -f "$command_script" ]]; then
  fail "unknown role '${role}' — no such command script '${command_script}'"
fi

shift || true
log "starting role '${role}' (APP_ENV=${APP_ENV})"
exec "$command_script" "$@"
