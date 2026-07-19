<?php

namespace Tests\Feature\Deployment\Fleet;

use App\Enums\DeploymentMode;
use App\Enums\FleetMigrationInstanceStatus as InstanceStatus;
use App\Enums\FleetMigrationRunStatus;
use App\Models\User;
use App\Services\FleetMigrationOrchestrationService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Deployment\Concerns\SetsUpDeploymentFirm;
use Tests\TestCase;

/**
 * fleet_migration_instance_status now carries FORCE ROW LEVEL SECURITY
 * (Wave 9, see database/migrations/2026_08_29_970005_prepare_row_level_
 * security_and_force_rls_on_fleet_migration_instance_status_table.php).
 * Every raw assertDatabaseHas()/assertDatabaseMissing() call against
 * this table below is now wrapped in the relevant firm's own ambient
 * context, since those helpers issue a plain DB query subject to the
 * same policy as any other read — the orchestration service's own
 * writer-side wraps (already exercised above each assertion) always
 * restore context to "none" once they return.
 */
class FleetMigrationOrchestrationServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpDeploymentFirm;

    private FleetMigrationOrchestrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FleetMigrationOrchestrationService::class);
    }

    public function test_creating_a_run_creates_a_pending_instance_for_every_dedicated_or_private_firm(): void
    {
        $dedicated = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $private = $this->makeDeploymentFirm(DeploymentMode::PrivateEnterprise);
        $saas = $this->makeDeploymentFirm(DeploymentMode::Saas);
        $initiator = User::factory()->create();

        $run = $this->service->createRun('2026_08_01_000000_example', $initiator);

        $tenantContext = new TenantContextService();
        $tenantContext->runWithFirmContext($dedicated, fn () => $this->assertDatabaseHas('fleet_migration_instance_status', ['fleet_migration_run_id' => $run->id, 'firm_id' => $dedicated->id, 'status' => InstanceStatus::Pending->value]));
        $tenantContext->runWithFirmContext($private, fn () => $this->assertDatabaseHas('fleet_migration_instance_status', ['fleet_migration_run_id' => $run->id, 'firm_id' => $private->id, 'status' => InstanceStatus::Pending->value]));
        $tenantContext->runWithFirmContext($saas, fn () => $this->assertDatabaseMissing('fleet_migration_instance_status', ['fleet_migration_run_id' => $run->id, 'firm_id' => $saas->id]));
    }

    public function test_one_failure_halts_the_run_and_skips_remaining_pending_instances(): void
    {
        $firmA = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmB = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmC = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $initiator = User::factory()->create();

        $run = $this->service->createRun('2026_08_01_000001_example', $initiator);
        $run = $this->service->begin($run);

        $this->service->applyInstance($run, $firmA, true);
        $this->service->applyInstance($run, $firmB, false, 'simulated failure');

        $run = $run->fresh();

        $this->assertSame(FleetMigrationRunStatus::Halted, $run->status);
        $tenantContext = new TenantContextService();
        $tenantContext->runWithFirmContext($firmA, fn () => $this->assertDatabaseHas('fleet_migration_instance_status', ['fleet_migration_run_id' => $run->id, 'firm_id' => $firmA->id, 'status' => InstanceStatus::Applied->value]));
        $tenantContext->runWithFirmContext($firmB, fn () => $this->assertDatabaseHas('fleet_migration_instance_status', ['fleet_migration_run_id' => $run->id, 'firm_id' => $firmB->id, 'status' => InstanceStatus::Failed->value]));
        $tenantContext->runWithFirmContext($firmC, fn () => $this->assertDatabaseHas('fleet_migration_instance_status', ['fleet_migration_run_id' => $run->id, 'firm_id' => $firmC->id, 'status' => InstanceStatus::Skipped->value]));
    }

    public function test_rollback_marks_already_applied_instances_rolled_back(): void
    {
        $firmA = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmB = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $initiator = User::factory()->create();

        $run = $this->service->createRun('2026_08_01_000002_example', $initiator);
        $run = $this->service->begin($run);

        $this->service->applyInstance($run, $firmA, true);
        $this->service->applyInstance($run, $firmB, false, 'simulated failure');

        $run = $this->service->rollback($run->fresh());

        $this->assertSame(FleetMigrationRunStatus::RolledBack, $run->status);
        $tenantContext = new TenantContextService();
        $tenantContext->runWithFirmContext($firmA, fn () => $this->assertDatabaseHas('fleet_migration_instance_status', ['fleet_migration_run_id' => $run->id, 'firm_id' => $firmA->id, 'status' => InstanceStatus::RolledBack->value]));
        // firmB was Failed, not Applied — rollback never touches a
        // Failed row.
        $tenantContext->runWithFirmContext($firmB, fn () => $this->assertDatabaseHas('fleet_migration_instance_status', ['fleet_migration_run_id' => $run->id, 'firm_id' => $firmB->id, 'status' => InstanceStatus::Failed->value]));
    }

    public function test_a_fully_successful_run_can_complete(): void
    {
        $firmA = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $initiator = User::factory()->create();

        $run = $this->service->createRun('2026_08_01_000003_example', $initiator);
        $run = $this->service->begin($run);
        $this->service->applyInstance($run, $firmA, true);

        $run = $this->service->complete($run->fresh());

        $this->assertSame(FleetMigrationRunStatus::Completed, $run->status);
        $this->assertNotNull($run->completed_at);
    }

    public function test_summarize_reports_accurate_per_status_counts(): void
    {
        $firmA = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmB = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $firmC = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $initiator = User::factory()->create();

        $run = $this->service->createRun('2026_08_01_000004_example', $initiator);
        $run = $this->service->begin($run);
        $this->service->applyInstance($run, $firmA, true);
        $this->service->applyInstance($run, $firmB, false, 'boom');

        $summary = $this->service->summarize($run->fresh());

        $this->assertSame(1, $summary->appliedCount);
        $this->assertSame(1, $summary->failedCount);
        $this->assertSame(1, $summary->skippedCount);
        $this->assertSame(3, $summary->totalInstances());
    }

    public function test_no_real_process_migration_or_server_action_ever_happens(): void
    {
        // Comments/docblocks may legitimately explain what NOT to do
        // (e.g. "never calls Artisan::call()") without that prose
        // counting as the forbidden pattern itself — only executable
        // code is checked here.
        $source = $this->stripComments(file_get_contents(app_path('Services/FleetMigrationOrchestrationService.php')));

        foreach (['Artisan::call', 'exec(', 'shell_exec(', 'proc_open(', 'popen(', 'passthru(', 'system(', 'Process::'] as $needle) {
            $this->assertStringNotContainsString($needle, $source, "FleetMigrationOrchestrationService must not reference: {$needle}");
        }
    }

    /**
     * Strips PHP comments (// # and block/doc comments) via the real
     * tokenizer so forbidden-token checks only ever see executable
     * code — a token merely mentioned in prose must never fail this
     * test.
     */
    private function stripComments(string $source): string
    {
        $stripped = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $stripped .= is_array($token) ? $token[1] : $token;
        }

        return $stripped;
    }
}
