<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\ToggleAiKillSwitchAction;
use App\Filament\Pages\PlatformAiOversightPage;
use App\Marketplace\Enums\MarketplaceAnalyticsEventType;
use App\Marketplace\Models\MarketplaceAnalyticsEvent;
use App\Models\PlatformAdmin;
use App\Services\AiModeResolutionService;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformAiOversightPageTest — Mission 3 (MyAttorney Conversion + AI
 * Intake), checkpoint 14. Access control (mirrors
 * PlatformMarketplaceAnalyticsPageTest's own established shape, but
 * narrower — canAccessAiPolicySettings()'s own role set), that the page
 * renders real funnel counts, and that the kill-switch toggle actually
 * flips ai_policy_settings via the real, audited AiPolicySettingService
 * write path.
 */
final class PlatformAiOversightPageTest extends TestCase
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

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(PlatformAiOversightPage::canAccess());
    }

    public function test_navigation_is_hidden_for_a_sales_rep(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(PlatformAiOversightPage::canAccess());
    }

    public function test_navigation_is_visible_for_super_admin_platform_admin_and_security_auditor(): void
    {
        foreach ([PlatformRoleCode::SuperAdmin, PlatformRoleCode::PlatformAdmin, PlatformRoleCode::SecurityAuditor] as $role) {
            $admin = $this->adminWithRole($role);
            $this->actingAs($admin, 'platform_admin');

            $this->assertTrue(PlatformAiOversightPage::canAccess());
        }
    }

    public function test_a_sales_rep_is_forbidden_at_the_route_level(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformAiOversightPage::getUrl())->assertForbidden();
    }

    public function test_the_page_renders_real_funnel_counts_and_kill_switch_status(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        MarketplaceAnalyticsEvent::factory()->count(3)->create(['event_type' => MarketplaceAnalyticsEventType::IntakeStarted, 'subject_type' => null, 'subject_id' => null, 'occurred_at' => now()]);

        $response = $this->get(PlatformAiOversightPage::getUrl());

        $response->assertOk();
        $response->assertSee('Started: 3', false);
        $response->assertSee('Status:', false);
    }

    public function test_toggling_the_kill_switch_flips_the_stored_value(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        // No ai_policy_settings row exists yet in a fresh test database
        // — platformKillSwitchEngaged() treats that as "not engaged"
        // (AiModeResolutionService's own established default), so the
        // first toggle here engages it.
        $this->assertFalse(app(AiModeResolutionService::class)->platformKillSwitchEngaged());

        $test = Livewire::test(PlatformAiOversightPage::class);
        $test->mountAction(ToggleAiKillSwitchAction::getDefaultName());
        $test->callMountedAction();

        $this->assertTrue(app(AiModeResolutionService::class)->platformKillSwitchEngaged());
    }

    public function test_toggling_is_forbidden_for_a_security_auditor_who_can_only_read(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SecurityAuditor);
        $this->actingAs($admin, 'platform_admin');

        $engagedBefore = app(AiModeResolutionService::class)->platformKillSwitchEngaged();

        $test = Livewire::test(PlatformAiOversightPage::class);
        $test->mountAction(ToggleAiKillSwitchAction::getDefaultName());
        $test->callMountedAction();

        $this->assertSame($engagedBefore, app(AiModeResolutionService::class)->platformKillSwitchEngaged());
    }
}
