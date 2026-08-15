<?php

namespace Tests\Feature\Operations;

use App\Enums\DeploymentMode;
use App\Enums\FleetMigrationRunStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Resources\PlatformFleetMigrationRunResource;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\FleetMigrationOrchestrationService;
use App\Services\FleetMigrationSafetyService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operations Control Plane — fleet migration safety truth.
 *
 * The orchestrator is a rehearsal tool: outcomes are typed in by an
 * operator and nothing is ever migrated. That is fine, as long as
 * nobody can mistake a run record for a rollout. These tests hold the
 * labelling honest and keep the missing-control inventory accurate,
 * so the production-safe verdict stays derived rather than asserted.
 */
class FleetMigrationSafetyTruthTest extends TestCase
{
    use RefreshDatabase;

    private function safety(): FleetMigrationSafetyService
    {
        return app(FleetMigrationSafetyService::class);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    public function test_fleet_migration_is_not_production_safe_and_says_why(): void
    {
        $safety = $this->safety();

        $this->assertFalse($safety->isProductionSafe());
        $this->assertTrue($safety->isSimulationOnly());
        $this->assertNotEmpty($safety->missingControls());
    }

    public function test_the_missing_control_inventory_names_the_real_gaps(): void
    {
        $missing = collect($this->safety()->missingControls())->pluck('control');

        foreach ([
            'Real execution',
            'Target eligibility gating',
            'Preflight checks',
            'Backup readiness gate',
            'Canary stage',
            'Failure threshold',
            'Pause / resume',
            'Reversible rollback',
        ] as $control) {
            $this->assertTrue($missing->contains($control), "{$control} must be reported as missing");
        }
    }

    public function test_controls_that_do_exist_are_credited_honestly(): void
    {
        $present = collect($this->safety()->controls())
            ->filter(fn (array $c): bool => $c['present'])
            ->pluck('control');

        // Both of these are genuinely implemented in
        // FleetMigrationOrchestrationService and it would be equally
        // dishonest to report them as absent.
        $this->assertTrue($present->contains('Halt propagation'));
        $this->assertTrue($present->contains('Per-target results'));
    }

    public function test_the_disclosure_says_no_migration_is_executed(): void
    {
        $disclosure = $this->safety()->disclosure();

        $this->assertStringContainsString('REHEARSAL ONLY', $disclosure);
        $this->assertStringContainsString('No migration is ever executed', $disclosure);
        $this->assertStringContainsString('Rollback relabels rows and reverses nothing', $disclosure);
    }

    public function test_a_completed_run_is_labelled_simulated_in_the_list(): void
    {
        $firm = Firm::factory()->create(['deployment_mode' => DeploymentMode::Dedicated->value]);
        $service = app(FleetMigrationOrchestrationService::class);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $run = $service->createRun('2026_01_01_add_column', platformAdminActor: $admin);
        $service->begin($run);
        $service->applyInstance($run->fresh(), $firm, true);
        $service->complete($run->fresh());

        $response = $this->actingAs($admin, 'platform_admin')
            ->get(PlatformFleetMigrationRunResource::getUrl('index'));

        $response->assertOk();
        $response->assertSee('Completed (simulated)');
    }

    public function test_the_list_page_discloses_the_rehearsal_semantics(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')
            ->get(PlatformFleetMigrationRunResource::getUrl('index'));

        $response->assertOk();
        $response->assertSee('Rehearsal Tool');
        $response->assertSee('REHEARSAL ONLY');
        $response->assertSee('Missing safety controls');
    }

    /**
     * The one safety property the orchestrator genuinely has: a
     * failure stops everything else. Worth a real regression, because
     * the console now credits it explicitly.
     */
    public function test_a_failed_target_halts_the_run_and_skips_the_rest(): void
    {
        $failing = Firm::factory()->create(['deployment_mode' => DeploymentMode::Dedicated->value]);
        $other = Firm::factory()->create(['deployment_mode' => DeploymentMode::Dedicated->value]);

        $service = app(FleetMigrationOrchestrationService::class);
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $run = $service->createRun('2026_01_01_add_column', platformAdminActor: $admin);
        $service->begin($run);
        $service->applyInstance($run->fresh(), $failing, false, 'migration failed');

        $run = $run->fresh();
        $summary = $service->summarize($run);

        $this->assertSame(FleetMigrationRunStatus::Halted, $run->status);
        $this->assertSame(1, $summary->failedCount);
        $this->assertSame(1, $summary->skippedCount, 'the other target must be skipped, not left pending');
        $this->assertSame(0, $summary->pendingCount);
        $this->assertNotNull($other->id);
    }

    public function test_a_halted_run_cannot_be_completed(): void
    {
        $firm = Firm::factory()->create(['deployment_mode' => DeploymentMode::Dedicated->value]);
        $service = app(FleetMigrationOrchestrationService::class);
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $run = $service->createRun('2026_01_01_add_column', platformAdminActor: $admin);
        $service->begin($run);
        $service->applyInstance($run->fresh(), $firm, false, 'boom');

        $this->expectException(\RuntimeException::class);
        $service->complete($run->fresh());
    }

    public function test_a_run_cannot_be_begun_twice(): void
    {
        $service = app(FleetMigrationOrchestrationService::class);
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $run = $service->createRun('2026_01_01_add_column', platformAdminActor: $admin);
        $service->begin($run);

        $this->expectException(\RuntimeException::class);
        $service->begin($run->fresh());
    }

    public function test_rollback_is_never_presented_as_reversing_anything(): void
    {
        $control = collect($this->safety()->controls())
            ->firstWhere('control', 'Reversible rollback');

        $this->assertFalse($control['present']);
        $this->assertStringContainsString('reverses nothing', $this->safety()->disclosure());
        $this->assertStringContainsString('only relabels', $control['detail']);
    }
}
