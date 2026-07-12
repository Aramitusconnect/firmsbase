#!/usr/bin/env bash
# Standalone pre-flight check: validates the CURRENT shell environment's
# DB_* variables against every Stage A safety rule, without running
# anything. Intended as a manual sanity check, or as the first line of any
# future script that must perform a destructive database operation outside
# the artisan wrapper. Exits non-zero and refuses on any violation.
#
# Usage: source this file, or run it directly with the target env already
# exported: DB_DATABASE=... DB_USERNAME=... ./guard-environment.sh

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./lib.sh

for var in DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD; do
  if [[ -z "${!var:-}" ]]; then
    rls_fail "required environment variable ${var} is not set"
  fi
done

if [[ "${APP_ENV:-}" != "testing" ]]; then
  rls_fail "APP_ENV must be 'testing', got '${APP_ENV:-<unset>}'"
fi

if [[ "$DB_HOST" != "$RLS_TEST_HOST" || "$DB_PORT" != "$RLS_TEST_PORT" ]]; then
  rls_fail "DB_HOST:DB_PORT must be ${RLS_TEST_HOST}:${RLS_TEST_PORT}, got ${DB_HOST}:${DB_PORT}"
fi

if [[ "$DB_USERNAME" != "$RLS_TEST_ROLE" ]]; then
  rls_fail "DB_USERNAME must be the dedicated mission test role '${RLS_TEST_ROLE}', got '${DB_USERNAME}'"
fi

rls_reject_if_blocklisted "$DB_DATABASE"
rls_require_disposable_pattern "$DB_DATABASE"
rls_verify_sentinel "$DB_DATABASE"

rls_log "environment OK: database='${DB_DATABASE}' host=${DB_HOST} port=${DB_PORT} role=${DB_USERNAME}"
