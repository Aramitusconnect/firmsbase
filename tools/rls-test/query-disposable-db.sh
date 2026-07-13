#!/usr/bin/env bash
# Read-only ad-hoc query helper for reviewers/auditors. Runs exactly one
# SELECT-shaped statement (or other genuinely read-only pg_catalog query)
# against a validated disposable/template database, via the dedicated test
# role's own connection. Never prints or requires a password directly —
# the caller never needs to see the secret.
#
# Usage: query-disposable-db.sh <db_name> "<SQL>"
# Refuses anything that is not a SELECT/WITH/SHOW/EXPLAIN statement.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./lib.sh

db_name="${1:?usage: query-disposable-db.sh <db_name> \"<SQL>\"}"
sql="${2:?usage: query-disposable-db.sh <db_name> \"<SQL>\"}"

rls_reject_if_blocklisted "$db_name"

if [[ "$db_name" =~ $RLS_DISPOSABLE_PATTERN ]] || [[ "$db_name" =~ $RLS_TEMPLATE_PATTERN ]]; then
  :
else
  rls_fail "database '${db_name}' matches neither the disposable nor the template naming pattern"
fi

rls_verify_sentinel "$db_name"

normalized="$(echo "$sql" | tr '[:upper:]' '[:lower:]' | sed 's/^[[:space:]]*//')"
if [[ ! "$normalized" =~ ^(select|with|show|explain) ]]; then
  rls_fail "only SELECT/WITH/SHOW/EXPLAIN statements are permitted through this script, got: ${sql}"
fi

rls_test_psql "$db_name" -c "$sql"
