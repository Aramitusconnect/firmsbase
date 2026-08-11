<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Analytics;

use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Enums\MarketplaceAnalyticsEventType;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceAnalyticsEvent;
use App\Models\PracticeArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MarketplaceAnalyticsIntegrationTest — Mission 2 (MyAttorney
 * Marketplace Core), checkpoint 13. HTTP-level proof that the three
 * public MyAttorney controllers actually call
 * MarketplaceAnalyticsService — not just that the service works in
 * isolation.
 */
class MarketplaceAnalyticsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewing_a_published_firm_profile_records_a_view_event(): void
    {
        $firm = DirectoryFirm::factory()->create(['slug' => 'analytics-firm']);

        $this->get($this->myAttorneyUrl('/firms/analytics-firm'))->assertOk();

        $event = MarketplaceAnalyticsEvent::query()->sole();
        $this->assertSame(MarketplaceAnalyticsEventType::FirmProfileViewed, $event->event_type);
        $this->assertSame($firm->id, $event->subject_id);
    }

    public function test_viewing_a_draft_firm_profile_404s_and_records_nothing(): void
    {
        DirectoryFirm::factory()->draft()->create(['slug' => 'draft-analytics-firm']);

        $this->get($this->myAttorneyUrl('/firms/draft-analytics-firm'))->assertNotFound();

        $this->assertSame(0, MarketplaceAnalyticsEvent::query()->count());
    }

    public function test_viewing_a_published_attorney_profile_records_a_view_event(): void
    {
        $attorney = DirectoryAttorney::factory()->create(['slug' => 'analytics-attorney']);

        $this->get($this->myAttorneyUrl('/attorneys/analytics-attorney'))->assertOk();

        $event = MarketplaceAnalyticsEvent::query()->sole();
        $this->assertSame(MarketplaceAnalyticsEventType::AttorneyProfileViewed, $event->event_type);
        $this->assertSame($attorney->id, $event->subject_id);
    }

    public function test_a_real_search_records_a_search_performed_event(): void
    {
        $category = PracticeArea::query()->where('code', 'family-law')->firstOrFail();
        DirectoryFirm::factory()->create(['publication_state' => DirectoryPublicationState::Published])
            ->practiceAreas()->attach($category->id, ['source_type' => 'admin_entered']);

        $this->get($this->myAttorneyUrl('/?practice_area=family-law'))->assertOk();

        $event = MarketplaceAnalyticsEvent::query()->sole();
        $this->assertSame(MarketplaceAnalyticsEventType::SearchPerformed, $event->event_type);
        $this->assertSame('family-law', $event->dimensions['practice_area_slug']);
    }

    public function test_a_blank_landing_page_visit_records_nothing(): void
    {
        $this->get($this->myAttorneyUrl('/'))->assertOk();

        $this->assertSame(0, MarketplaceAnalyticsEvent::query()->count());
    }

    public function test_requesting_page_two_of_the_same_search_does_not_record_a_second_event(): void
    {
        $this->get($this->myAttorneyUrl('/?practice_area=family-law'))->assertOk();
        $this->get($this->myAttorneyUrl('/?practice_area=family-law&page=2'))->assertOk();

        $this->assertSame(1, MarketplaceAnalyticsEvent::query()->count());
    }
}
