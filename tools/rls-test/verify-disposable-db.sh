#!/usr/bin/env bash
# Read-only safety verification for a disposable (or template) database.
# Safe for any role to run (uses the dedicated test role's own connection,
# not the admin role) except for the sentinel table's canonical_head check,
# which is read via the admin role since the sentinel table's DELETE/INSERT
# happened under admin during creation but SELECT is available to the owner
# role too — this script only ever SELECTs.
#
# Usage: verify-disposable-db.sh <db_name>
# Exits 0 and prints "OK" plus the sentinel row if everything checks out.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./lib.sh

db_name="${1:?usage: verify-disposable-db.sh <db_name>}"

rls_reject_if_blocklisted "$db_name"

if [[ "$db_name" =~ $RLS_DISPOSABLE_PATTERN ]]; then
  kind="disposable"
elif [[ "$db_name" =~ $RLS_TEMPLATE_PATTERN ]]; then
  kind="template"
else
  rls_fail "database '${db_name}' matches neither the disposable nor the template naming pattern"
fi

rls_verify_sentinel "$db_name"

owner="$(rls_admin_psql -Atc "SELECT pg_catalog.pg_get_userbyid(datdba) FROM pg_database WHERE datname = '${db_name}';")"
if [[ "$owner" != "$RLS_TEST_ROLE" ]]; then
  rls_fail "database '${db_name}' is owned by '${owner}', expected '${RLS_TEST_ROLE}'"
fi

# Definitive check: grantee OID 0 in an exploded ACL item represents the
# PUBLIC pseudo-role. If any such item still grants CONNECT ('c'), PUBLIC
# has not been fully revoked.
public_connect="$(rls_admin_psql -Atc "
  SELECT count(*)
  FROM pg_database d, aclexplode(d.datacl) acl
  WHERE d.datname = '${db_name}' AND acl.grantee = 0 AND acl.privilege_type = 'CONNECT';
")"
if [[ "$public_connect" != "0" ]]; then
  rls_fail "database '${db_name}' still grants CONNECT to PUBLIC"
fi

rls_log "OK: '${db_name}' (${kind}) — sentinel valid, owner=${RLS_TEST_ROLE}, PUBLIC connect revoked"
echo "OK $db_name $kind"
