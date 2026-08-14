<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformMarketplaceOverviewPage;
use App\Marketplace\Enums\ClaimState;
use App\Marketplace\Enums\CorrectionState;
use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Enums\VerificationDimension;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryClaim;
use App\Marketplace\Models\DirectoryCorrectionRequest;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Marketplace\Models\DirectoryVerification;
use App\Marketplace\Models\MarketplaceAnalyticsEvent;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlatformMarketplaceOverviewPageTest — MyAttorney SuperAdmin console
 * professionalization mission, MYAT1. Access control mirrors
 * PlatformMarketplaceAnalyticsPageTest exactly (this page reuses the
 * same canViewMarketplaceAnalytics() gate), plus coverage that every
 * summary card reflects real data and that the date-range filter
 * actually narrows the funnel counts.
 */
final class PlatformMarketplaceOverviewPageTest extends TestCase
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
        $this->assertFalse(PlatformMarketplaceOverviewPage::canAccess());
    }

    public function test_navigation_is_hidden_for_a_platform_admin_with_no_role(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(PlatformMarketplaceOverviewPage::canAccess());
    }

    public function test_navigation_is_hidden_for_a_sales_rep(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(PlatformMarketplaceOverviewPage::canAccess());
    }

    public function test_navigation_is_visible_for_super_admin_platform_admin_sales_manager_and_read_only_auditor(): void
    {
        foreach ([PlatformRoleCode::SuperAdmin, PlatformRoleCode::PlatformAdmin, PlatformRoleCode::SalesManager, PlatformRoleCode::ReadOnlyAuditor] as $role) {
            $admin = $this->adminWithRole($role);
            $this->actingAs($admin, 'platform_admin');

            $this->assertTrue(PlatformMarketplaceOverviewPage::canAccess());
        }
    }

    public function test_guest_is_redirected_from_the_page(): void
    {
        $this->get(PlatformMarketplaceOverviewPage::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_sales_rep_is_forbidden_at_the_route_level(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformMarketplaceOverviewPage::getUrl())->assertForbidden();
    }

    public function test_zero_state_renders_cleanly_with_no_marketplace_data(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformMarketplaceOverviewPage::getUrl());

        $response->assertOk();
        $response->assertSee('Total firms: 0', false);
        $response->assertSee('Total attorneys: 0', false);
        $response->assertSee('Claims awaiting review: 0', false);
        $response->assertSee('Searches: 0', false);
        $response->assertSee('Conversion rate (searches → converted): 0%', false);
    }

    public function test_directory_cards_reflect_real_counts(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        DirectoryFirm::factory()->create([
            'publication_state' => DirectoryPublicationState::Published,
            'is_claimed' => true,
            'is_marketplace_member' => true,
            'accepting_inquiries' => true,
        ]);
        DirectoryFirm::factory()->create([
            'publication_state' => DirectoryPublicationState::Draft,
            'accepting_inquiries' => false,
        ]);
        DirectoryAttorney::factory()->create(['publication_state' => DirectoryPublicationState::Published]);

        $verifiedFirm = DirectoryFirm::factory()->create(['accepting_inquiries' => false]);
        DirectoryVerification::factory()
            ->forVerifiable($verifiedFirm, VerificationDimension::FirmAuthority)
            ->verified()
            ->create();

        $response = $this->get(PlatformMarketplaceOverviewPage::getUrl());

        $response->assertOk();
        $response->assertSee('Total firms: 3', false);
        $response->assertSee('Total attorneys: 1', false);
        $response->assertSee('Published firms: 2', false);
        $response->assertSee('Claimed firms: 1', false);
        $response->assertSee('Verified firms: 1', false);
        $response->assertSee('FirmsVault members: 1', false);
        $response->assertSee('Firms accepting inquiries: 1', false);
        $response->assertSee('Listings needing review (draft): 1', false);
    }

    public function test_operations_cards_count_only_active_states(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        DirectoryClaim::factory()->create(['state' => ClaimState::Pending]);
        DirectoryClaim::factory()->create(['state' => ClaimState::Approved]);
        DirectoryCorrectionRequest::factory()->create(['state' => CorrectionState::UnderReview]);
        DirectoryCorrectionRequest::factory()->create(['state' => CorrectionState::Resolved]);
        DirectoryImportBatch::factory()->create(['status' => DirectoryImportBatchStatus::Previewed]);
        DirectoryImportBatch::factory()->create(['status' => DirectoryImportBatchStatus::Applied]);

        $response = $this->get(PlatformMarketplaceOverviewPage::getUrl());

        $response->assertOk();
        $response->assertSee('Claims awaiting review: 1', false);
        $response->assertSee('Correction/removal requests awaiting review: 1', false);
        $response->assertSee('Import batches processing: 1', false);
        $response->assertSee('Failed imports: 0', false);
    }

    public function test_funnel_reflects_real_analytics_events_within_the_default_window(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $firm = DirectoryFirm::factory()->create();
        MarketplaceAnalyticsEvent::factory()->firmProfileViewed()->create(['subject_id' => $firm->id, 'occurred_at' => now()]);
        MarketplaceAnalyticsEvent::factory()->searchPerformed()->create(['occurred_at' => now()]);
        MarketplaceAnalyticsEvent::factory()->searchPerformed()->create(['occurred_at' => now()->subDays(45)]);

        $response = $this->get(PlatformMarketplaceOverviewPage::getUrl());

        $response->assertOk();
        $response->assertSee('Searches: 1', false);
        $response->assertSee('Profile views: 1', false);
    }

    public function test_ai_section_reports_kill_switch_status_and_discloses_unavailable_metrics(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformMarketplaceOverviewPage::getUrl());

        $response->assertOk();
        $response->assertSee('Platform AI status: Enabled', false);
        $response->assertSee('Calls during period: not available', false);
    }
}
