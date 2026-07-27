<?php

namespace Tests\Feature\Deployment\Fleet;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\FleetMigrationOrchestrationService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Deployment\Concerns\SetsUpDeploymentFirm;
use Tests\TestCase;

/**
 * FleetMigrationOrchestrationServicePlatformAdminActorTest — Phase 4
 * (FirmsVault Platform Admin Control Center, "Operations"). Proves the
 * resolution to the "actor-type gap" for createRun()'s NOT NULL
 * `initiated_by` FK: a PlatformAdmin-only caller resolves a single,
 * lazily-created, idempotent sentinel `users` row rather than
 * requiring a real firm-panel User — and that sentinel row can never
 * authenticate into the firm panel. Also proves the "zero actor param"
 * additions to begin/rollback/complete record an audit event only
 * when supplied, byte-for-byte unchanged otherwise.
 */
class FleetMigrationOrchestrationServicePlatformAdminActorTest extends TestCase
{
    use RefreshDatabase, SetsUpDeploymentFirm;

    public function test_a_real_user_is_still_accepted_unchanged(): void
    {
        $user = User::factory()->create();
        $service = app(FleetMigrationOrchestrationService::class);

        $run = $service->createRun('2026_08_02_000000_example', $user);

        $this->assertSame($user->id, $run->initiated_by);
        $this->assertSame(0, DB::table('users')->where('email', 'like', 'platform-system%')->count());
    }

    public function test_a_platform_admin_only_caller_resolves_the_sentinel_actor_and_records_audit(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $service = app(FleetMigrationOrchestrationService::class);

        $run = $service->createRun('2026_08_02_000001_example', null, $admin);

        $sentinel = User::query()->where('email', 'platform-system+fleet-migrations@firmsvault.internal')->first();
        $this->assertNotNull($sentinel);
        $this->assertSame($sentinel->id, $run->initiated_by);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'fleet_migration_run_created')
                ->where('actor_id', $admin->id)
                ->first()
        );
        $this->assertNotNull($row);
    }

    public function test_the_sentinel_actor_can_never_access_any_filament_panel(): void
    {
        $admin = PlatformAdmin::factory()->create();
        app(FleetMigrationOrchestrationService::class)->createRun('2026_08_02_000002_example', null, $admin);

        $sentinel = User::query()->where('email', 'platform-system+fleet-migrations@firmsvault.internal')->firstOrFail();

        $this->assertFalse($sentinel->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_the_sentinel_actor_row_is_reused_idempotently_across_multiple_runs(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $service = app(FleetMigrationOrchestrationService::class);

        $runA = $service->createRun('2026_08_02_000003_example', null, $admin);
        $runB = $service->createRun('2026_08_02_000004_example', null, $admin);

        $this->assertSame($runA->initiated_by, $runB->initiated_by);
        $this->assertSame(1, User::query()->where('email', 'platform-system+fleet-migrations@firmsvault.internal')->count());
    }

    public function test_create_run_throws_when_neither_actor_is_supplied(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(FleetMigrationOrchestrationService::class)->createRun('2026_08_02_000005_example');
    }

    public function test_begin_records_an_audit_event_only_when_a_platform_admin_actor_is_supplied(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $service = app(FleetMigrationOrchestrationService::class);

        $runWithoutActor = $service->createRun('2026_08_02_000006_example', User::factory()->create());
        $service->begin($runWithoutActor);

        $this->assertSame(
            0,
            app(TenantContextService::class)->runWithoutFirmContext(
                fn () => DB::table('security_events')->where('event_type', 'fleet_migration_run_begun')->count()
            )
        );

        $runWithActor = $service->createRun('2026_08_02_000007_example', User::factory()->create());
        $service->begin($runWithActor, $admin);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'fleet_migration_run_begun')
                ->where('actor_id', $admin->id)
                ->first()
        );
        $this->assertNotNull($row);
    }
}
