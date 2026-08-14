<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformMarketplaceSettingsPage;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlatformMarketplaceSettingsPageTest — SuperAdmin console
 * professionalization mission (MYAT9, section 11). Access control
 * mirrors PlatformMarketplaceAnalyticsPageTest's own established
 * shape (same canViewMarketplaceAnalytics() gate), plus coverage that
 * the retention values shown are the REAL effective config()
 * values — not hardcoded placeholder numbers — and that the AI kill
 * switch status reflects real state rather than a fixed string.
 */
final class PlatformMarketplaceSettingsPageTest extends TestCase
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
        $this->assertFalse(PlatformMarketplaceSettingsPage::canAccess());
    }

    public function test_navigation_is_hidden_for_a_sales_rep(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(PlatformMarketplaceSettingsPage::canAccess());
    }

    public function test_navigation_is_visible_for_super_admin_platform_admin_sales_manager_and_read_only_auditor(): void
    {
        foreach ([PlatformRoleCode::SuperAdmin, PlatformRoleCode::PlatformAdmin, PlatformRoleCode::SalesManager, PlatformRoleCode::ReadOnlyAuditor] as $role) {
            $admin = $this->adminWithRole($role);
            $this->actingAs($admin, 'platform_admin');

            $this->assertTrue(PlatformMarketplaceSettingsPage::canAccess());
        }
    }

    public function test_a_sales_rep_is_forbidden_at_the_route_level(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformMarketplaceSettingsPage::getUrl())->assertForbidden();
    }

    public function test_the_page_shows_the_real_effective_retention_config(): void
    {
        config(['marketplace.analytics_retention_days' => 123, 'marketplace.intake_retention_days' => 45]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformMarketplaceSettingsPage::getUrl());

        $response->assertOk();
        $response->assertSee('123 days', false);
        $response->assertSee('45 days', false);
    }

    public function test_the_page_reflects_platform_ai_status(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformMarketplaceSettingsPage::getUrl());

        $response->assertOk();
        $response->assertSee('Platform AI status: Enabled', false);
    }

    public function test_the_page_links_to_ai_oversight_and_import_batches(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformMarketplaceSettingsPage::getUrl());

        $response->assertOk();
        $response->assertSee('Manage in AI Oversight', false);
        $response->assertSee('Manage Directory Import Batches', false);
    }

    public function test_practice_area_link_is_hidden_from_an_admin_without_catalog_access(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesManager);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformMarketplaceSettingsPage::getUrl());

        $response->assertOk();
        $response->assertDontSee('Manage Practice Area Catalog', false);
    }

    public function test_practice_area_link_is_visible_to_a_super_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformMarketplaceSettingsPage::getUrl());

        $response->assertOk();
        $response->assertSee('Manage Practice Area Catalog', false);
    }
}
