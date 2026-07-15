#!/usr/bin/env bash
# Regression test for the CI disposable-database naming contract enforced
# by tests/bootstrap-verify-test-database.php and
# tools/rls-test/lib.sh's RLS_DISPOSABLE_PATTERN.
#
# Written after .github/workflows/schema-tenant-firewall.yml's CI database
# name ("firmsbase_test_39a3l_disposable_ci_${GITHUB_RUN_ID}_${GITHUB_RUN_ATTEMPT}")
# was refused by tests/bootstrap-verify-test-database.php's guard: that
# name LOOKS like it matches "disposable_<purpose>_<run_id>", but the
# pattern permits exactly ONE underscore after "disposable_" — a single
# alphanumeric purpose token, then a single alphanumeric run_id token,
# neither of which may itself contain an underscore. Joining
# GITHUB_RUN_ID and GITHUB_RUN_ATTEMPT with "_" produced a THIRD
# alphanumeric segment, which the regex rejects outright.
#
# Pure string/regex checks against the real RLS_DISPOSABLE_PATTERN
# constant (sourced from lib.sh, not duplicated here) — no live Postgres
# connection required, so this runs fast and needs no database.
#
# Usage: tools/rls-test/tests/disposable-name-pattern-test.sh

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
source ./lib.sh

fail() { echo "NAMING PATTERN TEST FAILED: $*" >&2; exit 1; }
pass() { echo "OK: $*"; }

assert_matches() {
  local name="$1" desc="$2"
  if [[ ! "$name" =~ $RLS_DISPOSABLE_PATTERN ]]; then
    fail "expected '$name' ($desc) to match the disposable pattern, but it did not"
  fi
  pass "'$name' ($desc) matches the disposable pattern"
}

assert_rejects() {
  local name="$1" desc="$2"
  if [[ "$name" =~ $RLS_DISPOSABLE_PATTERN ]]; then
    fail "expected '$name' ($desc) to be REJECTED by the disposable pattern, but it matched"
  fi
  pass "'$name' ($desc) correctly rejected by the disposable pattern"
}

echo "=== 1. the corrected workflow's generation formula produces a valid, matching name ==="
# Mirrors schema-tenant-firewall.yml's corrected provisioning step exactly:
# purpose = "ci${GITHUB_RUN_ID}${GITHUB_RUN_ATTEMPT}" (concatenated, no
# separator), then create-disposable-db.sh appends its own single-token
# run_id ($(date +%s)$(( RANDOM % 1000 )), also separator-free) after the
# one permitted underscore.
RUN_ID="29382546444"
RUN_ATTEMPT="1"
purpose="ci${RUN_ID}${RUN_ATTEMPT}"
if [[ ! "$purpose" =~ ^[a-z0-9]+$ ]]; then
  fail "purpose '$purpose' is not a valid create-disposable-db.sh purpose (must match ^[a-z0-9]+\$)"
fi
synthetic_run_id="$(date +%s)$(( RANDOM % 1000 ))"
corrected_name="firmsbase_test_39a3l_disposable_${purpose}_${synthetic_run_id}"
assert_matches "$corrected_name" "corrected CI-generated name"

echo "=== 2. rerun-attempt uniqueness: a different GITHUB_RUN_ATTEMPT yields a different, still-valid name ==="
purpose_attempt2="ci${RUN_ID}2"
name_attempt2="firmsbase_test_39a3l_disposable_${purpose_attempt2}_${synthetic_run_id}"
if [[ "$corrected_name" == "$name_attempt2" ]]; then
  fail "rerun-attempt 1 and rerun-attempt 2 produced the identical database name"
fi
assert_matches "$name_attempt2" "rerun-attempt-2 CI-generated name"

echo "=== 3. the original, buggy name (extra underscore joining run id and attempt) is correctly rejected ==="
assert_rejects "firmsbase_test_39a3l_disposable_ci_29382546444_1" "original buggy CI database name"

echo "=== 4. persistent/non-disposable names are rejected by the pattern, and separately by the blocklist ==="
assert_rejects "firmsbase_test" "persistent shared test database"
assert_rejects "firmsbase" "primary application database"
assert_rejects "firmsbase_test_39a3l_template_1234567" "immutable template (wrong pattern for a disposable check)"

if ( rls_reject_if_blocklisted "firmsbase_test" ) 2>/dev/null; then
  fail "rls_reject_if_blocklisted incorrectly allowed a blocklisted persistent database name"
fi
pass "rls_reject_if_blocklisted correctly refuses a blocklisted persistent database name"

echo
echo "ALL DISPOSABLE-NAME-PATTERN REGRESSION CHECKS PASSED"
