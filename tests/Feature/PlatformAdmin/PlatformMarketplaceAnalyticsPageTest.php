<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformMarketplaceAnalyticsPage;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceAnalyticsEvent;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlatformMarketplaceAnalyticsPageTest — Mission 2 (MyAttorney
 * Marketplace Core), checkpoint 13. Access control (deliberately
 * broader than the marketplace-governance gate — see
 * PlatformStaffAccessPolicyService::MARKETPLACE_ANALYTICS_ROLES' own
 * docblock) and that the page actually renders real aggregate counts.
 */
final class PlatformMarketplaceAnalyticsPageTest extends TestCase
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
        $this->assertFalse(PlatformMarketplaceAnalyticsPage::canAccess());
    }

    public function test_navigation_is_hidden_for_a_platform_admin_with_no_role(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(PlatformMarketplaceAnalyticsPage::canAccess());
    }

    public function test_navigation_is_hidden_for_a_sales_rep(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(PlatformMarketplaceAnalyticsPage::canAccess());
    }

    public function test_navigation_is_visible_for_super_admin_platform_admin_sales_manager_and_read_only_auditor(): void
    {
        foreach ([PlatformRoleCode::SuperAdmin, PlatformRoleCode::PlatformAdmin, PlatformRoleCode::SalesManager, PlatformRoleCode::ReadOnlyAuditor] as $role) {
            $admin = $this->adminWithRole($role);
            $this->actingAs($admin, 'platform_admin');

            $this->assertTrue(PlatformMarketplaceAnalyticsPage::canAccess());
        }
    }

    public function test_guest_is_redirected_from_the_page(): void
    {
        $this->get(PlatformMarketplaceAnalyticsPage::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_sales_rep_is_forbidden_at_the_route_level(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformMarketplaceAnalyticsPage::getUrl())->assertForbidden();
    }

    public function test_the_page_renders_real_aggregate_counts(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $firm = DirectoryFirm::factory()->create(['display_name' => 'Analytics Dashboard Firm']);
        MarketplaceAnalyticsEvent::factory()->firmProfileViewed()->create(['subject_id' => $firm->id, 'occurred_at' => now()]);

        $response = $this->get(PlatformMarketplaceAnalyticsPage::getUrl());

        $response->assertOk();
        $response->assertSee('Profile views: 1', false);
        $response->assertSee('Analytics Dashboard Firm', false);
    }
}
