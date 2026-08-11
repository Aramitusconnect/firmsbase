<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Analytics;

use App\Marketplace\Enums\ConsultationMode;
use App\Marketplace\Enums\MarketplaceAnalyticsEventType;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceAnalyticsEvent;
use App\Marketplace\Services\MarketplaceAnalyticsService;
use App\Marketplace\ViewModels\SearchCriteria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MarketplaceAnalyticsServiceTest — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 13. Proves the recorder writes exactly the
 * privacy-conscious shape the migration/model docblocks promise: no
 * free-text search query, no lat/lng/postal code, no actor of any
 * kind — only coarse, already-public taxonomy facets.
 */
class MarketplaceAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceAnalyticsService $analytics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analytics = app(MarketplaceAnalyticsService::class);
    }

    public function test_records_a_firm_profile_view(): void
    {
        $firm = DirectoryFirm::factory()->create();

        $this->analytics->recordFirmProfileView($firm);

        $event = MarketplaceAnalyticsEvent::query()->sole();
        $this->assertSame(MarketplaceAnalyticsEventType::FirmProfileViewed, $event->event_type);
        $this->assertSame(DirectoryFirm::class, $event->subject_type);
        $this->assertSame($firm->id, $event->subject_id);
        $this->assertNull($event->dimensions);
    }

    public function test_records_an_attorney_profile_view(): void
    {
        $attorney = DirectoryAttorney::factory()->create();

        $this->analytics->recordAttorneyProfileView($attorney);

        $event = MarketplaceAnalyticsEvent::query()->sole();
        $this->assertSame(MarketplaceAnalyticsEventType::AttorneyProfileViewed, $event->event_type);
        $this->assertSame(DirectoryAttorney::class, $event->subject_type);
        $this->assertSame($attorney->id, $event->subject_id);
    }

    public function test_records_a_search_with_only_coarse_taxonomy_dimensions(): void
    {
        $criteria = new SearchCriteria(
            name: 'A Specific Person Name',
            practiceAreaSlug: 'family-law',
            city: 'Detroit',
            state: 'MI',
            postalCode: '48201',
            languageCode: 'es',
            acceptingInquiriesOnly: true,
            consultationMode: ConsultationMode::Video,
            originLatitude: 42.3314,
            originLongitude: -83.0458,
        );

        $this->analytics->recordSearchPerformed($criteria);

        $event = MarketplaceAnalyticsEvent::query()->sole();
        $this->assertSame(MarketplaceAnalyticsEventType::SearchPerformed, $event->event_type);
        $this->assertNull($event->subject_type);
        $this->assertNull($event->subject_id);

        $dimensions = $event->dimensions;
        $this->assertSame('family-law', $dimensions['practice_area_slug']);
        $this->assertSame('Detroit', $dimensions['city']);
        $this->assertSame('MI', $dimensions['state']);
        $this->assertSame('es', $dimensions['language_code']);
        $this->assertSame('video', $dimensions['consultation_mode']);
        $this->assertTrue($dimensions['accepting_inquiries_only']);

        // The privacy-sensitive/too-granular fields must never appear.
        $this->assertArrayNotHasKey('name', $dimensions);
        $this->assertArrayNotHasKey('postal_code', $dimensions);
        $this->assertArrayNotHasKey('lat', $dimensions);
        $this->assertArrayNotHasKey('originLatitude', $dimensions);
        $this->assertArrayNotHasKey('lng', $dimensions);
        $this->assertArrayNotHasKey('originLongitude', $dimensions);
        $this->assertStringNotContainsString('Specific Person Name', json_encode($dimensions));
    }

    public function test_a_blank_search_records_null_dimensions_not_an_empty_array(): void
    {
        $this->analytics->recordSearchPerformed(new SearchCriteria);

        $event = MarketplaceAnalyticsEvent::query()->sole();
        $this->assertNull($event->dimensions);
    }

    public function test_no_event_column_ever_stores_an_ip_address_session_id_or_actor(): void
    {
        $columns = Schema::getColumnListing('directory_marketplace_analytics_events');

        foreach (['ip_address', 'ip', 'session_id', 'actor_type', 'actor_id', 'user_agent', 'referrer'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "directory_marketplace_analytics_events must never gain a {$forbidden} column.");
        }
    }
}
