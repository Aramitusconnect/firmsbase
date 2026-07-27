<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\DeploymentMode;
use App\Enums\FleetMigrationInstanceStatus as InstanceStatus;
use App\Enums\FleetMigrationRunStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\ApplyFleetMigrationInstanceAction;
use App\Filament\Actions\Platform\BeginFleetMigrationRunAction;
use App\Filament\Actions\Platform\CompleteFleetMigrationRunAction;
use App\Filament\Actions\Platform\CreateFleetMigrationRunAction;
use App\Filament\Actions\Platform\RollbackFleetMigrationRunAction;
use App\Filament\Pages\PlatformFleetMigrationRunDetailPage;
use App\Filament\Resources\PlatformFleetMigrationRunResource;
use App\Filament\Resources\PlatformFleetMigrationRunResource\Pages\ListPlatformFleetMigrationRuns;
use App\Models\FleetMigrationInstanceStatus;
use App\Models\FleetMigrationRun;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\FleetMigrationOrchestrationService;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Deployment\Concerns\SetsUpDeploymentFirm;
use Tests\TestCase;

/**
 * PlatformFleetMigrationRunResourceTest — Phase 4 (FirmsVault Platform
 * Admin Control Center, "Operations"). Navigation, direct-route auth,
 * ordering, pagination, empty state, and the full simulated lifecycle
 * (create -> begin -> apply -> complete / rollback), including the
 * sentinel-actor resolution for createRun()'s NOT NULL `initiated_by`
 * FK.
 */
