<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\DeploymentHealthReportMode;
use App\Enums\HealthCheckStatus;
use App\Models\DeploymentHealthCheck;
use App\Models\Firm;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DeploymentHealthChecksForceRlsActivationTest — proves the FORCE ROW
 * LEVEL SECURITY activation for deployment_health_checks (database/
 * migrations/2026_08_28_960006_prepare_row_level_security_and_force_rls_on_deployment_health_checks_table.php)
 * is permanently active and behaves correctly.
 *
 * Sixth and last of the six-table, one-batch Section 39A-8 Wave 8
 * activation — see LegalHoldsForceRlsActivationTest's own docblock for
 * the full combined-batch rationale. Kept last purely to preserve one
 * clean, combined-wave commit — this table has zero dependency on the
 * other five.
 *
 * deployment_health_checks is the ONE table in this batch with a
 * NULLABLE firm_id at the schema level (accepted, documented deviation
 * — every current writer always sets a real, non-null firm_id; the
 * standard non-null-style policy is applied anyway). It is also fully
 * append-only (booted() blocks both UPDATE and DELETE) — this file's
 * own dedicated section below proves that guard still works correctly
 * UNDER FORCE RLS, not merely that it worked before this batch.
 */
class DeploymentHealthChecksForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_28_960006_prepare_row_level_security_and_force_rls_on_deployment_health_checks_table.php';

    // ---------------------------------------------------------------
    // FORCE state / policy proofs
    // ---------------------------------------------------------------

    public function test_all_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->forcedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_deployment_health_checks_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('deployment_health_checks', $coverage->forcedTables());
    }

    /**
     * forcedTables() is derived dynamically from every
     * *_force_rls_on_*_table.php migration present in the repository —
     * so the exact count this WHOLE WAVE expects is itself exact and
     * reviewable: 86 tables forced through Section 39A-7 Wave 7, plus
     * this batch's own 6 (legal_holds, deletion_requests,
     * key_destruction_requests, support_access_requests,
     * support_access_sessions, deployment_health_checks) = 92, no more,
     * no fewer. This is the LAST table in the batch, so this
     * assertion's exact count is the final, authoritative check for
     * the whole wave.
     */
    public function test_the_forced_tables_registry_reports_exactly_ninety_two_tables_at_the_end_of_this_wave(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        // Narrowly updated AGAIN by Section 39A-5 Wave 9 (migration/export domain, 6 tables) — additive only, no existing assertion removed or weakened.
        $this->assertCount(
            98,
            $coverage->forcedTables(),
            'Exactly 98 tables must have FORCE ROW LEVEL SECURITY active after this entire Wave 9 batch lands — no more, no fewer.'
        );

        $expectedNewInThisWave = [
            'legal_holds', 'deletion_requests', 'key_destruction_requests',
            'support_access_requests', 'support_access_sessions', 'deployment_health_checks',
        ];

        foreach ($expectedNewInThisWave as $table) {
            $this->assertContains($table, $coverage->forcedTables(), "{$table} must be part of the forced-tables registry by the end of this wave.");
        }
    }

    public function test_deployment_health_checks_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'deployment_health_checks'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_deployment_health_checks_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'deployment_health_checks'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'deployment_health_checks must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'deployment_health_checks'::regclass and polname = 'deployment_health_checks_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The deployment_health_checks_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly — not a FOR INSERT-only clause.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_deployment_health_checks(): void
    {
        $firm = Firm::factory()->create();
        $this->createCheckForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, DeploymentHealthCheck::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_deployment_health_checks(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('deployment_health_checks')->insert($this->rowAttributes($firm));
    }

    /**
     * DeploymentHealthCheckFactory DID gain a context-hold create()
     * override in this batch — its bare default-creation path is
     * already tenant-consistent, so a bare
     * DeploymentHealthCheck::factory()->create() must now SUCCEED even
     * with no ambient context.
     */
    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $check = DeploymentHealthCheck::factory()->create();

        $this->assertNotNull($check->id);
        $this->assertNotNull($check->firm_id);

        $persisted = $this->runWithFirmContext(
            $check->firm_id,
            fn () => DeploymentHealthCheck::query()->find($check->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($check->firm_id, $persisted->firm_id);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_deployment_health_check(): void
    {
        $firmA = Firm::factory()->create();
        $checkA = $this->createCheckForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => DeploymentHealthCheck::query()->pluck('id')->all(),
        );

        $this->assertSame([$checkA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_deployment_health_check(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createCheckForFirm($firmA);
        $checkB = $this->createCheckForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => DeploymentHealthCheck::query()->pluck('id')->all(),
        );

        $this->assertNotContains($checkB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_deployment_health_check(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('deployment_health_checks')->insertGetId($this->rowAttributes($firmA)),
        );

        $this->assertIsInt($insertedId);
    }

    /**
     * booted() blocks UPDATE entirely (append-only) — RLS's own UPDATE
     * clause is therefore never reached in practice, but this test
     * still proves the cross-firm case using the raw DB facade (which
     * does NOT go through Eloquent's booted() hooks) to isolate what
     * RLS alone permits/denies at the database layer.
     */
    public function test_firm_a_cannot_update_firm_b_deployment_health_check_via_raw_query(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $checkB = $this->createCheckForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($checkB) {
            return DB::table('deployment_health_checks')->where('id', $checkB->id)->update(['status' => HealthCheckStatus::Degraded->value]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s deployment_health_checks row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => DeploymentHealthCheck::query()->find($checkB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(HealthCheckStatus::Healthy, $reReadAsFirmB->status);
    }

    public function test_firm_a_cannot_delete_firm_b_deployment_health_check_via_raw_query(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $checkB = $this->createCheckForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($checkB) {
            DB::table('deployment_health_checks')->where('id', $checkB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => DeploymentHealthCheck::query()->find($checkB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B deployment_health_checks.');
    }

    public function test_firm_a_cannot_insert_a_deployment_health_check_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('deployment_health_checks')->insert($this->rowAttributes($firmB));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $checkA = $this->createCheckForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($checkA, $firmB) {
            DB::table('deployment_health_checks')->where('id', $checkA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Required proof item: the append-only guard (booted() blocks both
    // update and delete) still works correctly UNDER FORCE RLS — not
    // merely that it worked before this batch.
    // ---------------------------------------------------------------

    public function test_append_only_guard_blocks_update_even_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $check = $this->createCheckForFirm($firm);

        $this->expectException(\LogicException::class);

        $this->runWithFirmContext($firm, function () use ($check) {
            $check->fresh()->update(['status' => HealthCheckStatus::Degraded]);
        });
    }

    public function test_append_only_guard_blocks_delete_even_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $check = $this->createCheckForFirm($firm);

        $this->expectException(\LogicException::class);

        $this->runWithFirmContext($firm, function () use ($check) {
            $check->fresh()->delete();
        });
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->createCheckForFirm($firm);

        $this->assertNoDatabaseTenantContext();
    }

    public function test_tenant_context_clears_after_exception(): void
    {
        $firm = Firm::factory()->create();

        try {
            $this->runWithFirmContext($firm, function () {
                throw new \RuntimeException('simulated failure inside firm context');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext();
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'deployment_health_checks'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'deployment_health_checks'::regclass and polname = 'deployment_health_checks_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'deployment_health_checks'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }

    public function test_migration_round_trip_affects_only_deployment_health_checks(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables[] = 'support_access_requests';
        $otherTables[] = 'support_access_sessions';

        $before = [];
        foreach ($otherTables as $table) {
            $before[$table] = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
        }

        $migration = require base_path(self::MIGRATION_PATH);
        $migration->down();
        $migration->up();

        foreach ($otherTables as $table) {
            $after = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertSame(
                (bool) $before[$table]->relrowsecurity,
                (bool) $after->relrowsecurity,
                "{$table}'s relrowsecurity must be unaffected by the deployment_health_checks migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the deployment_health_checks migration round trip."
            );
        }
    }

    // ---------------------------------------------------------------
    // Scope proofs
    // ---------------------------------------------------------------

    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $thisBatch = ['legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks'];

        foreach ($coverage->missingPreparedTables() as $table) {
            if (in_array($table, $thisBatch, true)) {
                continue;
            }

            $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);

            if ($row === null) {
                continue;
            }

            $this->assertFalse(
                (bool) $row->relrowsecurity,
                "{$table} was reported as missing RLS preparation, but RLS is now enabled — this checkpoint must not add policies for any other uncovered table."
            );
        }
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched by this checkpoint.');
    }

    public function test_row_level_security_coverage_mapping_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/RowLevelSecurityCoverageMappingService.php');

        $this->assertEmpty(
            $changed,
            'RowLevelSecurityCoverageMappingService.php must remain untouched by this individual checkpoint — the wave-integration update lands separately once this batch has landed.'
        );
    }

    public function test_gap_registry_doc_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('docs/governance/rls-gap-registry.md');

        $this->assertEmpty($changed, 'docs/governance/rls-gap-registry.md must remain untouched by this checkpoint — reserved for a later wave-integration commit.');
    }

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This checkpoint must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    private function createCheckForFirm(Firm $firm): DeploymentHealthCheck
    {
        return $this->runWithFirmContext($firm, fn () => DeploymentHealthCheck::factory()->create(['firm_id' => $firm->id]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm): array
    {
        return [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firm_id' => $firm->id,
            'heartbeat_at' => now(),
            'version' => '2026.7.0',
            'migration_status' => 'completed',
            'status' => HealthCheckStatus::Healthy->value,
            'reported_via' => DeploymentHealthReportMode::Live->value,
            'created_at' => now(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function changedOrUntrackedPaths(string $scope): array
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($scope)
        ));

        if ($changed === '') {
            return [];
        }

        return preg_split('/\R/', $changed) ?: [];
    }
}
