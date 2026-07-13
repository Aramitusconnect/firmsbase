#!/usr/bin/env bash
# Computes a comparable schema fingerprint for a disposable/template
# database: table count, migration count, FORCE-enabled count, RLS-enabled
# count, and a sorted table-name hash. Read-only, uses the dedicated test
# role's own connection.
#
# Usage: fingerprint-database.sh <db_name>

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./lib.sh

db_name="${1:?usage: fingerprint-database.sh <db_name>}"
rls_reject_if_blocklisted "$db_name"

table_count="$(rls_test_psql "$db_name" -Atc "SELECT count(*) FROM pg_tables WHERE schemaname = 'public';")"

migration_count="0"
if rls_test_psql "$db_name" -Atc "SELECT 1 FROM information_schema.tables WHERE table_name = 'migrations';" | grep -q 1; then
  migration_count="$(rls_test_psql "$db_name" -Atc "SELECT count(*) FROM migrations;")"
fi

force_count="$(rls_test_psql "$db_name" -Atc "
  SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
  WHERE n.nspname = 'public' AND c.relkind = 'r' AND c.relforcerowsecurity;
")"

rls_enabled_count="$(rls_test_psql "$db_name" -Atc "
  SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
  WHERE n.nspname = 'public' AND c.relkind = 'r' AND c.relrowsecurity;
")"

table_name_hash="$(rls_test_psql "$db_name" -Atc "
  SELECT md5(string_agg(tablename, ',' ORDER BY tablename)) FROM pg_tables WHERE schemaname = 'public';
")"

echo "db_name=${db_name}"
echo "table_count=${table_count}"
echo "migration_count=${migration_count}"
echo "force_count=${force_count}"
echo "rls_enabled_count=${rls_enabled_count}"
echo "table_name_hash=${table_name_hash}"
