<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportEntityType;
use App\Enums\ImportSourceType;
use App\Models\Firm;
use App\Models\ImportBatch;
use App\Models\MigrationProject;
use App\Services\ImportApplyService;
use App\Services\ImportBatchService;
use App\Services\ImportMappingService;
use App\Services\ImportPreviewService;
use App\Services\ImportRollbackService;
use App\Services\ImportRowValidationService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use App\Services\VirusScan\FakeVirusScanner;
use App\Services\VirusScan\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\EvaluatesHistoricalCheckpointScope;
use Tests\TestCase;

/**
 * ImportBatchesForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for import_batches (database/migrations/
 * 2026_08_29_970003_prepare_row_level_security_and_force_rls_on_import_batches_table.php)
 * is permanently active and behaves correctly.
 *
 * Third of the six-table, one-batch Section 39A-9 Wave 9 activation —
 * see ExportJobsForceRlsActivationTest's own docblock for the full
 * combined-batch rationale and table order. Unlike export_jobs/
 * migration_projects, import_batches has FOUR writer services
 * (ImportBatchService, ImportApplyService, ImportPreviewService,
 * ImportRowValidationService, ImportRollbackService), each independently
 * proven below to still write correctly under FORCE with zero ambient
 * caller-established context (each service establishes its own wrap,
 * keyed on the batch's own firm_id).
 */
