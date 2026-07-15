#!/usr/bin/env bash
# Focused regression test for the mission-sentinel contract in lib.sh,
# create-disposable-db.sh, and destroy-disposable-db.sh — specifically the
# TCP admin-connection path (PGHOST set) used by the GitHub Actions CI
# workflow, since that is exactly the path the sentinel bug and its fix
# were about (rls_admin_psql falling back to peer auth when PGHOST is
# unset, and rls_verify_sentinel previously swallowing that connection
# failure as a misleading "no mission sentinel comment" error).
#
# IMPORTANT: RLS_TEST_ROLE (rls_test_runner_39a3l) is a real, shared,
# cluster-wide role that may already own live mission databases on this
# Postgres instance. This test NEVER creates, drops, or alters that role or
# its secret file — it only ever references the role by name as the OWNER
# of its own uniquely-named, self-cleaning throwaway databases (which does
# not require knowing the role's password and does not modify the role).
# The only role this test creates/drops is its own uniquely-named,
# fully-namespaced throwaway superuser connection identity.
#
# Self-contained and self-cleaning: always tears down every database and
# role it created (trap on EXIT) regardless of pass/fail. Safe to run
# against a shared Postgres cluster with other concurrent mission activity
# (uses the same mission-wide flock via rls_acquire_lock as the real
# create/destroy scripts).
#
# Requires: a reachable PostgreSQL server the invoking user can reach via
# `sudo -u postgres psql` (peer auth) to provision the throwaway TCP admin
# role, and via 127.0.0.1 TCP (scram-sha-256 or equivalent) to exercise the
# actual CI code path. Requires RLS_TEST_ROLE to already exist (it must, in
# any environment where the disposable-database tooling has been used at
# all before).
#
# Usage: tools/rls-test/tests/sentinel-lifecycle-test.sh

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
source ./lib.sh

RUN_TAG="$(date +%s)$(( RANDOM % 1000 ))"
TEST_ADMIN_ROLE="rls_sentineltest_admin_${RUN_TAG}"
TEST_ADMIN_PASSWORD="$(openssl rand -hex 24)"

PASS_DB=""
UNMARKED_DB=""
MISMATCH_DB=""
STDERR_CAPTURE="$(mktemp)"

cleanup() {
  set +e
  [[ -n "$PASS_DB" ]] && sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -c "DROP DATABASE IF EXISTS \"${PASS_DB}\";" >/dev/null 2>&1
  [[ -n "$UNMARKED_DB" ]] && sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -c "DROP DATABASE IF EXISTS \"${UNMARKED_DB}\";" >/dev/null 2>&1
  [[ -n "$MISMATCH_DB" ]] && sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -c "DROP DATABASE IF EXISTS \"${MISMATCH_DB}\";" >/dev/null 2>&1
  # Only ever drops the throwaway admin role this test itself created —
  # never RLS_TEST_ROLE, which is a real shared role this test must not
  # touch.
  sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -c "DROP ROLE IF EXISTS \"${TEST_ADMIN_ROLE}\";" >/dev/null 2>&1
  rm -f "$STDERR_CAPTURE"
}
trap cleanup EXIT

fail() { echo "SENTINEL TEST FAILED: $*" >&2; exit 1; }
pass() { echo "OK: $*"; }

if ! sudo -u postgres psql -X -Atc "SELECT 1 FROM pg_roles WHERE rolname = '${RLS_TEST_ROLE}';" | grep -q 1; then
  fail "RLS_TEST_ROLE ('${RLS_TEST_ROLE}') does not exist on this Postgres instance — this test only references it as an existing database owner and will not create it (it must already exist from prior mission use)"
fi

echo "=== setting up a throwaway TCP-capable admin role (mimics CI's postgres superuser) — RLS_TEST_ROLE itself is never touched ==="
sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -c \
  "CREATE ROLE \"${TEST_ADMIN_ROLE}\" LOGIN SUPERUSER PASSWORD '${TEST_ADMIN_PASSWORD}';"

export PGHOST=127.0.0.1
export PGPORT=5432
export PGUSER="${TEST_ADMIN_ROLE}"
export PGPASSWORD="${TEST_ADMIN_PASSWORD}"
# libpq defaults to a database named after PGUSER when none is given; the CI
# workflow's postgres service container avoids this because POSTGRES_DB is
# set to match POSTGRES_USER ("postgres"). This throwaway role has no
# same-named database, so point explicitly at the always-present "postgres"
# administrative database instead.
export PGDATABASE=postgres

current_head="$(cd "$RLS_WORKTREE_ROOT" && git rev-parse HEAD)"

echo "=== 1. a newly provisioned database receives the exact sentinel ==="
PASS_DB="$(./create-disposable-db.sh selftest)"
comment="$(rls_admin_psql -Atc "SELECT shobj_description(oid, 'pg_database') FROM pg_database WHERE datname = '${PASS_DB}';")"
[[ -n "$comment" ]] || fail "no sentinel comment found on newly provisioned '${PASS_DB}'"
[[ "$comment" == *"mission=section-39a3l;"* ]] || fail "sentinel missing correct mission field: ${comment}"
[[ "$comment" == *"purpose=selftest;"* ]] || fail "sentinel missing correct purpose field: ${comment}"
[[ "$comment" == *"head=${current_head};"* ]] || fail "sentinel head field does not match current HEAD: ${comment}"
pass "provisioned '${PASS_DB}' with sentinel: ${comment}"

