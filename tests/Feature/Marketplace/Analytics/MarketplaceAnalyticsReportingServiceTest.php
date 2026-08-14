<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Analytics;

use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Enums\MarketplaceAnalyticsEventType;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceAnalyticsEvent;
use App\Marketplace\Services\MarketplaceAnalyticsReportingService;
use App\Models\PracticeArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * MarketplaceAnalyticsReportingServiceTest — Mission 2 (MyAttorney
 * Marketplace Core), checkpoint 13. Aggregate-query correctness: time-
 * window boundaries, top-N ordering, and that a row outside the
 * requested window (or for the wrong event type) never leaks into a
 * count/ranking it doesn't belong in.
 */
class MarketplaceAnalyticsReportingServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceAnalyticsReportingService $reporting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reporting = app(MarketplaceAnalyticsReportingService::class);
    }

    public function test_total_views_since_counts_both_firm_and_attorney_views_but_not_searches(): void
    {
        $since = Carbon::now()->subDays(7);

        MarketplaceAnalyticsEvent::factory()->firmProfileViewed()->create(['occurred_at' => now()]);
        MarketplaceAnalyticsEvent::factory()->attorneyProfileViewed()->create(['occurred_at' => now()]);
        MarketplaceAnalyticsEvent::factory()->searchPerformed()->create(['occurred_at' => now()]);

        $this->assertSame(2, $this->reporting->totalViewsSince($since));
        $this->assertSame(1, $this->reporting->totalSearchesSince($since));
    }

    public function test_total_views_since_excludes_events_before_the_window(): void
    {
        MarketplaceAnalyticsEvent::factory()->firmProfileViewed()->create(['occurred_at' => now()->subDays(40)]);

        $this->assertSame(0, $this->reporting->totalViewsSince(Carbon::now()->subDays(30)));
    }

    public function test_top_viewed_firms_ranks_by_view_count_descending(): void
    {
        $popular = DirectoryFirm::factory()->create(['display_name' => 'Popular Firm']);
        $quiet = DirectoryFirm::factory()->create(['display_name' => 'Quiet Firm']);

        MarketplaceAnalyticsEvent::factory()->count(3)->firmProfileViewed()->create(['subject_id' => $popular->id, 'occurred_at' => now()]);
        MarketplaceAnalyticsEvent::factory()->count(1)->firmProfileViewed()->create(['subject_id' => $quiet->id, 'occurred_at' => now()]);

        $rows = $this->reporting->topViewedFirms(Carbon::now()->subDays(7));

        $this->assertSame('Popular Firm', $rows->first()['firm']->display_name);
        $this->assertSame(3, $rows->first()['views']);
        $this->assertSame('Quiet Firm', $rows->last()['firm']->display_name);
    }

    public function test_top_viewed_attorneys_ranks_by_view_count_descending(): void
    {
        $popular = DirectoryAttorney::factory()->create(['name' => 'Popular Attorney']);

        MarketplaceAnalyticsEvent::factory()->count(2)->attorneyProfileViewed()->create(['subject_id' => $popular->id, 'occurred_at' => now()]);

        $rows = $this->reporting->topViewedAttorneys(Carbon::now()->subDays(7));

        $this->assertSame('Popular Attorney', $rows->first()['attorney']->name);
        $this->assertSame(2, $rows->first()['views']);
    }

    public function test_top_searched_practice_areas_groups_and_counts_correctly(): void
    {
        MarketplaceAnalyticsEvent::factory()->count(2)->searchPerformed(['practice_area_slug' => 'family-law'])->create(['occurred_at' => now()]);
        MarketplaceAnalyticsEvent::factory()->searchPerformed(['practice_area_slug' => 'immigration'])->create(['occurred_at' => now()]);
        MarketplaceAnalyticsEvent::factory()->searchPerformed(['city' => 'Detroit'])->create(['occurred_at' => now()]);

        $rows = $this->reporting->topSearchedPracticeAreas(Carbon::now()->subDays(7));

        $this->assertSame('family-law', $rows->first()['practice_area_slug']);
        $this->assertSame(2, $rows->first()['searches']);
        $this->assertCount(2, $rows);
    }

    public function test_top_searched_cities_groups_and_counts_correctly(): void
    {
        MarketplaceAnalyticsEvent::factory()->count(3)->searchPerformed(['city' => 'Detroit'])->create(['occurred_at' => now()]);
        MarketplaceAnalyticsEvent::factory()->searchPerformed(['city' => 'Lansing'])->create(['occurred_at' => now()]);

        $rows = $this->reporting->topSearchedCities(Carbon::now()->subDays(7));

        $this->assertSame('Detroit', $rows->first()['city']);
        $this->assertSame(3, $rows->first()['searches']);
    }

    public function test_reporting_methods_never_expose_a_raw_event_row(): void
    {
        MarketplaceAnalyticsEvent::factory()->firmProfileViewed()->create(['occurred_at' => now()]);

        $rows = $this->reporting->topViewedFirms(Carbon::now()->subDays(7));

        $this->assertArrayNotHasKey('id', $rows->first());
        $this->assertArrayNotHasKey('subject_id', $rows->first());
    }

    public function test_total_intake_funnel_counts_are_independent_per_stage(): void
    {
        $since = Carbon::now()->subDays(7);

        MarketplaceAnalyticsEvent::factory()->count(5)->create(['event_type' => MarketplaceAnalyticsEventType::IntakeStarted, 'subject_type' => null, 'subject_id' => null, 'occurred_at' => now()]);
        MarketplaceAnalyticsEvent::factory()->count(4)->create(['event_type' => MarketplaceAnalyticsEventType::IntakeSubmitted, 'subject_type' => null, 'subject_id' => null, 'occurred_at' => now()]);
        MarketplaceAnalyticsEvent::factory()->count(3)->create(['event_type' => MarketplaceAnalyticsEventType::IntakeAccepted, 'subject_type' => null, 'subject_id' => null, 'occurred_at' => now()]);
        MarketplaceAnalyticsEvent::factory()->count(1)->create(['event_type' => MarketplaceAnalyticsEventType::IntakeDeclined, 'subject_type' => null, 'subject_id' => null, 'occurred_at' => now()]);
        MarketplaceAnalyticsEvent::factory()->count(2)->create(['event_type' => MarketplaceAnalyticsEventType::IntakeConverted, 'subject_type' => null, 'subject_id' => null, 'occurred_at' => now()]);

        $this->assertSame(5, $this->reporting->totalIntakesStartedSince($since));
        $this->assertSame(4, $this->reporting->totalIntakesSubmittedSince($since));
        $this->assertSame(3, $this->reporting->totalIntakesAcceptedSince($since));
        $this->assertSame(1, $this->reporting->totalIntakesDeclinedSince($since));
        $this->assertSame(2, $this->reporting->totalIntakesConvertedSince($since));
    }

    public function test_intake_funnel_counts_exclude_events_before_the_window(): void
    {
        MarketplaceAnalyticsEvent::factory()->create(['event_type' => MarketplaceAnalyticsEventType::IntakeStarted, 'subject_type' => null, 'subject_id' => null, 'occurred_at' => now()->subDays(40)]);

        $this->assertSame(0, $this->reporting->totalIntakesStartedSince(Carbon::now()->subDays(30)));
    }

    /**
     * SuperAdmin console professionalization mission (MYAT7) coverage
     * for the 6 methods added to support previous-period comparison and
     * demand-vs-supply/directory-performance breakdowns.
     */
    public function test_total_views_and_searches_between_only_counts_events_inside_the_bounded_window(): void
    {
        MarketplaceAnalyticsEvent::factory()->firmProfileViewed()->create(['occurred_at' => now()->subDays(20)]);
        MarketplaceAnalyticsEvent::factory()->firmProfileViewed()->create(['occurred_at' => now()->subDays(5)]);
        MarketplaceAnalyticsEvent::factory()->searchPerformed()->create(['occurred_at' => now()->subDays(20)]);

        $from = Carbon::now()->subDays(25);
        $to = Carbon::now()->subDays(10);

        $this->assertSame(1, $this->reporting->totalViewsBetween($from, $to));
        $this->assertSame(1, $this->reporting->totalSearchesBetween($from, $to));
    }

    public function test_firm_views_by_claim_status_groups_by_the_firms_current_flag(): void
    {
        $claimed = DirectoryFirm::factory()->create(['is_claimed' => true]);
        $unclaimed = DirectoryFirm::factory()->create(['is_claimed' => false]);

        MarketplaceAnalyticsEvent::factory()->count(2)->firmProfileViewed()->create(['subject_id' => $claimed->id, 'occurred_at' => now()]);
        MarketplaceAnalyticsEvent::factory()->firmProfileViewed()->create(['subject_id' => $unclaimed->id, 'occurred_at' => now()]);

        $rows = $this->reporting->firmViewsByClaimStatus(Carbon::now()->subDays(7));

        $this->assertSame(2, $rows['true']);
        $this->assertSame(1, $rows['false']);
    }

    public function test_firm_views_by_member_and_accepting_inquiries_status(): void
    {
        $member = DirectoryFirm::factory()->create(['is_marketplace_member' => true, 'accepting_inquiries' => false]);
        $nonMember = DirectoryFirm::factory()->create(['is_marketplace_member' => false, 'accepting_inquiries' => true]);

        MarketplaceAnalyticsEvent::factory()->firmProfileViewed()->create(['subject_id' => $member->id, 'occurred_at' => now()]);
        MarketplaceAnalyticsEvent::factory()->firmProfileViewed()->create(['subject_id' => $nonMember->id, 'occurred_at' => now()]);

        $memberRows = $this->reporting->firmViewsByMemberStatus(Carbon::now()->subDays(7));
        $acceptingRows = $this->reporting->firmViewsByAcceptingInquiriesStatus(Carbon::now()->subDays(7));

        $this->assertSame(1, $memberRows['true']);
        $this->assertSame(1, $memberRows['false']);
        $this->assertSame(1, $acceptingRows['true']);
        $this->assertSame(1, $acceptingRows['false']);
    }

    public function test_demand_vs_supply_by_practice_area_counts_published_firms_offering_the_searched_area(): void
    {
        $practiceArea = PracticeArea::factory()->create(['slug' => 'mya-test-practice-area', 'is_active' => true]);
        $publishedFirm = DirectoryFirm::factory()->create(['publication_state' => DirectoryPublicationState::Published]);
        $publishedFirm->practiceAreas()->attach($practiceArea->id, ['source_type' => 'admin_entered']);

        MarketplaceAnalyticsEvent::factory()->count(3)->searchPerformed(['practice_area_slug' => 'mya-test-practice-area'])->create(['occurred_at' => now()]);
        MarketplaceAnalyticsEvent::factory()->searchPerformed(['practice_area_slug' => 'no-supply-area'])->create(['occurred_at' => now()]);

        $rows = $this->reporting->demandVsSupplyByPracticeArea(Carbon::now()->subDays(7));

        $familyLaw = $rows->firstWhere('practice_area_slug', 'mya-test-practice-area');
        $noSupply = $rows->firstWhere('practice_area_slug', 'no-supply-area');

        $this->assertSame(3, $familyLaw['searches']);
        $this->assertSame(1, $familyLaw['published_firms']);
        $this->assertSame(1, $noSupply['searches']);
        $this->assertSame(0, $noSupply['published_firms']);
    }
}
