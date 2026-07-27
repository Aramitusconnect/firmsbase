<?php

namespace Tests\Feature\StatusPage;

use App\Models\PlatformAdmin;
use App\Services\StatusPageService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * StatusPageServicePlatformAdminActorTest — Phase 4 (FirmsVault
 * Platform Admin Control Center, "Operations"). Proves the new
 * optional ?PlatformAdmin $actor parameter on every StatusPageService
 * method — a genuine "zero actor param" gap, identical in shape to
 * PlanService::activate()/archive() — is purely additive: omitted, no
 * audit row is written (byte-for-byte unchanged); supplied, a
 * recordPlatformEvent() row is written.
 */
class StatusPageServicePlatformAdminActorTest extends TestCase
{
    use RefreshDatabase;

    public function test_omitting_the_actor_writes_no_audit_event(): void
    {
        $service = app(StatusPageService::class);

        $service->publish('investigating', 'client_portal', 'msg', now());

        $this->assertSame(
            0,
            app(TenantContextService::class)->runWithoutFirmContext(
                fn () => DB::table('security_events')->where('event_type', 'status_page_event_published')->count()
            )
        );
    }

    public function test_supplying_an_actor_writes_an_audit_event(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $service = app(StatusPageService::class);

        $event = $service->publish('investigating', 'client_portal', 'msg', now(), null, $admin);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'status_page_event_published')
                ->where('actor_id', $admin->id)
                ->where('actor_type', PlatformAdmin::class)
                ->first()
        );
        $this->assertNotNull($row);

        $service->update($event->correlation_id, 'identified', 'root cause found', $admin);
        $service->resolvePublicly($event->correlation_id, 'fixed', $admin);
        $service->unpublish($event->correlation_id, $admin);

        foreach (['status_page_event_updated', 'status_page_event_resolved_publicly', 'status_page_event_unpublished'] as $eventType) {
            $row = app(TenantContextService::class)->runWithoutFirmContext(
                fn () => DB::table('security_events')->where('event_type', $eventType)->where('actor_id', $admin->id)->first()
            );
            $this->assertNotNull($row, "Expected an audit row for {$eventType}.");
        }
    }
}
