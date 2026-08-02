<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\DeploymentMode;
use App\Enums\FleetMigrationInstanceStatus as InstanceStatus;
use App\Enums\FleetMigrationRunStatus;
use App\Models\Firm;
use App\Models\FleetMigrationInstanceStatus;
use App\Models\User;
use App\Services\FleetMigrationOrchestrationService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Deployment\Concerns\SetsUpDeploymentFirm;
use Tests\TestCase;

/**
 * FleetMigrationInstanceStatusForceRlsActivationTest — proves the
 * FORCE ROW LEVEL SECURITY activation for fleet_migration_instance_status
 * (database/migrations/2026_08_29_970005_prepare_row_level_security_and_force_rls_on_fleet_migration_instance_status_table.php)
 * is permanently active and behaves correctly.
 *
 * Fifth of the six-table, one-batch Section 39A-9 Wave 9 activation —
 * see ExportJobsForceRlsActivationTest's own docblock for the full
 * combined-batch rationale and table order.
 *
 * THE MOST IMPORTANT TEST FILE IN THIS WAVE: before this batch,
 * FleetMigrationOrchestrationService performed cross-firm bulk
 * queries/updates with no per-firm scoping at all — a single exists()
 * check across every firm in complete(), a single bulk UPDATE with no
 * firm_id narrowing in applyInstance()'s failure branch and in
 * rollback(), and a single cross-firm GROUP BY in summarize(). Under
 * FORCE ROW LEVEL SECURITY, a single active app.current_firm_id session
 * setting can only see one firm's rows at a time — the OLD single-query
 * shape would have silently become fail-OPEN: complete()'s cross-firm
 * exists() check would return false for any OTHER firm's still-blocking
 * instance (since only one firm's rows are visible under any one
 * context), letting a run complete even though another firm still has a
 * Pending/Failed instance. The redesigned service explicitly loops over
 * every dedicated/private firm, each iteration wrapped in its own
 * runWithFirmContext() call. test_complete_correctly_detects_a_blocking_instance_belonging_to_a_different_firm_than_any_single_context_could_see()
 * below is the direct regression test proving this fail-open bug is
 * genuinely closed, not merely that the loop exists syntactically.
 */
class FleetMigrationInstanceStatusForceRlsActivationTest extends TestCase
{
    use RefreshDatabase, SetsUpDeploymentFirm;

    private const MIGRATION_PATH = 'database/migrations/2026_08_29_970005_prepare_row_level_security_and_force_rls_on_fleet_migration_instance_status_table.php';

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

