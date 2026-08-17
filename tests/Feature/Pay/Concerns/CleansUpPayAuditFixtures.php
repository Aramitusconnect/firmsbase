<?php

declare(strict_types=1);

namespace Tests\Feature\Pay\Concerns;

use App\Services\Pay\PayAuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * CleansUpPayAuditFixtures — TEST-ONLY privileged fixture cleanup for
 * durable FirmsVault Pay audit rows.
 *
 * ============================================================
 * WHY A PRIVILEGED PATH IS REQUIRED HERE
 * ============================================================
 * `security_events` carries exactly two RLS policies:
 *
 *     security_events_platform_write    FOR INSERT
 *     security_events_tenant_isolation  FOR SELECT
 *
 * There is deliberately NO DELETE policy. Under FORCE ROW LEVEL
 * SECURITY a command with no permissive policy matches ZERO rows, so the
 * audit log is append-only for every role in every tenant context —
 * verified directly: under correct context the row is readable (count 1)
 * while the DELETE returns 0. That is an audit control and it must stay.
 *
 * Ordinary tenant-context cleanup is therefore impossible, and the two
 * non-privileged dispositions both break the suite:
 *   - delete the firm  -> ON DELETE SET NULL orphans the rows to
 *     firm_id = NULL, which makes them visible to every CONTEXTLESS
 *     reader (TenantAwareLoginPolicyTest, BelongsToTenantScopeTest);
 *   - keep the firm    -> retained firms break platform cross-firm
 *     aggregations (PlatformExecutiveDashboardServiceTest and friends).
 *
 * ============================================================
 * WHY THIS IS SAFE, AND WHY IT CAN NEVER MASK A PRODUCTION DEFECT
 * ============================================================
 * PRODUCTION NEVER DELETES A FIRM. Verified across the whole repository:
 * `firms` has no `deleted_at` column and no SoftDeletes trait; no
 * production code path calls delete() on a Firm (the only production
 * references to the `firms` table are read/count queries in
 * OperationsOverviewService and PlatformExecutiveDashboardService); and
 * the offboarding/deletion-governance domain completes a request by
 * updating its status, never by removing the tenant row.
 *
 * So orphaned audit attribution is a TEST-FIXTURE artifact that cannot
 * occur in production, and this cleanup cannot be hiding a real
 * production defect — there is no production path it corresponds to.
 *
 * SCOPE AND SAFETY OF THE PRIVILEGE:
 *   - it runs only in the disposable test database;
 *   - FORCE RLS is dropped and restored around a single DELETE, in a
 *     finally block, and the restoration is ASSERTED afterwards;
 *   - no policy is created, altered or dropped — the policy definitions
 *     and the migrations that own them are untouched;
 *   - the DELETE is scoped to the explicit firm ids the calling test
 *     created, so it can never touch another test's rows. It deliberately
 *     does NOT filter on category: a test may also create NON-Pay fixture
 *     events for its own firm — the durability test writes an
 *     `authentication` / `unrelated.probe` row to prove other categories
 *     are untouched — and leaving those behind orphans them to
 *     firm_id = NULL the instant the firm is deleted. That orphan was the
 *     exact producer of the 13472/13474 failure: it is visible to every
 *     contextless reader and can never be removed afterwards. Every row
 *     this deletes is a fixture the calling test created, for a firm it
 *     owns;
 *   - it must be called BEFORE the test deletes its own firm rows, which
 *     is the whole point: after that, firm_id is already NULL and the
 *     rows are no longer identifiable as this test's.
 */
trait CleansUpPayAuditFixtures
{
    /**
     * Remove ALL security_events this test created for its OWN firms.
     * MUST be called before the test deletes those firm rows — afterwards
     * firm_id is NULL and the rows are no longer identifiable as this
     * test's, nor removable at all.
     *
     * @param  list<int>  $firmIds
     */
    protected function purgeAuditFixturesForFirms(array $firmIds): void
    {
        $firmIds = array_values(array_filter($firmIds));

        if ($firmIds === []) {
            return;
        }

        // Table owner + no FORCE = owner bypasses RLS for this one
        // statement. Nothing about the policies themselves changes.
        DB::statement('ALTER TABLE security_events NO FORCE ROW LEVEL SECURITY');

        try {
            DB::table('security_events')
                ->whereIn('firm_id', $firmIds)
                ->delete();
        } finally {
            DB::statement('ALTER TABLE security_events FORCE ROW LEVEL SECURITY');
        }

        $forced = DB::selectOne(
            "select relforcerowsecurity from pg_class where relname = 'security_events'"
        );

        $this->assertTrue(
            (bool) $forced->relforcerowsecurity,
            'FORCE ROW LEVEL SECURITY must be restored on security_events immediately after test-only '
            .'fixture cleanup. Leaving it off would silently disable a tenant-isolation control for '
            .'every subsequent test in the run.'
        );
    }

    /**
     * Guard: no Pay audit row may be left orphaned to firm_id = NULL.
     * Read with NO tenant context deliberately — under the SELECT policy
     * that reader can only ever see NULL-firm rows, which is exactly the
     * residue that breaks unrelated contextless assertions.
     */
    protected function assertNoOrphanedPayAuditRows(): void
    {
        $orphaned = DB::table('security_events')
            ->where('category', PayAuditRecorder::CATEGORY)
            ->whereNull('firm_id')
            ->count();

        $this->assertSame(
            0,
            $orphaned,
            'Orphaned FirmsVault Pay audit rows (firm_id = NULL) are visible to every contextless reader '
            .'and can never be removed afterwards. Call purgeAuditFixturesForFirms() BEFORE deleting '
            .'the test firm.'
        );
    }
}
