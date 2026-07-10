<?php

namespace Tests\Feature\Security\Login;

use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlatformAdminLoginPanelAccessTest — internal login/panel access
 * wiring. Proves the platform_admin guard + admin panel: an active
 * PlatformAdmin can reach the dashboard, a guest cannot, an inactive
 * PlatformAdmin is denied, and both successful and failed login
 * attempts are recorded as SecurityEvent rows.
 */
class PlatformAdminLoginPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_reach_platform_admin_dashboard_after_login(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $response = $this->actingAs($admin, 'platform_admin')->get('/admin');

        $response->assertOk();
    }

    public function test_guest_cannot_reach_platform_admin_dashboard(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect('/admin/login');
    }

    public function test_inactive_platform_admin_cannot_reach_dashboard(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => false]);

        $response = $this->actingAs($admin, 'platform_admin')->get('/admin');

        $response->assertForbidden();
    }

    public function test_successful_platform_admin_login_is_recorded_as_a_security_event(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        auth('platform_admin')->login($admin);

        $event = SecurityEvent::query()
            ->where('actor_type', PlatformAdmin::class)
            ->where('actor_id', $admin->id)
            ->where('event_type', 'login_succeeded')
            ->first();

        $this->assertNotNull($event, 'Expected a login_succeeded SecurityEvent row for the platform admin.');
        $this->assertSame('authentication', $event->category);
        $this->assertNull($event->firm_id, 'Platform-level login events must have a null firm_id.');
    }

    public function test_failed_platform_admin_login_is_recorded_as_a_security_event(): void
    {
        PlatformAdmin::factory()->create(['email' => 'admin@example.com', 'is_active' => true]);

        event(new \Illuminate\Auth\Events\Failed('platform_admin', null, ['email' => 'admin@example.com', 'password' => 'wrong']));

        $event = SecurityEvent::query()
            ->where('event_type', 'login_failed')
            ->where('category', 'authentication')
            ->first();

        $this->assertNotNull($event, 'Expected a login_failed SecurityEvent row.');
        $this->assertSame('admin@example.com', $event->metadata['attempted_email'] ?? null);
        $this->assertArrayNotHasKey('password', $event->metadata, 'The audit log must never store the attempted password.');
    }
}
