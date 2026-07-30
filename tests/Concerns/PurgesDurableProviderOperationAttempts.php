<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * PurgesDurableProviderOperationAttempts — Checkpoint 8.2 (§A4/§A11).
 *
 * `provider_operation_attempts` is written on the independent
 * `pgsql_audit` session precisely so its rows survive the caller's
 * transaction rolling back — which means `RefreshDatabase` does not roll
 * them back either. Rows therefore outlive the test that created them and,
 * across test RUNS against the same database, could collide with a later
 * firm's logical operation key. A suite that exercises the gate purges the
 * table around itself.
 *
 * WHY THIS IS A TRAIT AND NOT A GLOBAL setUp() HOOK. It was a global hook
 * in `Tests\TestCase` first, and that was a mistake worth recording:
 * touching the shared audit connection in EVERY test's setUp() meant that
 * whenever an earlier test left that session in a failed transaction — a
 * routine occurrence, since audit writes for an uncommitted firm can fail
 * their foreign key — the very next test died in setUp() with "current
 * transaction is aborted", producing a cascade of failures in suites that
 * had nothing to do with any of this. Opting in per suite keeps the blast
 * radius where the behavior actually belongs.
 */
trait PurgesDurableProviderOperationAttempts
{
    protected function purgeDurableProviderOperationAttempts(): void
    {
        DB::connection('pgsql_audit')->table('provider_operation_attempts')->delete();
    }

    protected function setUpPurgesDurableProviderOperationAttempts(): void
    {
        $this->purgeDurableProviderOperationAttempts();
    }

    protected function tearDownPurgesDurableProviderOperationAttempts(): void
    {
        $this->purgeDurableProviderOperationAttempts();
    }
}
