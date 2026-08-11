<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Analytics;

use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceAnalyticsEvent;
use App\Marketplace\Services\MarketplaceAnalyticsReportingService;
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
}
