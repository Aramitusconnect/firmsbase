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
use Livewire\Livewire;
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

    /**
     * SuperAdmin console professionalization mission (MYAT7): the
     * rewritten page adds a live 7/30/90/Custom date-range filter (same
     * pattern as PlatformMarketplaceOverviewPage), previous-period
     * comparison, directory-performance breakdowns, demand-vs-supply
     * search intelligence, and an explicit gaps section.
     */
    public function test_range_filter_narrows_the_summary_counts(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        MarketplaceAnalyticsEvent::factory()->searchPerformed()->create(['occurred_at' => now()->subDays(2)]);
        MarketplaceAnalyticsEvent::factory()->searchPerformed()->create(['occurred_at' => now()->subDays(45)]);

        $test = Livewire::test(PlatformMarketplaceAnalyticsPage::class);
        $test->assertSee('Searches performed: 1', false);

        $test->set('data.range', '90');
        $test->assertSee('Searches performed: 2', false);
    }

    public function test_summary_shows_a_previous_period_comparison(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        MarketplaceAnalyticsEvent::factory()->searchPerformed()->create(['occurred_at' => now()->subDays(2)]);
        MarketplaceAnalyticsEvent::factory()->count(4)->searchPerformed()->create(['occurred_at' => now()->subDays(35)]);

        $response = $this->get(PlatformMarketplaceAnalyticsPage::getUrl());

        $response->assertOk();
        $response->assertSee('Searches performed: 1', false);
        $response->assertSee('vs. prior period', false);
    }

    public function test_directory_performance_breaks_down_views_by_claim_member_and_accepting_status(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $claimedFirm = DirectoryFirm::factory()->create(['is_claimed' => true, 'is_marketplace_member' => true, 'accepting_inquiries' => true]);
        $unclaimedFirm = DirectoryFirm::factory()->create(['is_claimed' => false, 'is_marketplace_member' => false, 'accepting_inquiries' => false]);
        MarketplaceAnalyticsEvent::factory()->firmProfileViewed()->create(['subject_id' => $claimedFirm->id, 'occurred_at' => now()]);
        MarketplaceAnalyticsEvent::factory()->firmProfileViewed()->create(['subject_id' => $unclaimedFirm->id, 'occurred_at' => now()]);

        $response = $this->get(PlatformMarketplaceAnalyticsPage::getUrl());

        $response->assertOk();
        $response->assertSee('Claimed firm views: 1', false);
        $response->assertSee('Unclaimed firm views: 1', false);
        $response->assertSee('FirmsVault member views: 1', false);
        $response->assertSee('Non-member views: 1', false);
        $response->assertSee('Accepting-inquiries views: 1', false);
        $response->assertSee('Not-accepting views: 1', false);
    }

    public function test_search_intelligence_flags_a_practice_area_with_no_published_supply(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        MarketplaceAnalyticsEvent::factory()->searchPerformed(['practice_area_slug' => 'unmet-demand-area'])->create(['occurred_at' => now()]);

        $response = $this->get(PlatformMarketplaceAnalyticsPage::getUrl());

        $response->assertOk();
        $response->assertSee('unmet-demand-area — 1 search(es), 0 published firm(s) — ⚠ no published firms offer this', false);
    }

    public function test_gaps_section_discloses_structurally_unavailable_metrics(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformMarketplaceAnalyticsPage::getUrl());

        $response->assertOk();
        $response->assertSee('Search-to-profile click-through rate: not available', false);
        $response->assertSee('Zero-result search rate: not available', false);
        $response->assertSee('Top free-text search terms: not available', false);
        $response->assertSee('Search reformulations: not available', false);
        $response->assertSee('Average time to conversion: not available', false);
    }
}
