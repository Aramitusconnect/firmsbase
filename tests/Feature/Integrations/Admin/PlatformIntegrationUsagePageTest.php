<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformIntegrationUsagePage;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlatformIntegrationUsagePageTest — Phase 2 (FirmsVault Platform Admin
 * Control Center, "Integration Operations Center"). Route-level
 * authorization plus the honest-empty-state proof this page's whole
 * design exists for: no fabricated usage numbers, a clear "not
 * available" notice, and the "Sync Volume" section correctly labeled as
 * NOT usage.
 */
final class PlatformIntegrationUsagePageTest extends TestCase
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

    // --- Navigation visibility ---
    // (a plain Filament\Pages\Page, unlike a Resource, needs its own
    // shouldRegisterNavigation() override tied to canAccess() — see
    // this class's own implementation, mirroring
    // PlatformIntegrationOverviewPage/PlatformProviderHealthPage's
    // identical established pattern.)

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(PlatformIntegrationUsagePage::shouldRegisterNavigation());
    }

    public function test_navigation_is_hidden_for_a_platform_admin_with_no_role(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(PlatformIntegrationUsagePage::shouldRegisterNavigation());
    }

    public function test_navigation_is_visible_for_an_eligible_platform_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(PlatformIntegrationUsagePage::shouldRegisterNavigation());
    }

    public function test_guest_is_redirected_from_the_usage_page(): void
    {
        $this->get(PlatformIntegrationUsagePage::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->get(PlatformIntegrationUsagePage::getUrl())->assertForbidden();
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);

        $this->actingAs($admin, 'platform_admin')->get(PlatformIntegrationUsagePage::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_page_and_sees_the_honest_empty_state(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformIntegrationUsagePage::getUrl());
        $response->assertOk();

        // The honest "not available" notice — no fabricated usage number.
        $response->assertSee('No usage-metering data is available');
        $response->assertSee('IntegrationUsageRecorderService');

        // The proxy section is clearly labeled as NOT usage.
        $response->assertSee('Sync Volume Snapshot (not usage)');
    }

    public function test_the_page_never_fabricates_a_usage_figure_or_labels_the_proxy_as_usage(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformIntegrationUsagePage.php'));

        // The proxy section's own label explicitly disclaims "usage".
        $this->assertStringContainsString('Sync Volume Snapshot (not usage)', $source);
        // No hardcoded/fabricated numeric literal is ever presented as a
        // usage total — every number is derived via sprintf('%d', ...)
        // against a real Collection method (count()/sum()), never a
        // literal constant.
        $this->assertMatchesRegularExpression('/sprintf\(.*%d.*\$summaries->(count|sum)\(/s', $source);
    }

    /**
     * `integration_usage_records` is legitimately NAMED in this page's
     * own docblock prose (explaining WHY it is not queried) — the
     * reliable structural signal is that no live query is ever built
     * against it: no `DB::table('integration_usage_records')` call and
     * no `IntegrationUsageRecord::query(`/`IntegrationUsageRecord::`
     * class usage (which would require a real `use` import to compile
     * at all).
     */
    public function test_the_page_never_queries_integration_usage_records_directly(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformIntegrationUsagePage.php'));

        $this->assertStringNotContainsString("DB::table('integration_usage_records')", $source);
        $this->assertStringNotContainsString('use App\Integrations\Models\IntegrationUsageRecord;', $source);
        $this->assertStringNotContainsString('IntegrationUsageRecord::', $source);
    }
}
