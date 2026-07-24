<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\ExportJobStatus;
use App\Enums\ExportType;
use App\Models\ExportJob;
use App\Models\Firm;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ExportJobsForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for export_jobs (database/migrations/
 * 2026_08_29_970001_prepare_row_level_security_and_force_rls_on_export_jobs_table.php)
 * is permanently active and behaves correctly.
 *
 * First of the six-table, one-batch Section 39A-9 Wave 9 activation
 * (migration/export domain: export_jobs, migration_projects,
 * import_batches, implementation_projects,
 * fleet_migration_instance_status, offboarding_requests). ExportJob
 * uses BelongsToTenant + HasPublicUuid — a genuine tenant-owned row,
 * fully mutable via ExportJobService.
 */
class ExportJobsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_29_970001_prepare_row_level_security_and_force_rls_on_export_jobs_table.php';

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

    public function test_export_jobs_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('export_jobs', $coverage->forcedTables());
    }

    public function test_export_jobs_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'export_jobs'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_export_jobs_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'export_jobs'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'export_jobs must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.');
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'export_jobs'::regclass and polname = 'export_jobs_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The export_jobs_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_export_jobs(): void
    {
        $firm = Firm::factory()->create();
        $this->createJobForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, ExportJob::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_export_jobs(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('export_jobs')->insert($this->rowAttributes($firm));
    }

    /**
     * ExportJobFactory DID gain a context-hold create() override in
     * this batch — its bare default-creation path is already
     * tenant-consistent, so a bare ExportJob::factory()->create() must
     * now SUCCEED even with no ambient context.
     */
    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $job = ExportJob::factory()->create();

        $this->assertNotNull($job->id);
        $this->assertNotNull($job->firm_id);

        $persisted = $this->runWithFirmContext(
            $job->firm_id,
            fn () => ExportJob::query()->find($job->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($job->firm_id, $persisted->firm_id);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_export_job(): void
    {
        $firmA = Firm::factory()->create();
        $jobA = $this->createJobForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ExportJob::query()->pluck('id')->all(),
        );

        $this->assertSame([$jobA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_export_job(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createJobForFirm($firmA);
        $jobB = $this->createJobForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ExportJob::query()->pluck('id')->all(),
        );

        $this->assertNotContains($jobB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_export_job(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('export_jobs')->insertGetId($this->rowAttributes($firmA)),
        );

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_export_job(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $jobB = $this->createJobForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($jobB) {
            return DB::table('export_jobs')->where('id', $jobB->id)->update(['status' => ExportJobStatus::Failed->value]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s export_jobs row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ExportJob::query()->find($jobB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(ExportJobStatus::Requested, $reReadAsFirmB->status);
    }

    public function test_firm_a_cannot_delete_firm_b_export_job(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $jobB = $this->createJobForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($jobB) {
            DB::table('export_jobs')->where('id', $jobB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ExportJob::query()->find($jobB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B export_jobs.');
    }

    public function test_firm_a_cannot_insert_an_export_job_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('export_jobs')->insert($this->rowAttributes($firmB));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $jobA = $this->createJobForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($jobA, $firmB) {
            DB::table('export_jobs')->where('id', $jobA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->createJobForFirm($firm);

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
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'export_jobs'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'export_jobs'::regclass and polname = 'export_jobs_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'export_jobs'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }

    public function test_migration_round_trip_affects_only_export_jobs(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice($coverage->preparedTables(), 0, 5);

        $before = [];
        foreach ($otherTables as $table) {
            $before[$table] = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
        }

        $migration = require base_path(self::MIGRATION_PATH);
        $migration->down();
        $migration->up();

        foreach ($otherTables as $table) {
            $after = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertEquals($before[$table], $after, "{$table}'s RLS state must be unaffected by export_jobs' own migration round trip.");
        }
    }

    // ---------------------------------------------------------------
    // Scope proofs
    // ---------------------------------------------------------------

    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $thisBatch = [
            'export_jobs', 'migration_projects', 'import_batches',
            'implementation_projects', 'fleet_migration_instance_status',
            'offboarding_requests',
        ];

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

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This checkpoint must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    private function createJobForFirm(Firm $firm): ExportJob
    {
        return $this->runWithFirmContext($firm, fn () => ExportJob::factory()->create(['firm_id' => $firm->id]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'export_type' => ExportType::Clients->value,
            'status' => ExportJobStatus::Requested->value,
            'legal_hold_checked' => true,
            'retention_checked' => true,
            'offboarding_checked' => true,
            'created_at' => now(),
            'updated_at' => now(),
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
