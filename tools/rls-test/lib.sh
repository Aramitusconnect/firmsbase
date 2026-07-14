#!/usr/bin/env bash
# Section 39A-3L Stage A — shared helpers for the RLS disposable-test-database
# toolchain. Sourced by the other scripts in this directory. Never echoes a
# secret value; only ever reads the password from the gitignored secret file
# and passes it via PGPASSWORD in the child process environment.

set -euo pipefail

RLS_MISSION="section-39a3l"
RLS_TEST_ROLE="rls_test_runner_39a3l"
RLS_TEST_HOST="127.0.0.1"
RLS_TEST_PORT="5432"
RLS_DISPOSABLE_PATTERN='^firmsbase_test_39a3l_disposable_[a-z0-9]+_[a-z0-9]+$'
RLS_TEMPLATE_PATTERN='^firmsbase_test_39a3l_template_[a-f0-9]{7,40}$'

RLS_WORKTREE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RLS_SECRET_FILE="${RLS_WORKTREE_ROOT}/.rls-test-secrets/rls_test_runner_39a3l.pgpass"
RLS_LOCK_FILE="/home/ubuntu/firmsbase/rls-checkpoints/.rls-test-db.lock"

RLS_BLOCKLIST=(
  "firmsbase"
  "firmsbase_test"
  "firmsbase_test_39a3k"
  "firmsbase_test_39a3l_marathon"
  "firmsbase_test_39a3l_checkpoint21_recovery"
  "firmsbase_test_39a3l_marathon_incident_20260712"
  "firmsbase_test_39a4a1_inventory"
  "firmsbase_test_39a4a_classification"
)

rls_log() {
  echo "[rls-test] $*" >&2
}

rls_fail() {
  echo "[rls-test] REFUSED: $*" >&2
  exit 1
}

rls_require_secret_file() {
  if [[ ! -f "$RLS_SECRET_FILE" ]]; then
    rls_fail "secret file not found at ${RLS_SECRET_FILE} — Phase A2 role creation must run first"
  fi
  local perms
  perms="$(stat -c '%a' "$RLS_SECRET_FILE")"
  if [[ "$perms" != "600" ]]; then
    rls_fail "secret file ${RLS_SECRET_FILE} has unsafe permissions ${perms}, expected 600"
  fi
}

rls_test_role_password() {
  rls_require_secret_file
  cat "$RLS_SECRET_FILE"
}

# Checks a database name against the blocklist. Aborts if blocked.
rls_reject_if_blocklisted() {
  local db_name="$1"
  local entry
  for entry in "${RLS_BLOCKLIST[@]}"; do
    if [[ "$db_name" == "$entry" ]]; then
      rls_fail "database '${db_name}' is an explicitly blocked persistent/shared/quarantined/sibling-mission database"
    fi
  done
}

# Requires the name to match the disposable pattern. Aborts otherwise.
rls_require_disposable_pattern() {
  local db_name="$1"
  if [[ ! "$db_name" =~ $RLS_DISPOSABLE_PATTERN ]]; then
    rls_fail "database '${db_name}' does not match the approved disposable naming pattern 'firmsbase_test_39a3l_disposable_<purpose>_<run_id>'"
  fi
}

rls_require_template_pattern() {
  local db_name="$1"
  if [[ ! "$db_name" =~ $RLS_TEMPLATE_PATTERN ]]; then
    rls_fail "database '${db_name}' does not match the approved immutable-template naming pattern 'firmsbase_test_39a3l_template_<head_sha>'"
  fi
}

# Runs a psql command as the postgres administrative role. Coordinator-only —
# never invoke this function's caller scripts from a subagent task prompt.
# When PGHOST is set (e.g. CI with a Docker service container) it connects via
# TCP using the standard libpq env vars; otherwise it falls back to the local
# Unix-socket peer-auth path used during local development.
rls_admin_psql() {
  if [[ -n "${PGHOST:-}" ]]; then
    psql -X -q -v ON_ERROR_STOP=1 -h "$PGHOST" "$@"
  else
    sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 "$@"
  fi
}

# Runs a psql command as the dedicated mission test role against a specific
# database, without ever printing the password.
rls_test_psql() {
  local db_name="$1"
  shift
  PGPASSWORD="$(rls_test_role_password)" psql -X -q -v ON_ERROR_STOP=1 \
    -h "$RLS_TEST_HOST" -p "$RLS_TEST_PORT" -U "$RLS_TEST_ROLE" -d "$db_name" "$@"
}

# Confirms the given database carries this mission's sentinel and that its
# canonical_head matches the currently checked-out HEAD (unless
# RLS_SKIP_HEAD_CHECK=1, used only for template-build against a known frozen
# HEAD before it is checked out again).
#
# The sentinel is stored via COMMENT ON DATABASE (pg_shdescription), NOT a
# table inside the database's own public schema. This is deliberate: Laravel
# RefreshDatabase's first-use-per-process reset on PostgreSQL runs the
# equivalent of `DROP SCHEMA public CASCADE; CREATE SCHEMA public;` before
# re-running migrations — a normal, expected, legitimate part of running the
# test suite against a disposable database — which would silently destroy
# any sentinel stored as an ordinary table. A database-level COMMENT lives
# in a shared catalog outside any single schema and survives that reset,
# so ownership/authenticity can still be verified after a real test run.
rls_verify_sentinel() {
  local db_name="$1"
  local current_head
  current_head="$(cd "$RLS_WORKTREE_ROOT" && git rev-parse HEAD)"

  local comment
  comment="$(rls_admin_psql -Atc \
    "SELECT shobj_description(oid, 'pg_database') FROM pg_database WHERE datname = '${db_name}';" 2>/dev/null || true)"

  if [[ -z "$comment" ]]; then
    rls_fail "database '${db_name}' has no mission sentinel comment — refusing to treat it as a mission-owned disposable/template database"
  fi

  local sentinel_mission sentinel_head
  sentinel_mission="$(echo "$comment" | sed -n 's/.*mission=\([^;]*\).*/\1/p')"
  sentinel_head="$(echo "$comment" | sed -n 's/.*head=\([^;]*\).*/\1/p')"

  if [[ "$sentinel_mission" != "$RLS_MISSION" ]]; then
    rls_fail "database '${db_name}' sentinel mission mismatch: expected '${RLS_MISSION}', found '${sentinel_mission}'"
  fi

  if [[ "${RLS_SKIP_HEAD_CHECK:-0}" != "1" && "$sentinel_head" != "$current_head" ]]; then
    rls_fail "database '${db_name}' sentinel canonical_head mismatch: expected '${current_head}', found '${sentinel_head}'"
  fi
}

# Acquires the mission-wide database-operation lock for the duration of the
# calling script (released automatically on exit via the fd being closed).
rls_acquire_lock() {
  mkdir -p "$(dirname "$RLS_LOCK_FILE")"
  exec {RLS_LOCK_FD}>"$RLS_LOCK_FILE"
  if ! flock -w 60 "$RLS_LOCK_FD"; then
    rls_fail "could not acquire the database-operation lock within 60s — another create/destroy operation is in progress"
  fi
  rls_log "acquired database-operation lock"
}