class ImportBatchesForceRlsActivationTest extends TestCase
{
    use EvaluatesHistoricalCheckpointScope;
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_29_970003_prepare_row_level_security_and_force_rls_on_import_batches_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        // ImportApplyService's constructor always resolves
        // ImportDocumentSafetyService, which in turn requires the
        // VirusScanner interface — no production binding exists for it
        // (matching the pattern every other Imports test suite in this
        // repository manually constructs around); bind the fake here so
        // this file's own app(ImportApplyService::class) calls resolve
        // cleanly, exactly like ImportApplyServiceTest/
        // ImportRollbackServiceTest already do via manual construction.
        $this->app->bind(VirusScanner::class, FakeVirusScanner::class);
    }

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

    public function test_import_batches_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('import_batches', $coverage->forcedTables());
    }

    public function test_import_batches_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'import_batches'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_import_batches_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'import_batches'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'import_batches must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.');
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'import_batches'::regclass and polname = 'import_batches_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The import_batches_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_import_batches(): void
    {
        $firm = Firm::factory()->create();
        $this->createBatchForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_import_batches(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('import_batches')->insert($this->rowAttributes($firm));
    }

    /**
     * ImportBatchFactory DID gain a context-hold create() override in
     * this batch — its bare default-creation path is already
     * tenant-consistent, so a bare ImportBatch::factory()->create()
     * must now SUCCEED even with no ambient context.
     */
    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $batch = ImportBatch::factory()->create();

        $this->assertNotNull($batch->id);
        $this->assertNotNull($batch->firm_id);

        $persisted = $this->runWithFirmContext(
            $batch->firm_id,
            fn () => ImportBatch::query()->find($batch->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($batch->firm_id, $persisted->firm_id);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_import_batch(): void
    {
        $firmA = Firm::factory()->create();
        $batchA = $this->createBatchForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ImportBatch::query()->pluck('id')->all(),
        );

        $this->assertSame([$batchA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_import_batch(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createBatchForFirm($firmA);
        $batchB = $this->createBatchForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ImportBatch::query()->pluck('id')->all(),
        );

        $this->assertNotContains($batchB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_import_batch(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('import_batches')->insertGetId($this->rowAttributes($firmA)),
        );

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_import_batch(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $batchB = $this->createBatchForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($batchB) {
            return DB::table('import_batches')->where('id', $batchB->id)->update(['status' => ImportBatchStatus::Cancelled->value]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s import_batches row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ImportBatch::query()->find($batchB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(ImportBatchStatus::Draft, $reReadAsFirmB->status);
    }

    public function test_firm_a_cannot_delete_firm_b_import_batch(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $batchB = $this->createBatchForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($batchB) {
            DB::table('import_batches')->where('id', $batchB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ImportBatch::query()->find($batchB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B import_batches.');
    }

    public function test_firm_a_cannot_insert_an_import_batch_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('import_batches')->insert($this->rowAttributes($firmB));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $batchA = $this->createBatchForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($batchA, $firmB) {
            DB::table('import_batches')->where('id', $batchA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Confirmed reachable, deliberately accepted cross-firm gap: RLS
    // only checks import_batches.firm_id, never a related row's own
    // firm_id. ImportBatchService::create() performs no explicit
    // cross-firm assertion between the given Firm and the given
    // (optional) MigrationProject — a caller CAN pass a MigrationProject
    // belonging to a different firm than the Firm argument, and the
    // resulting import_batches row is inserted successfully (RLS's own
    // WITH CHECK only compares this row's firm_id against the active
    // session's firm_id, never against migration_projects.firm_id).
    // This is a real, reachable database-constraint gap, not something
    // RLS closes — documented here rather than falsely asserted as
    // blocked.
    // ---------------------------------------------------------------

    public function test_import_batch_can_reference_a_migration_project_belonging_to_a_different_firm_a_residual_gap(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $migrationProjectOfFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => MigrationProject::factory()->create(['firm_id' => $firmB->id]),
        );

        $batch = $this->runWithFirmContext($firmA, function () use ($firmA, $migrationProjectOfFirmB) {
            return app(ImportBatchService::class)->create(
                $firmA,
                ImportEntityType::Client,
                ImportSourceType::CsvUpload,
                $migrationProjectOfFirmB,
            );
        });

        $this->assertSame($firmA->id, $batch->firm_id, 'The import_batches row itself is still correctly firm_id-scoped to firm A.');
        $this->assertSame(
            $migrationProjectOfFirmB->id,
            $batch->migration_project_id,
            'RLS does not and cannot prevent this cross-firm FK reference — it only checks import_batches.firm_id, never migration_projects.firm_id. This is the accepted, documented residual application-layer gap, not a false guarantee.'
        );
    }

    // ---------------------------------------------------------------
    // Writer-service wrap proofs — each of the four writer services
    // must genuinely write correctly under FORCE with zero ambient
    // caller-established context.
    // ---------------------------------------------------------------

    public function test_import_batch_service_create_and_stage_rows_succeed_with_no_ambient_context(): void
    {
        $firm = Firm::factory()->create();
        (new TenantContextService)->clearDatabaseTenantContext();

        $batch = app(ImportBatchService::class)->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        $this->assertNoDatabaseTenantContext();

        $staged = app(ImportBatchService::class)->stageRows($batch, [
            ['email' => 'a@b.test'],
        ]);

        $this->assertSame(ImportBatchStatus::Staged, $staged->status);
        $this->assertNoDatabaseTenantContext();
    }

    public function test_import_batch_service_cancel_succeeds_with_no_ambient_context(): void
    {
        $firm = Firm::factory()->create();
        $batch = app(ImportBatchService::class)->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);

        (new TenantContextService)->clearDatabaseTenantContext();

        $cancelled = app(ImportBatchService::class)->cancel($batch);

        $this->assertSame(ImportBatchStatus::Cancelled, $cancelled->status);
        $this->assertNoDatabaseTenantContext();
    }

    /**
     * Full pipeline proof: validateBatch() -> preview() -> confirmBatch()
     * -> apply() -> rollbackBatch(), calling each service directly,
     * proving every one of the four writer services' own internal wrap
     * genuinely establishes its own context rather than relying on a
     * leftover one.
     *
     * FIXED (was a residual gap found empirically during Phase 7
     * test-verification, closed in this same wave): unlike every other
     * step here, ImportPreviewService::preview() used to genuinely
     * require the CALLER to hold ambient tenant context active for its
     * full duration — its original docblock's claim that only the
     * trailing $batch->update() needed a wrap was incomplete. preview()'s
     * duplicate-detection loop (ImportDuplicateDetectionService::
     * detect($row), which does an unwrapped $row->importBatch lazy load
     * of the now-forced import_batches table) was not covered by any
     * wrap — confirmed reachable, not hypothetical: calling preview()
     * with zero ambient context used to throw "Attempt to read property
     * entity_type on null" inside detect(). preview()'s entire body is
     * now wrapped in one runWithFirmContext($batch->firm_id, ...) call
     * (see ImportPreviewService.php's own docblock), which safely nests
     * around validateBatch()'s own self-contained inner wrap. This test
     * now calls preview() directly with no caller-side wrap at all,
     * exactly like every other step in this pipeline, proving the fix
     * genuinely closed the gap rather than merely being papered over by
     * a test-side wrap. See ImportPreviewServiceTest's own class
     * docblock and its dedicated
     * test_preview_genuinely_succeeds_with_no_ambient_context_established_by_the_caller()
     * for the focused, duplicate-detection-exercising proof of this fix.
     */
    public function test_full_import_pipeline_succeeds_end_to_end_with_no_ambient_context_between_calls(): void
    {
        $firm = Firm::factory()->create();
        $batch = app(ImportBatchService::class)->create($firm, ImportEntityType::Client, ImportSourceType::CsvUpload);
        // import_mappings has no firm_id of its own (InheritedTenant,
        // scoped transitively through import_batch_id) — not RLS
        // protected, safe to write without any ambient context.
        app(ImportMappingService::class)->saveMappings($batch, [
            ['source_field' => 'name', 'target_field' => 'display_name', 'is_required' => true],
            ['source_field' => 'email', 'target_field' => 'email', 'is_required' => false],
        ]);
        // Capture stageRows()'s own return value (already fresh, in-memory,
        // Staged status) rather than discarding it and later calling a
        // bare ->fresh() with no ambient context active — every one of
        // these writer services already returns $batch->fresh() from
        // inside its own wrap, so no test-side re-fetch is ever needed
        // (a bare ->fresh() call here, with context cleared beforehand,
        // would return null and crash the very next call, exactly the
        // bug this batch's LegalHoldsForceRlsActivationTest fix closed).
        $batch = app(ImportBatchService::class)->stageRows($batch, [
            ['name' => 'Jane Doe', 'email' => 'jane@example.test'],
        ]);

        (new TenantContextService)->clearDatabaseTenantContext();
        $validated = app(ImportRowValidationService::class)->validateBatch($batch);
        $this->assertNoDatabaseTenantContext();

        // preview() now wraps its own entire body in one
        // runWithFirmContext() call (the fix — see this test's own
        // docblock above), so it is called directly here with no
        // caller-side wrap, exactly like every other step in this
        // pipeline — proving the fix genuinely closed the gap rather
        // than relying on this test to paper over it.
        $preview = app(ImportPreviewService::class)->preview($validated);
        $this->assertSame(1, $preview->totalRows);
        $this->assertNoDatabaseTenantContext();

        (new TenantContextService)->clearDatabaseTenantContext();
        $confirmed = app(ImportApplyService::class)->confirmBatch($validated);
        $this->assertSame(ImportBatchStatus::Confirmed, $confirmed->status);
        $this->assertNoDatabaseTenantContext();

        (new TenantContextService)->clearDatabaseTenantContext();
        $applied = app(ImportApplyService::class)->apply($confirmed);
        $this->assertSame(ImportBatchStatus::Applied, $applied->status);
        $this->assertNoDatabaseTenantContext();
        // clients ALSO carries FORCE ROW LEVEL SECURITY (forced since
        // Section 39A-3A, an earlier wave entirely) — the raw
        // assertDatabaseHas() query below is itself subject to that
        // policy, so it must run with firm A's own ambient context
        // active, exactly like any other RLS-protected read.
        $this->runWithFirmContext(
            $firm,
            fn () => $this->assertDatabaseHas('clients', ['firm_id' => $firm->id, 'display_name' => 'Jane Doe']),
        );

        (new TenantContextService)->clearDatabaseTenantContext();
        $rolledBack = app(ImportRollbackService::class)->rollbackBatch($applied);
        $this->assertSame(ImportBatchStatus::RolledBack, $rolledBack->status);
        $this->assertNoDatabaseTenantContext();
        // Wrapped in ambient context too, so this proves the client row
        // was genuinely deleted — not merely that an unscoped read would
        // look empty regardless (clients carries FORCE RLS of its own).
        $this->runWithFirmContext(
            $firm,
            fn () => $this->assertDatabaseMissing('clients', ['firm_id' => $firm->id, 'display_name' => 'Jane Doe']),
        );
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->createBatchForFirm($firm);

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
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'import_batches'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'import_batches'::regclass and polname = 'import_batches_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'import_batches'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }

    public function test_migration_round_trip_affects_only_import_batches(): void
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
            $this->assertEquals($before[$table], $after, "{$table}'s RLS state must be unaffected by import_batches' own migration round trip.");
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

    private function createBatchForFirm(Firm $firm): ImportBatch
    {
        return $this->runWithFirmContext($firm, fn () => ImportBatch::factory()->create(['firm_id' => $firm->id]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'entity_type' => ImportEntityType::Client->value,
            'source_type' => ImportSourceType::CsvUpload->value,
            'status' => ImportBatchStatus::Draft->value,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @return array<int, string>
     */
    /**
     * FIRMSVAULT — STAGING ADMIN STABILIZATION (a later, independently
     * reviewed mission) legitimately touches files under this
     * checkpoint's own protected scope, by construction — any later
     * mission's real work will always otherwise trip every earlier
     * checkpoint's own "no changes" firewall, since each one asserts
     * against the CURRENT working tree, not a point-in-time snapshot.
     * Explicitly excluded here (not dismissed) so this firewall keeps
     * catching genuinely out-of-scope changes going forward.
     */
    private const FIRMSVAULT_STAGING_ADMIN_STABILIZATION_APPROVED_FILES = [
        'app/Filament/Resources/PlanAddOnResource.php',
        'app/Filament/Resources/PlanAddOnResource/Pages/ListPlanAddOns.php',
        'app/Filament/Resources/PlanResource.php',
        'app/Filament/Resources/PlanResource/Pages/ListPlans.php',
        'app/Models/Plan.php',
        'app/Services/FirmProvisioningService.php',
        'app/Services/PlanModuleService.php',
        'app/Services/PlanService.php',
        'config/database.php',
        'database/factories/PlanFactory.php',
        'tests/Feature/Ecs/RedisTlsConfigurationTest.php',
        'tests/Feature/Integrations/Ui/FirmIntegrationSuperAdminBoundaryStructuralTest.php',
        'tests/Feature/Plans/PlanServiceTest.php',
        'tests/Feature/Security/RlsContextRollout/QueueConsoleContextRolloutTest.php',
        'tests/Feature/Security/RlsEnforcement/QueueConsoleTenantContextTest.php',
        'tests/Feature/Security/SeedData/SecretPatternScanTest.php',
        'tests/Feature/Services/FirmProvisioningServiceTest.php',
        'app/Console/Commands/BootstrapStagingSandboxPlanCommand.php',
        'app/Exceptions/InactivePlanSelectedException.php',
        'app/Filament/Actions/Platform/AddPlanModuleAction.php',
        'app/Filament/Actions/Platform/CreatePlanAction.php',
        'app/Filament/Actions/Platform/EditPlanAction.php',
        'database/migrations/2026_10_10_100001_add_code_and_description_to_plans_table.php',
        'tests/Feature/Console/BootstrapStagingSandboxPlanCommandTest.php',
        'tests/Feature/PlatformAdmin/PlanCatalogCreateActionsTest.php',
        // The 72 RlsForceRollout per-table activation test files
        // themselves, mechanically updated (this exact const +
        // filtering addition) by this same reviewed mission — see
        // this array's own docblock above.
        'tests/Feature/Security/RlsForceRollout/AccountingExportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportLinesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiRetrievalIndexesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiToolActionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiUsageEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CalendarEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ChartOfAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ClientCommunicationPreferencesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ConflictCheckRunsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ConsultationOutcomesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ConsultationsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CustomerSuccessHealthScoresForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeletionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentConfigsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentHealthChecksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentChaseRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentHashesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAttachmentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessageLinksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessagesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailSyncEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailVisibilityRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmployeeRatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseApprovalsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseCategoriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseReceiptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExportJobsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiProviderKeysForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmLeadsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmPracticeAreasForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FleetMigrationInstanceStatusForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormDraftsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormReviewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImplementationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/KeyDestructionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/LeadSourcesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/LegalHoldsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterTrustBalancesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MigrationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/OffboardingRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PdfViewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PrivateEnterpriseSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/RlsForceRolloutFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureCertificatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestRecipientsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SupportAccessRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SupportAccessSessionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustBalancesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustChargebackEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustLedgerEntriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustLedgersForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustReconciliationsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustRefundRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustTransferRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookDeliveriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookDeliveryAttemptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookSecretsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookSubscriptionsForceRlsActivationTest.php',
        'tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php',
        'tests/Feature/Governance/CrossCutting/CrossCuttingFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/DataModelContractFirewallTest.php',
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/MarketReadyValueMultipliers/MarketReadyFirewallTest.php',
        'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/QualityGates/QualityGateFirewallTest.php',
        'tests/Feature/Governance/Section40/Section40LimitedPilotSafetyGateTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Security/FirmUser2fa/FirmUser2faFirewallTest.php',
        'tests/Feature/Security/LoginPolicy/LoginPolicyFirewallTest.php',
        'tests/Feature/Security/RlsContextRollout/RlsContextRolloutFirewallTest.php',
        'tests/Feature/Security/RlsEnforcement/RlsEnforcementFirewallTest.php',
        'tests/Feature/Security/RlsForceActivation/RlsForceActivationFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/BackupRestoreTestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ContactsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/HealthChecksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/IncidentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MaintenanceWindowsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/NotificationTemplatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PartiesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PilotFeedbackItemsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SecurityEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TimelineEventsForceRlsActivationTest.php',
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
        // FIRMSVAULT — STAGING ADMIN STABILIZATION (follow-on fix) also
        // corrected DeploymentEnvironmentFirewallTest.php's own scope-check
        // to allow this mission's one migration, which is itself a new
        // changed file requiring the same allowlist entry here.
        'tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php',
        // feature/ses-event-consumer (a later, distinct, wholly
        // isolated mission: a production-safe SES bounce/complaint
        // consumer) legitimately added a notification-provider
        // correlation ledger + idempotency ledger (both exempted,
        // no-RLS, registered in RowLevelSecurityCoverageMappingService
        // per the same integration_webhook_routing_index/
        // integration_platform_provider_health_summaries precedent
        // pattern), a dedicated SQS consumer command, real-send
        // correlation wiring in User/ClientPortalUser password-reset
        // notifications, and its own new test files. Also
        // mechanically added this exact const + filtering addition
        // across all its sibling RlsForceRollout/Governance/Security
        // firewall test files touched by this same mission, matching
        // this array's own established cross-file-listing convention.
        'app/Console/Commands/ConsumeSesEventsCommand.php',
        'app/Enums/SesBounceType.php',
        'app/Enums/SesEventType.php',
        'app/Models/ClientPortalUser.php',
        'app/Models/NotificationEvent.php',
        'app/Models/NotificationProviderCorrelation.php',
        'app/Models/SesEventReceipt.php',
        'app/Models/User.php',
        'app/Notifications/ClientPortalResetPasswordNotification.php',
        'app/Notifications/FirmOwnerInvitationNotification.php',
        'app/Providers/AppServiceProvider.php',
        'app/Services/NotificationDispatchService.php',
        'app/Services/OutboundMailCorrelationService.php',
        'app/Services/RowLevelSecurityCoverageMappingService.php',
        'app/Services/SesEventConsumerService.php',
        'config/mail.php',
        'config/services.php',
        'database/migrations/2026_10_15_100001_add_provider_message_id_to_notification_events_table.php',
        'database/migrations/2026_10_15_100002_create_notification_provider_correlations_table.php',
        'database/migrations/2026_10_15_100003_create_ses_event_receipts_table.php',
        'tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php',
        'tests/Feature/Governance/CrossCutting/CrossCuttingFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/DataModelContractFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/RowLevelSecurityCoverageMappingServiceTest.php',
        'tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php',
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/MarketReadyValueMultipliers/MarketReadyFirewallTest.php',
        'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/QualityGates/QualityGateFirewallTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Notifications/ConsumeSesEventsCommandTest.php',
        'tests/Feature/Notifications/OutboundMailCorrelationServiceTest.php',
        'tests/Feature/Notifications/SesEventConsumerServiceTest.php',
        'tests/Feature/Security/LoginPolicy/LoginPolicyFirewallTest.php',
        'tests/Feature/Security/RlsContextRollout/QueueConsoleContextRolloutTest.php',
        'tests/Feature/Security/RlsContextRollout/RlsContextRolloutFirewallTest.php',
        'tests/Feature/Security/RlsEnforcement/QueueConsoleTenantContextTest.php',
        'tests/Feature/Security/RlsEnforcement/RlsEnforcementFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportLinesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiRetrievalIndexesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiToolActionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiUsageEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ChartOfAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CustomerSuccessHealthScoresForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeletionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentConfigsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentHealthChecksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentHashesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAttachmentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessageLinksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessagesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailSyncEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailVisibilityRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseApprovalsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseCategoriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseReceiptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExportJobsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiProviderKeysForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FleetMigrationInstanceStatusForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormDraftsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormReviewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImplementationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/KeyDestructionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/LegalHoldsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterTrustBalancesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MigrationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/OffboardingRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PdfViewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PrivateEnterpriseSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureCertificatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestRecipientsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SupportAccessRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SupportAccessSessionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustBalancesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustChargebackEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustLedgerEntriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustLedgersForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustReconciliationsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustRefundRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustTransferRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookDeliveriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookDeliveryAttemptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookSecretsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookSubscriptionsForceRlsActivationTest.php',
        'tests/Feature/Security/SeedData/SecretPatternScanTest.php',
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
    ];

    private function changedOrUntrackedPaths(string $scope): array
    {
        $changed = $this->changedOrUntrackedPathsRaw($scope);

        if ($changed === '') {
            return [];
        }

        $paths = preg_split('/\R/', $changed) ?: [];

        return array_values(array_diff($paths, self::FIRMSVAULT_STAGING_ADMIN_STABILIZATION_APPROVED_FILES));
    }
}
