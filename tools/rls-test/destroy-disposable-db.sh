#!/usr/bin/env bash
# Coordinator-only. Drops exactly one disposable database, after verifying
# it matches the approved naming pattern, is not blocklisted, carries this
# mission's sentinel, and has zero active connections. Never force-terminates
# another connection — if one exists, it aborts instead, since an unexpected
# live connection is itself a signal something is wrong.
#
# Usage: destroy-disposable-db.sh <db_name>

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./lib.sh

db_name="${1:?usage: destroy-disposable-db.sh <db_name>}"

rls_reject_if_blocklisted "$db_name"
rls_require_disposable_pattern "$db_name"

rls_acquire_lock

if ! rls_admin_psql -Atc "SELECT 1 FROM pg_database WHERE datname = '${db_name}';" | grep -q 1; then
  rls_fail "database '${db_name}' does not exist — nothing to destroy"
fi

rls_verify_sentinel "$db_name"

conns="$(rls_admin_psql -Atc "SELECT count(*) FROM pg_stat_activity WHERE datname = '${db_name}';")"
if [[ "$conns" != "0" ]]; then
  rls_fail "database '${db_name}' has ${conns} active connection(s); refusing to drop a database that is still in use"
fi

rls_log "dropping disposable database '${db_name}'"
rls_admin_psql -c "DROP DATABASE \"${db_name}\";"
rls_log "dropped '${db_name}'"
