#!/usr/bin/env bash
# The ONLY approved way to run `php artisan ...` (including `test`,
# `migrate`, `migrate:status`, etc.) against a mission disposable database.
# Validates the target database's name, blocklist status, and sentinel
# before running anything, builds the required environment explicitly, and
# never prints the database password. Intended for use by the test-writer/
# test-runner role (Phase B) — never requires or grants Postgres admin
# access, so it is safe to hand to a subagent's task prompt.
#
# Usage: run-artisan-test.sh <db_name> -- <artisan-subcommand> [args...]
# Example: run-artisan-test.sh firmsbase_test_39a3l_disposable_ckpt22_170099 -- test --filter=PaymentPlan

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./lib.sh

db_name="${1:?usage: run-artisan-test.sh <db_name> -- <artisan-subcommand> [args...]}"
shift

if [[ "${1:-}" != "--" ]]; then
  rls_fail "expected '--' separator before the artisan subcommand, got '${1:-<nothing>}'"
fi
shift

if [[ "$#" -eq 0 ]]; then
  rls_fail "no artisan subcommand given"
fi

rls_reject_if_blocklisted "$db_name"
rls_require_disposable_pattern "$db_name"
rls_verify_sentinel "$db_name"

# Item 10 root-cause fix — held for this entire script's execution (both
# the artisan invocation below AND the post-test self-heal), so a second
# concurrent invocation against the SAME database waits rather than
# racing it. See rls_acquire_test_run_lock()'s own docblock in lib.sh for
# the exact corruption this prevents.
rls_acquire_test_run_lock "$db_name"

rls_log "running: php artisan $* (database=${db_name}, role=${RLS_TEST_ROLE})"

cd "$RLS_WORKTREE_ROOT"

subcommand="$1"
test_password="$(rls_test_role_password)"

set +e
APP_ENV=testing \
DB_CONNECTION=pgsql \
DB_HOST="$RLS_TEST_HOST" \
DB_PORT="$RLS_TEST_PORT" \
DB_DATABASE="$db_name" \
DB_USERNAME="$RLS_TEST_ROLE" \
DB_PASSWORD="$test_password" \
php artisan "$@"
exit_code=$?
set -e

# Section 39A-3L Stage A root-cause correction (2026-07-12): any test class
# using the `DatabaseMigrations` trait (as opposed to `RefreshDatabase`) runs
# `migrate:fresh` before, and `migrate:rollback` after, EVERY test method —
# by design, per Illuminate\Foundation\Testing\DatabaseMigrations. If such a
# class is the last one PHPUnit executes in this process, that final
# `migrate:rollback` rolls back the entire single `migrate:fresh` batch and
# nothing subsequent re-migrates, leaving the database schema reduced to an
# empty `migrations` table — with no crash, no interruption, and no failing
# test required. This was proven to be the exact mechanism behind the
# 2026-07-12 shared-test-database wipe incident (see
# /home/ubuntu/firmsbase/rls-checkpoints/incidents/test-db-wipe-after-checkpoint-21/).
# Unconditionally re-migrating after every `test` invocation makes this
# disposable database's post-run state deterministic regardless of which
# trait the last-executed test class happened to use, without touching the
# DatabaseMigrations-based tests themselves (they still exercise real
# migrate:fresh/rollback cycles exactly as designed for DB::afterCommit()
# testing) and without masking a genuine test failure (the original test
# exit code is preserved and returned below).
if [[ "$subcommand" == "test" ]]; then
  rls_log "post-test schema self-heal: re-running migrate --force (idempotent if already fully migrated)"
  APP_ENV=testing \
  DB_CONNECTION=pgsql \
  DB_HOST="$RLS_TEST_HOST" \
  DB_PORT="$RLS_TEST_PORT" \
  DB_DATABASE="$db_name" \
  DB_USERNAME="$RLS_TEST_ROLE" \
  DB_PASSWORD="$test_password" \
  php artisan migrate --force >/dev/null
  rls_log "post-test self-heal complete"
fi

exit "$exit_code"