final class PlatformFleetMigrationRunResourceTest extends TestCase
{
    use RefreshDatabase, SetsUpDeploymentFirm;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function assertAuditWritten(string $eventType, int $actorId): void
    {
        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', $eventType)
                ->where('actor_id', $actorId)
                ->first()
        );
        $this->assertNotNull($row, "Expected a security_events row for event_type={$eventType}.");
    }

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(PlatformFleetMigrationRunResource::canAccess());
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(PlatformFleetMigrationRunResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformFleetMigrationRunResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin')->get(PlatformFleetMigrationRunResource::getUrl())->assertOk();
    }

    public function test_empty_state(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformFleetMigrationRunResource::getUrl());
        $response->assertOk();
        $response->assertSee('No fleet migration runs yet');
    }

    public function test_orders_deterministically_by_id_when_created_at_ties(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $runs = FleetMigrationRun::factory()->count(5)->create();

        $first = Livewire::test(ListPlatformFleetMigrationRuns::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();
        $second = Livewire::test(ListPlatformFleetMigrationRuns::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame($first, $second);
        $this->assertSame($runs->sortByDesc('id')->pluck('id')->values()->all(), $first);
    }

    public function test_the_list_is_paginated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        FleetMigrationRun::factory()->count(30)->create();

        $test = Livewire::test(ListPlatformFleetMigrationRuns::class);
        $test->assertSuccessful();
        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }

    // --- Create lifecycle (sentinel actor) ---

    public function test_create_run_is_allowed_for_a_super_admin_uses_the_sentinel_actor_and_writes_audit_event(): void
    {
        $dedicated = $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        $this->makeDeploymentFirm(DeploymentMode::Saas);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListPlatformFleetMigrationRuns::class);
        $test->mountAction(CreateFleetMigrationRunAction::getDefaultName());
        $test->setActionData(['migration_identifier' => '2026_08_01_000000_example']);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $run = FleetMigrationRun::query()->firstOrFail();
        $this->assertSame('2026_08_01_000000_example', $run->migration_identifier);
        $this->assertSame(FleetMigrationRunStatus::Pending, $run->status);

        $sentinel = User::query()->where('email', 'platform-system+fleet-migrations@firmsvault.internal')->first();
        $this->assertNotNull($sentinel, 'The sentinel actor row must be created lazily.');
        $this->assertSame($sentinel->id, $run->initiated_by);

        app(TenantContextService::class)->runWithFirmContext($dedicated, function () use ($run, $dedicated): void {
            $this->assertDatabaseHas('fleet_migration_instance_status', [
                'fleet_migration_run_id' => $run->id,
                'firm_id' => $dedicated->id,
                'status' => InstanceStatus::Pending->value,
            ]);
        });

        $this->assertAuditWritten('fleet_migration_run_created', $admin->id);
    }

    public function test_create_run_is_denied_for_a_read_only_auditor(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListPlatformFleetMigrationRuns::class);
        $test->mountAction(CreateFleetMigrationRunAction::getDefaultName());
        $test->setActionData(['migration_identifier' => 'x']);
        $test->callMountedAction();

        $this->assertSame(0, FleetMigrationRun::query()->count());
    }

    // --- Full lifecycle through the detail page ---

    public function test_full_lifecycle_begin_apply_complete(): void
    {
        $dedicated = $this->makeDeploymentFirm(DeploymentMode::Dedicated);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $run = app(FleetMigrationOrchestrationService::class)->createRun('2026_08_01_000001_example', null, $admin);

        $detailTest = Livewire::test(PlatformFleetMigrationRunDetailPage::class, ['runUuid' => $run->uuid]);
        $detailTest->assertSuccessful();

        $detailTest->mountAction(BeginFleetMigrationRunAction::getDefaultName());
        $detailTest->callMountedAction();
        $detailTest->assertHasNoActionErrors();

        $run->refresh();
        $this->assertSame(FleetMigrationRunStatus::InProgress, $run->status);
        $this->assertAuditWritten('fleet_migration_run_begun', $admin->id);

        $instance = app(TenantContextService::class)->runWithFirmContext($dedicated, fn () => FleetMigrationInstanceStatus::query()
            ->where('fleet_migration_run_id', $run->id)
            ->where('firm_id', $dedicated->id)
            ->firstOrFail());

        $applyTest = Livewire::test(PlatformFleetMigrationRunDetailPage::class, ['runUuid' => $run->uuid]);
        $applyTest->callTableAction(ApplyFleetMigrationInstanceAction::getDefaultName(), $instance, data: ['succeeded' => 1]);
        $applyTest->assertHasNoTableActionErrors();

        app(TenantContextService::class)->runWithFirmContext($dedicated, function () use ($run, $dedicated): void {
            $this->assertDatabaseHas('fleet_migration_instance_status', [
                'fleet_migration_run_id' => $run->id,
                'firm_id' => $dedicated->id,
                'status' => InstanceStatus::Applied->value,
            ]);
        });
        // fleet_migration_instance_applied's audit event is written via
        // the FIRM-SCOPED PlatformAdminAuditEventRecorder::record()
        // variant (correct — the instance is one firm's own record), so
        // reading it back requires that same firm's tenant context,
        // unlike the other, firm-less lifecycle events asserted via
        // assertAuditWritten() elsewhere in this test (which use
        // runWithoutFirmContext()).
        $firmScopedRow = app(TenantContextService::class)->runWithFirmContext($dedicated, fn () => DB::table('security_events')
            ->where('event_type', 'fleet_migration_instance_applied')
            ->where('actor_id', $admin->id)
            ->where('firm_id', $dedicated->id)
            ->first());
        $this->assertNotNull($firmScopedRow);

        $completeTest = Livewire::test(PlatformFleetMigrationRunDetailPage::class, ['runUuid' => $run->uuid]);
        $completeTest->mountAction(CompleteFleetMigrationRunAction::getDefaultName());
        $completeTest->callMountedAction();
        $completeTest->assertHasNoActionErrors();

        $run->refresh();
        $this->assertSame(FleetMigrationRunStatus::Completed, $run->status);
        $this->assertAuditWritten('fleet_migration_run_completed', $admin->id);
    }

    public function test_rollback_is_visible_only_once_halted_or_completed(): void
    {
        $this->makeDeploymentFirm(DeploymentMode::Dedicated);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $run = app(FleetMigrationOrchestrationService::class)->createRun('2026_08_01_000002_example', null, $admin);

        $pendingTest = Livewire::test(PlatformFleetMigrationRunDetailPage::class, ['runUuid' => $run->uuid]);
        $pendingTest->assertActionHidden(RollbackFleetMigrationRunAction::getDefaultName());

        app(FleetMigrationOrchestrationService::class)->begin($run);

        $inProgressTest = Livewire::test(PlatformFleetMigrationRunDetailPage::class, ['runUuid' => $run->uuid]);
        $inProgressTest->assertActionHidden(RollbackFleetMigrationRunAction::getDefaultName());
    }

    public function test_guest_is_redirected_from_the_detail_page(): void
    {
        $run = FleetMigrationRun::factory()->create();

        $this->get(PlatformFleetMigrationRunDetailPage::getUrl(['runUuid' => $run->uuid]))->assertRedirect('/admin/login');
    }

    public function test_a_sales_rep_is_forbidden_from_the_detail_page(): void
    {
        $run = FleetMigrationRun::factory()->create();
        $salesRep = $this->adminWithRole(PlatformRoleCode::SalesRep);

        $this->actingAs($salesRep, 'platform_admin')
            ->get(PlatformFleetMigrationRunDetailPage::getUrl(['runUuid' => $run->uuid]))
            ->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_detail_page(): void
    {
        $run = FleetMigrationRun::factory()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(PlatformFleetMigrationRunDetailPage::getUrl(['runUuid' => $run->uuid]))
            ->assertOk();
    }
}