echo "=== 2. rls_verify_sentinel (what run-artisan-test.sh calls pre-flight) accepts the correctly marked database ==="
if ! ( rls_verify_sentinel "$PASS_DB" ) 2>"$STDERR_CAPTURE"; then
  cat "$STDERR_CAPTURE" >&2
  fail "rls_verify_sentinel refused a correctly-sentineled database"
fi
pass "rls_verify_sentinel accepted '${PASS_DB}'"

echo "=== 3. an unmarked (no comment) database is refused ==="
UNMARKED_DB="firmsbase_test_39a3l_disposable_selftestunmk_${RUN_TAG}"
rls_admin_psql -c "CREATE DATABASE \"${UNMARKED_DB}\" OWNER ${RLS_TEST_ROLE};"
if ( rls_verify_sentinel "$UNMARKED_DB" ) 2>"$STDERR_CAPTURE"; then
  fail "rls_verify_sentinel incorrectly accepted an unmarked database"
fi
grep -q "no mission sentinel comment" "$STDERR_CAPTURE" || { cat "$STDERR_CAPTURE" >&2; fail "unexpected refusal reason for unmarked database"; }
pass "rls_verify_sentinel correctly refused an unmarked database"

echo "=== 4. a database with a near-match/incorrect sentinel is refused ==="
MISMATCH_DB="firmsbase_test_39a3l_disposable_selftestmis_${RUN_TAG}"
rls_admin_psql -c "CREATE DATABASE \"${MISMATCH_DB}\" OWNER ${RLS_TEST_ROLE};"
rls_admin_psql -c "COMMENT ON DATABASE \"${MISMATCH_DB}\" IS 'mission=section-39a3l-fake;purpose=selftest;head=0000000000000000000000000000000000000000;run_id=0;created=2000-01-01T00:00:00Z';"
if ( rls_verify_sentinel "$MISMATCH_DB" ) 2>"$STDERR_CAPTURE"; then
  fail "rls_verify_sentinel incorrectly accepted a mismatched-sentinel database"
fi
grep -q "sentinel mission mismatch" "$STDERR_CAPTURE" || { cat "$STDERR_CAPTURE" >&2; fail "unexpected refusal reason for mismatched database"; }
pass "rls_verify_sentinel correctly refused a mismatched-sentinel database"

echo "=== 5. destroy-disposable-db.sh only drops the exactly-marked database ==="
if ./destroy-disposable-db.sh "$UNMARKED_DB" 2>"$STDERR_CAPTURE"; then
  fail "destroy-disposable-db.sh incorrectly dropped an unmarked database"
fi
still_there="$(rls_admin_psql -Atc "SELECT 1 FROM pg_database WHERE datname = '${UNMARKED_DB}';")"
[[ "$still_there" == "1" ]] || fail "unmarked database was dropped despite the sentinel refusal"
pass "destroy-disposable-db.sh correctly refused to drop the unmarked database (it still exists)"

./destroy-disposable-db.sh "$PASS_DB"
gone="$(rls_admin_psql -Atc "SELECT 1 FROM pg_database WHERE datname = '${PASS_DB}';" || true)"
[[ -z "$gone" ]] || fail "correctly-sentineled database was not actually dropped"
pass "destroy-disposable-db.sh correctly dropped the correctly-sentineled database '${PASS_DB}'"
PASS_DB=""

echo "=== 6. rls_admin_psql attempts TCP (never a Unix socket) whenever PGHOST is set ==="
# Regression coverage for the exact CI failure this was written after: a
# workflow step that calls run-artisan-test.sh / rls_verify_sentinel
# without PGHOST/PGPORT/PGUSER/PGPASSWORD silently falls through
# rls_admin_psql's "else" branch (peer auth over the default Unix
# socket) instead of the TCP branch — which fails in a GitHub Actions
# Postgres service container with exactly the symptom seen in that run:
# "connection to server on socket ... failed" / "role \"root\" does not
# exist". Deliberately point PGHOST at a real host but a port nothing is
# listening on: if rls_admin_psql is genuinely using TCP, the failure
# must look like a TCP connection failure (mentions the host/port,
# "Connection refused"), never a Unix socket path.
BOGUS_TCP_PORT=1
if TCP_PROBE_ERR="$(PGHOST=127.0.0.1 PGPORT=$BOGUS_TCP_PORT PGUSER="$TEST_ADMIN_ROLE" PGPASSWORD="$TEST_ADMIN_PASSWORD" PGDATABASE=postgres rls_admin_psql -Atc 'SELECT 1;' 2>&1)"; then
  fail "expected a connection attempt to a bogus TCP port to fail, but rls_admin_psql reported success"
fi
if echo "$TCP_PROBE_ERR" | grep -q '\.s\.PGSQL\.'; then
  fail "rls_admin_psql attempted a Unix socket connection instead of TCP when PGHOST was set — this is exactly the regression this test guards against: ${TCP_PROBE_ERR}"
fi
if ! echo "$TCP_PROBE_ERR" | grep -qE '"127\.0\.0\.1".*port ('"$BOGUS_TCP_PORT"')|[Cc]onnection refused'; then
  fail "rls_admin_psql's connection failure did not look like a TCP attempt at all: ${TCP_PROBE_ERR}"
fi
pass "rls_admin_psql attempted TCP (never a Unix socket) when PGHOST was set — failure was: ${TCP_PROBE_ERR}"

echo
echo "ALL SENTINEL LIFECYCLE REGRESSION CHECKS PASSED"
