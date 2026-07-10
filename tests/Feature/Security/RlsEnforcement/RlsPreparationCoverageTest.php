<?php

namespace Tests\Feature\Security\RlsEnforcement;

use App\Services\RowLevelSecurityCoverageMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RlsPreparationCoverageTest — Section 39A. Confirms every already-
 * prepared tenant-owned table (RowLevelSecurityCoverageMappingService
 * ::preparedTables(), 52 tables across Phases 1-6) has RLS ENABLED
 * with a firm_id-matching policy, and that global/reference tables
 * (RowLevelSecurityCoverageMappingService::exemptTables()) remain
 * fully accessible — RLS was never applied to them, by design.
 *
 * Deliberately does NOT assert FORCE ROW LEVEL SECURITY here — this
 * section's approved scope leaves FORCE unset on the live schema (see
 * TenantContextService's docblock); RlsForceEnforcementTest proves the
 * FORCE mechanism itself works correctly using scoped, self-reverting
 * transactional tests instead.
 */
class RlsPreparationCoverageTest extends TestCase
{
    use RefreshDatabase;

    private RowLevelSecurityCoverageMappingService $coverage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coverage = new RowLevelSecurityCoverageMappingService();
    }

    public function test_every_prepared_table_has_row_level_security_enabled(): void
    {
        foreach ($this->coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relrowsecurity, "RLS is not enabled on prepared table {$table}.");
        }
    }

    public function test_every_prepared_table_has_a_firm_id_matching_tenant_isolation_policy(): void
    {
        foreach ($this->coverage->preparedTables() as $table) {
            // Queried as a set, not selectOne(), and asserted via "at
            // least one policy matches" rather than "the only policy
            // matches": firm_users legitimately carries a SECOND,
            // narrower, FOR SELECT-only policy (firm_users_self_lookup
            // — internal login/panel access wiring's bootstrap fix)
            // in addition to its original firm_id-matching policy.
            // Every other prepared table still has exactly one policy.
            $policies = DB::select(
                'select polname, pg_get_expr(polqual, polrelid) as using_expression '
                .'from pg_policy where polrelid = ?::regclass',
                [$table]
            );

            $this->assertNotEmpty($policies, "No RLS policy found on prepared table {$table}.");

            $hasFirmIdMatchingPolicy = collect($policies)->contains(
                fn ($policy) => str_contains($policy->using_expression, 'firm_id')
                    && str_contains($policy->using_expression, 'app.current_firm_id')
            );

            $this->assertTrue($hasFirmIdMatchingPolicy, "Table {$table} must have at least one policy referencing firm_id and app.current_firm_id.");
        }
    }

    public function test_exempt_global_reference_tables_remain_fully_accessible_without_rls(): void
    {
        foreach ($this->coverage->exemptTables() as $table) {
            $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertFalse(
                (bool) $row->relrowsecurity,
                "{$table} is intentionally global/reference/platform-level and must not have RLS enabled."
            );

            // Confirm it is genuinely queryable with no tenant context at all.
            $this->assertIsInt(DB::table($table)->count());
        }
    }

    public function test_missing_prepared_tables_are_honestly_tracked_as_a_known_residual_gap(): void
    {
        // Section 39A does not add new RLS policies for these 41
        // Phase-7+ tables — see the final report for why (writing 41
        // new per-domain policies in this pass would be exactly the
        // kind of broad, unreviewed guess this section was told to
        // avoid). This test proves the existing mapping service still
        // honestly reports them as missing, rather than this section
        // silently pretending they are covered.
        $missing = $this->coverage->missingPreparedTables();

        $this->assertNotEmpty($missing, 'The known Phase 7+ RLS coverage gap must remain honestly tracked.');

        foreach ($missing as $table) {
            $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);

            if ($row === null) {
                continue;
            }

            $this->assertFalse(
                (bool) $row->relrowsecurity,
                "{$table} was reported as missing RLS preparation, but RLS is enabled — the mapping service is now stale and must be updated, not silently left wrong."
            );
        }
    }
}
