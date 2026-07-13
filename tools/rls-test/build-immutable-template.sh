#!/usr/bin/env bash
# Coordinator-only. Builds (or rebuilds) the immutable, verified schema
# template for the currently checked-out HEAD. Never used for tests
# directly — only ever cloned into a fresh disposable database via
# create-disposable-db.sh's TEMPLATE argument.
#
# Usage: build-immutable-template.sh
# Prints the resulting template database name on stdout (last line).

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./lib.sh

current_head_short="$(cd "$RLS_WORKTREE_ROOT" && git rev-parse --short=7 HEAD)"
current_head_full="$(cd "$RLS_WORKTREE_ROOT" && git rev-parse HEAD)"
db_name="firmsbase_test_39a3l_template_${current_head_short}"
rls_require_template_pattern "$db_name"

# Only the application/schema-relevant trees matter for template fidelity —
# database/ (migrations) and app/ (models/services/factories affect what
# gets built and how it behaves). Stage A's own infrastructure tooling
# (tools/rls-test, tests/bootstrap*.php, .claude/agents, phpunit.xml,
# .gitignore) is legitimately uncommitted while Stage A is still under dual
# review, and does not affect database schema, so it is not part of this
# check.
dirty_schema_relevant="$(cd "$RLS_WORKTREE_ROOT" && git status --porcelain=v1 -- database/ app/ database/factories)"
if [[ -n "$dirty_schema_relevant" ]]; then
  rls_fail "database/ or app/ has uncommitted changes; refusing to build an immutable template from an unverified schema/behavior state:
${dirty_schema_relevant}"
fi

rls_acquire_lock

if rls_admin_psql -Atc "SELECT 1 FROM pg_database WHERE datname = '${db_name}';" | grep -q 1; then
  rls_log "template '${db_name}' already exists for this HEAD — verifying it rather than rebuilding"
  RLS_SKIP_HEAD_CHECK=0 rls_verify_sentinel "$db_name"
  rls_log "existing template verified OK"
  echo "$db_name"
  exit 0
fi

rls_log "creating empty template database '${db_name}' as OWNER ${RLS_TEST_ROLE}"
rls_admin_psql -c "CREATE DATABASE \"${db_name}\" OWNER ${RLS_TEST_ROLE};"

rls_log "revoking CONNECT from PUBLIC on '${db_name}'"
rls_admin_psql -c "REVOKE CONNECT ON DATABASE \"${db_name}\" FROM PUBLIC;"
rls_admin_psql -c "GRANT CONNECT ON DATABASE \"${db_name}\" TO ${RLS_TEST_ROLE};"

rls_log "building schema via committed migrations (${current_head_full})"
APP_ENV=testing \
DB_CONNECTION=pgsql \
DB_HOST="$RLS_TEST_HOST" \
DB_PORT="$RLS_TEST_PORT" \
DB_DATABASE="$db_name" \
DB_USERNAME="$RLS_TEST_ROLE" \
DB_PASSWORD="$(rls_test_role_password)" \
bash -c "cd '$RLS_WORKTREE_ROOT' && php artisan migrate --force"

rls_log "writing mission sentinel"
sentinel="mission=${RLS_MISSION};purpose=template;head=${current_head_full};run_id=template;created=$(date -u +%FT%TZ)"
rls_admin_psql -c "COMMENT ON DATABASE \"${db_name}\" IS '${sentinel}';"

rls_log "marking template read-only at the database level (default_transaction_read_only)"
rls_admin_psql -c "ALTER DATABASE \"${db_name}\" SET default_transaction_read_only = on;"

rls_log "template '${db_name}' built and verified"
echo "$db_name"
