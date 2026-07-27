<?php

namespace Tests\Feature\Incidents;

use App\Enums\IncidentSeverity;
use App\Models\PlatformAdmin;
use App\Services\IncidentService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Deployment\Concerns\SetsUpDeploymentFirm;
use Tests\TestCase;

/**
 * IncidentServicePlatformAdminActorTest — Phase 4 (FirmsVault Platform
 * Admin Control Center, "Operations"). Proves the new
 * ?PlatformAdmin $platformAdminActor parameter added to every
 * IncidentService method: (a) leaves actor_user_id null exactly as
 * before when omitted (byte-for-byte unchanged behavior), (b) leaves
 * actor_user_id null even when supplied (a PlatformAdmin is never
 * coerced into the User-typed column), and (c) records a
 * security_events row via the correct firm-scoped/firm-less
 * PlatformAdminAuditEventRecorder variant depending on the incident's
 * own $firm.
 */
class IncidentServicePlatformAdminActorTest extends TestCase
{
    use RefreshDatabase, SetsUpDeploymentFirm;

    public function test_omitting_the_platform_admin_actor_leaves_behavior_unchanged(): void
    {
        $service = app(IncidentService::class);

        $incident = $service->open(null, IncidentSeverity::Low, 'test');

        $this->assertNull($incident->actor_user_id);
        $this->assertSame(
            0,
            app(TenantContextService::class)->runWithoutFirmContext(
                fn () => DB::table('security_events')->where('event_type', 'incident_opened')->count()
            )
        );
    }

    public function test_a_platform_admin_actor_leaves_actor_user_id_null_but_writes_a_platform_wide_audit_event(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $service = app(IncidentService::class);

        $incident = $service->open(null, IncidentSeverity::Critical, 'test', false, false, null, $admin);

        $this->assertNull($incident->actor_user_id, 'A PlatformAdmin must never be coerced into the User-typed actor_user_id column.');

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'incident_opened')
                ->where('actor_id', $admin->id)
                ->where('actor_type', PlatformAdmin::class)
                ->first()
        );
        $this->assertNotNull($row);
    }

    public function test_a_platform_admin_actor_on_a_firm_scoped_incident_writes_the_firm_scoped_audit_variant(): void
    {
        $firm = $this->makeDeploymentFirm();
        $admin = PlatformAdmin::factory()->create();
        $service = app(IncidentService::class);

        $incident = $service->open($firm, IncidentSeverity::Medium, 'test', false, false, null, $admin);

        $row = app(TenantContextService::class)->runWithFirmContext($firm, fn () => DB::table('security_events')
            ->where('event_type', 'incident_opened')
            ->where('firm_id', $firm->id)
            ->where('actor_id', $admin->id)
            ->first());

        $this->assertNotNull($row);
        $this->assertSame($firm->id, $incident->firm_id);
    }
}
