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
# 0. Resolve the role early — before any required-variable validation — so
# that validation can be role-specific (e.g. SES_EVENTS_QUEUE_URL only
# matters to the ses-consumer role; PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY
# only matters to web and ses-consumer). Only captures the value here —
# deliberately does NOT fail yet if empty; that check stays at the
# dispatch step below (its original location), so this block stays a
# pure, side-effect-free variable assignment. `shift` here, once, so the
# remaining "$@" (any extra arguments, e.g. maintenance's Artisan
# subcommand) reaches the command script unchanged at dispatch time below.
# ---------------------------------------------------------------------------
role="${1:-}"
shift || true

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

# ses-consumer is the only role that polls the SES bounce/complaint SQS
# queue — SES_EVENTS_QUEUE_URL has no meaning to, and must not be demanded
# of, any other role.
if [[ "$role" == "ses-consumer" ]]; then
  required_vars+=(SES_EVENTS_QUEUE_URL)
fi

# PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY is required only by
# the two roles that can actually reach CorrelatedPasswordResetSenderService's
# platform-scope path: web (synchronous password-reset/owner-invitation
# sends from the request path — these do not implement ShouldQueue) and
# ses-consumer (resolves platform_notification_correlations rows keyed by
# a fingerprint derived from this same key). worker/critical-worker/
# scheduler/migrate/maintenance never call that code path today and must
# not be made to depend on a secret they don't need — see
# docs/ecs/iam-matrix.md.
if [[ "$role" == "web" || "$role" == "ses-consumer" ]]; then
  required_vars+=(PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY)
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
# 1b. When Redis is required and configured for TLS, fail fast if the CA
# bundle config/database.php will ask PhpRedis to verify against isn't
# actually present and readable — a broken/missing CA file otherwise
# surfaces only much later as an opaque "SSL operation failed: certificate
# verify failed" from inside a request. Resolution order matches
# config/database.php exactly: REDIS_TLS_CA_FILE, then SSL_CERT_FILE, then
# the distro default. REDIS_TLS_PEER_NAME is NOT required here — the
# application derives a safe default from REDIS_HOST when it's absent.
# Never prints REDIS_PASSWORD, any secret ARN, or a credential-bearing URL.
# ---------------------------------------------------------------------------
redis_required=0
if [[ "${CACHE_STORE:-}" == "redis" || "${SESSION_DRIVER:-}" == "redis" || "${QUEUE_CONNECTION:-}" == "redis" ]]; then
  redis_required=1
fi

if [[ "$redis_required" -eq 1 && "${REDIS_HOST:-}" == tls://* ]]; then
  redis_ca_file="${REDIS_TLS_CA_FILE:-${SSL_CERT_FILE:-/etc/ssl/certs/ca-certificates.crt}}"

  if [[ ! -f "$redis_ca_file" ]]; then
    fail "Redis TLS CA file '${redis_ca_file}' does not exist — cannot start with an unverifiable Redis TLS connection"
  fi

  if [[ ! -r "$redis_ca_file" ]]; then
    fail "Redis TLS CA file '${redis_ca_file}' exists but is not readable by the current user (uid=$(id -u)) — cannot start with an unverifiable Redis TLS connection"
  fi

  log "Redis TLS CA file verified present and readable: ${redis_ca_file}"
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
# absorbed by a shell wrapper. `role` was already resolved (and shifted out
# of "$@") in step 0 above so the required-variable validation in step 1
# could be role-specific; the empty-role check stays here (its original
# location) rather than in step 0, so step 0 remains a pure assignment.
# ---------------------------------------------------------------------------
if [[ -z "$role" ]]; then
  fail "no role/command given — expected one of: web, worker, scheduler, migrate, maintenance, ses-consumer"
fi

command_script="docker/commands/${role}.sh"

if [[ ! -f "$command_script" ]]; then
  fail "unknown role '${role}' — no such command script '${command_script}'"
fi

log "starting role '${role}' (APP_ENV=${APP_ENV})"
exec "$command_script" "$@"