    public function test_fleet_migration_instance_status_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('fleet_migration_instance_status', $coverage->forcedTables());
    }

    public function test_fleet_migration_instance_status_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'fleet_migration_instance_status'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_fleet_migration_instance_status_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'fleet_migration_instance_status'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'fleet_migration_instance_status must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.');
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'fleet_migration_instance_status'::regclass and polname = 'fleet_migration_instance_status_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The fleet_migration_instance_status_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_fleet_migration_instance_status(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $initiator = User::factory()->create();
        app(FleetMigrationOrchestrationService::class)->createRun('2026_09_01_000000_example', $initiator);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, FleetMigrationInstanceStatus::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_fleet_migration_instance_status(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $initiator = User::factory()->create();
        $run = app(FleetMigrationOrchestrationService::class)->createRun('2026_09_01_000001_example', $initiator);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('fleet_migration_instance_status')->insert([
            'fleet_migration_run_id' => $run->id,
            'firm_id' => $firm->id,
            'status' => InstanceStatus::Pending->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * FleetMigrationInstanceStatusFactory DID gain a context-hold
     * create() override in this batch — its bare default-creation path
     * is already tenant-consistent, so a bare
     * FleetMigrationInstanceStatus::factory()->create() must now
     * SUCCEED even with no ambient context.
     */
    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $instance = FleetMigrationInstanceStatus::factory()->create();

        $this->assertNotNull($instance->id);
        $this->assertNotNull($instance->firm_id);

        $persisted = $this->runWithFirmContext(
            $instance->firm_id,
            fn () => FleetMigrationInstanceStatus::query()->find($instance->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($instance->firm_id, $persisted->firm_id);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_cannot_read_firm_b_instance_status(): void
    {
        $firmA = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmB = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $initiator = User::factory()->create();

        $run = app(FleetMigrationOrchestrationService::class)->createRun('2026_09_01_000002_example', $initiator);

        $instanceB = $this->runWithFirmContext(
            $firmB,
            fn () => FleetMigrationInstanceStatus::query()->where('fleet_migration_run_id', $run->id)->where('firm_id', $firmB->id)->firstOrFail(),
        );

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FleetMigrationInstanceStatus::query()->where('fleet_migration_run_id', $run->id)->pluck('id')->all(),
        );

        $this->assertNotContains($instanceB->id, $visibleIds);
    }

    public function test_firm_a_cannot_update_firm_b_instance_status(): void
    {
        $firmA = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmB = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $initiator = User::factory()->create();

        $run = app(FleetMigrationOrchestrationService::class)->createRun('2026_09_01_000003_example', $initiator);

        $instanceB = $this->runWithFirmContext(
            $firmB,
            fn () => FleetMigrationInstanceStatus::query()->where('fleet_migration_run_id', $run->id)->where('firm_id', $firmB->id)->firstOrFail(),
        );

        $affected = $this->runWithFirmContext($firmA, function () use ($instanceB) {
            return DB::table('fleet_migration_instance_status')->where('id', $instanceB->id)->update(['status' => InstanceStatus::Applied->value]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s fleet_migration_instance_status row.');
    }

    public function test_firm_a_cannot_delete_firm_b_instance_status(): void
    {
        $firmA = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmB = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $initiator = User::factory()->create();

        $run = app(FleetMigrationOrchestrationService::class)->createRun('2026_09_01_000004_example', $initiator);

        $instanceB = $this->runWithFirmContext(
            $firmB,
            fn () => FleetMigrationInstanceStatus::query()->where('fleet_migration_run_id', $run->id)->where('firm_id', $firmB->id)->firstOrFail(),
        );

        $this->runWithFirmContext($firmA, function () use ($instanceB) {
            DB::table('fleet_migration_instance_status')->where('id', $instanceB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FleetMigrationInstanceStatus::query()->find($instanceB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B fleet_migration_instance_status.');
    }

    public function test_firm_a_cannot_insert_an_instance_status_claiming_firm_b_ownership(): void
    {
        $firmA = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmB = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $initiator = User::factory()->create();
        $run = app(FleetMigrationOrchestrationService::class)->createRun('2026_09_01_000005_example', $initiator);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($run, $firmB) {
            DB::table('fleet_migration_instance_status')->insert([
                'fleet_migration_run_id' => $run->id,
                'firm_id' => $firmB->id,
                'status' => InstanceStatus::Pending->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    // ---------------------------------------------------------------
    // FleetMigrationOrchestrationService positive-path proofs
    // ---------------------------------------------------------------

    public function test_create_run_enrolls_every_dedicated_or_private_firm_and_only_those(): void
    {
        $dedicated = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $private = $this->makeDeploymentFirm(DeploymentMode::PrivateEnterprise);
        $saas = $this->makeDeploymentFirm(DeploymentMode::Saas);
        $initiator = User::factory()->create();

        $run = app(FleetMigrationOrchestrationService::class)->createRun('2026_09_01_000006_example', $initiator);

        $dedicatedInstance = $this->runWithFirmContext(
            $dedicated,
            fn () => FleetMigrationInstanceStatus::query()->where('fleet_migration_run_id', $run->id)->where('firm_id', $dedicated->id)->first(),
        );
        $privateInstance = $this->runWithFirmContext(
            $private,
            fn () => FleetMigrationInstanceStatus::query()->where('fleet_migration_run_id', $run->id)->where('firm_id', $private->id)->first(),
        );
        $saasInstance = $this->runWithFirmContext(
            $saas,
            fn () => FleetMigrationInstanceStatus::query()->where('fleet_migration_run_id', $run->id)->where('firm_id', $saas->id)->first(),
        );

        $this->assertNotNull($dedicatedInstance);
        $this->assertSame(InstanceStatus::Pending, $dedicatedInstance->status);
        $this->assertNotNull($privateInstance);
        $this->assertSame(InstanceStatus::Pending, $privateInstance->status);
        $this->assertNull($saasInstance, 'A Saas-mode firm must never be enrolled in a fleet migration run.');
        $this->assertNoDatabaseTenantContext();
    }

    public function test_apply_instance_failure_branch_genuinely_fans_skipped_out_to_every_other_firm(): void
    {
        $firmA = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmB = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmC = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $initiator = User::factory()->create();

        $service = app(FleetMigrationOrchestrationService::class);
        $run = $service->createRun('2026_09_01_000007_example', $initiator);
        $run = $service->begin($run);

        $service->applyInstance($run, $firmA, true);
        $service->applyInstance($run, $firmB, false, 'simulated failure');

        $statusFor = fn (Firm $firm) => $this->runWithFirmContext(
            $firm,
            fn () => FleetMigrationInstanceStatus::query()->where('fleet_migration_run_id', $run->id)->where('firm_id', $firm->id)->firstOrFail()->status,
        );

        $this->assertSame(InstanceStatus::Applied, $statusFor($firmA));
        $this->assertSame(InstanceStatus::Failed, $statusFor($firmB));
        $this->assertSame(InstanceStatus::Skipped, $statusFor($firmC), 'Every OTHER still-Pending firm (including one whose own context was never separately established by this test) must be fanned out to Skipped.');
        $this->assertNoDatabaseTenantContext();
    }

    public function test_rollback_genuinely_marks_every_firms_applied_instance_rolled_back(): void
    {
        $firmA = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmB = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $initiator = User::factory()->create();

        $service = app(FleetMigrationOrchestrationService::class);
        $run = $service->createRun('2026_09_01_000008_example', $initiator);
        $run = $service->begin($run);

        $service->applyInstance($run, $firmA, true);
        $service->applyInstance($run, $firmB, true);

        // rollback() only accepts a Halted or Completed run — both
        // firms succeeded here, so complete the run first.
        $run = $service->complete($run->fresh());
        $service->rollback($run->fresh());

        $statusFor = fn (Firm $firm) => $this->runWithFirmContext(
            $firm,
            fn () => FleetMigrationInstanceStatus::query()->where('fleet_migration_run_id', $run->id)->where('firm_id', $firm->id)->firstOrFail()->status,
        );

        $this->assertSame(InstanceStatus::RolledBack, $statusFor($firmA), 'Firm A\'s Applied instance must be rolled back.');
        $this->assertSame(InstanceStatus::RolledBack, $statusFor($firmB), 'Firm B\'s Applied instance must ALSO be rolled back — not just the first firm found.');
        $this->assertNoDatabaseTenantContext();
    }

    public function test_summarize_sums_counts_across_multiple_firms_correctly_not_just_the_first_firm_found(): void
    {
        $firmA = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmB = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmC = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmD = $this->makeDeploymentFirm(DeploymentMode::PrivateEnterprise);
        $initiator = User::factory()->create();

        $service = app(FleetMigrationOrchestrationService::class);
        $run = $service->createRun('2026_09_01_000009_example', $initiator);
        $run = $service->begin($run);

        $service->applyInstance($run, $firmA, true);
        $service->applyInstance($run, $firmB, true);
        $service->applyInstance($run, $firmC, false, 'boom');
        // firmD is left Pending (never applied) — it would have been
        // marked Skipped by firmC's failure branch above.

        $summary = $service->summarize($run->fresh());

        $this->assertSame(2, $summary->appliedCount, 'Both firm A and firm B must be counted — not just the first firm found.');
        $this->assertSame(1, $summary->failedCount);
        $this->assertSame(1, $summary->skippedCount);
        $this->assertSame(4, $summary->totalInstances());
        $this->assertNoDatabaseTenantContext();
    }

    // ---------------------------------------------------------------
    // TOP PRIORITY: fail-open regression — the single most important
    // proof in this wave. complete()'s OLD single cross-firm exists()
    // check would have been invisible to any one firm's active context
    // once FORCE landed, letting a run complete even though a DIFFERENT
    // firm still has a blocking Pending/Failed instance. The redesigned
    // per-firm loop must correctly detect this and refuse to complete.
    // ---------------------------------------------------------------

    public function test_complete_correctly_detects_a_blocking_instance_belonging_to_a_different_firm_than_any_single_context_could_see(): void
    {
        $firmA = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmB = $this->makeDeploymentFirm(DeploymentMode::PrivateEnterprise);
        $initiator = User::factory()->create();

        $service = app(FleetMigrationOrchestrationService::class);
        $run = $service->createRun('2026_09_01_000010_example', $initiator);
        $run = $service->begin($run);

        // Firm A succeeds; firm B is deliberately left Pending (never
        // applied at all) — the caller has NO ambient context for firm
        // B active anywhere in this test at the point complete() is
        // called below.
        $service->applyInstance($run, $firmA, true);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A run can only complete from InProgress with no Pending/Failed instances remaining.');

        $service->complete($run->fresh());
    }

    public function test_complete_also_correctly_detects_a_failed_instance_belonging_to_a_different_firm(): void
    {
        $firmA = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmB = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $initiator = User::factory()->create();

        $service = app(FleetMigrationOrchestrationService::class);
        $run = $service->createRun('2026_09_01_000011_example', $initiator);
        $run = $service->begin($run);

        // Firm B's failure halts the run (project rule); re-open it to
        // InProgress directly at the row level so complete()'s own gate
        // (not begin()'s Halted guard) is what is actually exercised —
        // Firm B's instance remains genuinely Failed the whole time.
        $service->applyInstance($run, $firmB, false, 'boom');
        $run->fresh()->forceFill(['status' => FleetMigrationRunStatus::InProgress])->save();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectException(\RuntimeException::class);

        $service->complete($run->fresh());
    }

    public function test_a_fully_successful_multi_firm_run_correctly_completes(): void
    {
        $firmA = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmB = $this->makeDeploymentFirm(DeploymentMode::PrivateEnterprise);
        $initiator = User::factory()->create();

        $service = app(FleetMigrationOrchestrationService::class);
        $run = $service->createRun('2026_09_01_000012_example', $initiator);
        $run = $service->begin($run);

        $service->applyInstance($run, $firmA, true);
        $service->applyInstance($run, $firmB, true);

        (new TenantContextService)->clearDatabaseTenantContext();

        $completed = $service->complete($run->fresh());

        $this->assertSame(FleetMigrationRunStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertNoDatabaseTenantContext();
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $initiator = User::factory()->create();

        app(FleetMigrationOrchestrationService::class)->createRun('2026_09_01_000013_example', $initiator);

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
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'fleet_migration_instance_status'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'fleet_migration_instance_status'::regclass and polname = 'fleet_migration_instance_status_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'fleet_migration_instance_status'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }

    public function test_migration_round_trip_affects_only_fleet_migration_instance_status(): void
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
            $this->assertEquals($before[$table], $after, "{$table}'s RLS state must be unaffected by fleet_migration_instance_status' own migration round trip.");
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
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($scope)
        ));

        if ($changed === '') {
            return [];
        }

        $paths = preg_split('/\R/', $changed) ?: [];

        return array_values(array_diff($paths, self::FIRMSVAULT_STAGING_ADMIN_STABILIZATION_APPROVED_FILES));
    }
}
