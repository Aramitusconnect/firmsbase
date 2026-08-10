<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformSchedulerPage;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use App\Services\SchedulerHealthService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * PlatformSchedulerPageTest — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). Navigation, direct-route auth, live
 * registered-schedule introspection (never a hand-maintained
 * duplicate list), and honest liveness disclosure. Read-only — no
 * mutating action exists on this page (positive proof included).
 */
final class PlatformSchedulerPageTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(PlatformSchedulerPage::shouldRegisterNavigation());
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(PlatformSchedulerPage::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformSchedulerPage::getUrl())->assertForbidden();
    }

    public function test_a_read_only_auditor_can_reach_the_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin')->get(PlatformSchedulerPage::getUrl())->assertOk();
    }

    public function test_the_registered_schedule_is_shown_including_the_new_operations_entries(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformSchedulerPage::getUrl());

        $response->assertOk();
        $response->assertSee('health:checks:run');
        $response->assertSee('scheduler:heartbeat:record');
        $response->assertSee('integrations:outbox:dispatch');
    }

    public function test_liveness_is_honestly_disclosed_as_unhealthy_before_any_heartbeat(): void
    {
        Cache::forget('firmsbase:scheduler:last_heartbeat_at');

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformSchedulerPage::getUrl());

        $response->assertOk();
        $response->assertSee('Unhealthy/Unknown');
    }

    public function test_liveness_reports_healthy_after_a_heartbeat(): void
    {
        app(SchedulerHealthService::class)->recordHeartbeat();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformSchedulerPage::getUrl());

        $response->assertOk();
        $response->assertSee('Healthy (recent heartbeat seen)');
    }

    public function test_no_filament_action_is_registered_anywhere_on_this_page(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformSchedulerPage.php'));

        $this->assertStringNotContainsString('Action::make(', $source);
        $this->assertStringNotContainsString('->action(', $source);
        $this->assertStringNotContainsString('getHeaderActions', $source);
    }
}
