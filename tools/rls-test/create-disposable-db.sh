#!/usr/bin/env bash
# Coordinator-only. Creates a uniquely named, sentinel-tagged disposable
# database, owned by the dedicated mission test role, with PUBLIC CONNECT
# revoked. Prints only the new database name on stdout (last line) so
# calling scripts can capture it; all other output goes to stderr.
#
# Usage: create-disposable-db.sh <purpose> [template_db_name]
#   purpose           short lowercase[0-9] tag, e.g. "shard7" or "run"
#   template_db_name  optional: an already-verified immutable template to
#                     clone from (must match the template naming pattern and
#                     carry a valid sentinel). Omit to build an empty schema.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./lib.sh

purpose="${1:?usage: create-disposable-db.sh <purpose> [template_db_name]}"
template_db="${2:-}"

if [[ ! "$purpose" =~ ^[a-z0-9]+$ ]]; then
  rls_fail "purpose '${purpose}' must be lowercase alphanumeric only"
fi

run_id="$(date +%s)$(( RANDOM % 1000 ))"
db_name="firmsbase_test_39a3l_disposable_${purpose}_${run_id}"
rls_require_disposable_pattern "$db_name"
rls_reject_if_blocklisted "$db_name"

current_head="$(cd "$RLS_WORKTREE_ROOT" && git rev-parse HEAD)"

rls_acquire_lock

rls_log "checking target name '${db_name}' is unused"
if rls_admin_psql -Atc "SELECT 1 FROM pg_database WHERE datname = '${db_name}';" | grep -q 1; then
  rls_fail "database '${db_name}' already exists (run_id collision) — aborting"
fi

if [[ -n "$template_db" ]]; then
  rls_require_template_pattern "$template_db"
  rls_log "verifying template '${template_db}' before cloning"
  rls_verify_sentinel "$template_db"
  conns="$(rls_admin_psql -Atc "SELECT count(*) FROM pg_stat_activity WHERE datname = '${template_db}';")"
  if [[ "$conns" != "0" ]]; then
    rls_fail "template '${template_db}' has ${conns} active connection(s); refusing to clone from a template that is in use"
  fi
  rls_log "creating '${db_name}' as OWNER ${RLS_TEST_ROLE} cloned from template '${template_db}'"
  rls_admin_psql -c "CREATE DATABASE \"${db_name}\" OWNER ${RLS_TEST_ROLE} TEMPLATE \"${template_db}\";"
else
  rls_log "creating empty database '${db_name}' as OWNER ${RLS_TEST_ROLE}"
  rls_admin_psql -c "CREATE DATABASE \"${db_name}\" OWNER ${RLS_TEST_ROLE};"
fi

rls_log "revoking CONNECT from PUBLIC on '${db_name}'"
rls_admin_psql -c "REVOKE CONNECT ON DATABASE \"${db_name}\" FROM PUBLIC;"
rls_admin_psql -c "GRANT CONNECT ON DATABASE \"${db_name}\" TO ${RLS_TEST_ROLE};"

rls_log "writing mission sentinel (database-level COMMENT, survives a schema-level reset)"
sentinel="mission=${RLS_MISSION};purpose=${purpose};head=${current_head};run_id=${run_id};created=$(date -u +%FT%TZ)"
rls_admin_psql -c "COMMENT ON DATABASE \"${db_name}\" IS '${sentinel}';"

rls_log "verifying the sentinel was applied correctly (read-back check) before reporting success"
written_comment="$(rls_admin_psql -Atc "SELECT shobj_description(oid, 'pg_database') FROM pg_database WHERE datname = '${db_name}';")"
if [[ "$written_comment" != "$sentinel" ]]; then
  rls_fail "sentinel read-back mismatch for '${db_name}': wrote '${sentinel}', read back '${written_comment}' — refusing to report success"
fi

rls_log "created and verified '${db_name}' (host=${RLS_TEST_HOST} port=${RLS_TEST_PORT} role=${RLS_TEST_ROLE})"
echo "$db_name"
