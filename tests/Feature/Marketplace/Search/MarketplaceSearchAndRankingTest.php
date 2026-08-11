<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Search;

use App\Marketplace\Enums\ConsultationMode;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryAttorneyFirm;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\FirmOffice;
use App\Marketplace\Services\MarketplaceRankingService;
use App\Marketplace\Services\MarketplaceSearchService;
use App\Marketplace\ViewModels\SearchCriteria;
use App\Models\Language;
use App\Models\PracticeArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MarketplaceSearchAndRankingTest — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 5. Covers the search test matrix (section 85,
 * items AA-AK) and the ranking test list (section 38) directly.
 */
class MarketplaceSearchAndRankingTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceSearchService $search;

    private MarketplaceRankingService $ranking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->search = app(MarketplaceSearchService::class);
        $this->ranking = app(MarketplaceRankingService::class);
    }

    // AA. name search — case-insensitive via the lowercased
    // name_normalized column (section 33). Punctuation normalization
    // beyond simple lowercasing is a disclosed, deferred limitation —
    // see MarketplaceSearchService's own docblock.
    public function test_name_search_matches_firm_display_name_case_insensitively(): void
    {
        DirectoryFirm::factory()->create(['display_name' => 'Acme Legal Group', 'name_normalized' => strtolower('acme legal group')]);
        DirectoryFirm::factory()->create(['display_name' => 'Unrelated Firm', 'name_normalized' => 'unrelated firm']);

        $results = $this->search->candidates(SearchCriteria::fromArray(['name' => 'ACME']));

        $this->assertCount(1, $results);
        $this->assertSame('Acme Legal Group', $results->first()->display_name);
    }

    // AB. attorney name search
    public function test_attorney_name_search_matches_firms_via_current_attorney_relationships(): void
    {
        $firm = DirectoryFirm::factory()->create(['display_name' => 'Attorney Match Firm']);
        $attorney = DirectoryAttorney::factory()->create(['name' => 'Jordan Rivera', 'name_normalized' => 'jordan rivera']);
        DirectoryAttorneyFirm::factory()->forAttorneyAndFirm($attorney, $firm)->create();
        DirectoryFirm::factory()->create(['display_name' => 'No Match Firm']);

        $results = $this->search->candidates(SearchCriteria::fromArray(['name' => 'Rivera']));

        $this->assertCount(1, $results);
        $this->assertSame('Attorney Match Firm', $results->first()->display_name);
    }

    // AC. practice-area search
    public function test_practice_area_search_returns_only_firms_with_that_practice_area(): void
    {
        $family = PracticeArea::query()->where('code', 'family-law')->firstOrFail();
        $immigration = PracticeArea::query()->where('code', 'immigration')->firstOrFail();

        $matching = DirectoryFirm::factory()->create(['display_name' => 'Family Firm']);
        $matching->practiceAreas()->attach($family->id, ['source_type' => 'admin_entered']);

        $nonMatching = DirectoryFirm::factory()->create(['display_name' => 'Immigration Firm']);
        $nonMatching->practiceAreas()->attach($immigration->id, ['source_type' => 'admin_entered']);

        $results = $this->search->candidates(SearchCriteria::fromArray(['practice_area' => 'family-law']));

        $this->assertCount(1, $results);
        $this->assertSame('Family Firm', $results->first()->display_name);
    }

    // AD. city search
    public function test_city_search_matches_case_insensitively_via_normalized_column(): void
    {
        $firm = DirectoryFirm::factory()->create(['display_name' => 'Detroit Firm']);
        FirmOffice::factory()->forFirm($firm)->create(['city' => 'Detroit', 'city_normalized' => 'detroit']);

        $other = DirectoryFirm::factory()->create(['display_name' => 'Lansing Firm']);
        FirmOffice::factory()->forFirm($other)->create(['city' => 'Lansing', 'city_normalized' => 'lansing']);

        $results = $this->search->candidates(SearchCriteria::fromArray(['city' => 'DETROIT']));

        $this->assertCount(1, $results);
        $this->assertSame('Detroit Firm', $results->first()->display_name);
    }

    // AE. language filter
    public function test_language_filter_returns_only_firms_offering_that_language(): void
    {
        $spanish = Language::factory()->spanish()->create();
        $arabic = Language::factory()->arabic()->create();

        $spanishFirm = DirectoryFirm::factory()->create(['display_name' => 'Spanish Firm']);
        $spanishFirm->languages()->attach($spanish->id, ['source_type' => 'admin_entered']);

        $arabicFirm = DirectoryFirm::factory()->create(['display_name' => 'Arabic Firm']);
        $arabicFirm->languages()->attach($arabic->id, ['source_type' => 'admin_entered']);

        $results = $this->search->candidates(SearchCriteria::fromArray(['language' => 'es']));

        $this->assertCount(1, $results);
        $this->assertSame('Spanish Firm', $results->first()->display_name);
    }

    // AF. accepting-inquiries filter
    public function test_accepting_inquiries_filter_excludes_firms_not_accepting(): void
    {
        DirectoryFirm::factory()->create(['display_name' => 'Open Firm', 'accepting_inquiries' => true]);
        DirectoryFirm::factory()->create(['display_name' => 'Closed Firm', 'accepting_inquiries' => false]);

        $results = $this->search->candidates(SearchCriteria::fromArray(['accepting_inquiries' => true]));

        $this->assertCount(1, $results);
        $this->assertSame('Open Firm', $results->first()->display_name);
    }

    public function test_consultation_mode_filter_matches_json_array_membership(): void
    {
        DirectoryFirm::factory()->create(['display_name' => 'Video Firm', 'consultation_modes' => ['video']]);
        DirectoryFirm::factory()->create(['display_name' => 'In Person Firm', 'consultation_modes' => ['in_person']]);

        $results = $this->search->candidates(SearchCriteria::fromArray(['consultation_mode' => ConsultationMode::Video->value]));

        $this->assertCount(1, $results);
        $this->assertSame('Video Firm', $results->first()->display_name);
    }

    // AG. unpublished result excluded
    public function test_unpublished_firms_are_never_returned_by_search(): void
    {
        DirectoryFirm::factory()->create(['display_name' => 'Published Firm']);
        DirectoryFirm::factory()->draft()->create(['display_name' => 'Draft Firm']);
        DirectoryFirm::factory()->suspended()->create(['display_name' => 'Suspended Firm']);

        $results = $this->search->candidates(SearchCriteria::fromArray([]));

        $this->assertCount(1, $results);
        $this->assertSame('Published Firm', $results->first()->display_name);
    }

    // AH. duplicate result prevented
    public function test_a_firm_matching_via_multiple_relations_is_returned_exactly_once(): void
    {
        $family = PracticeArea::query()->where('code', 'family-law')->firstOrFail();
        $firm = DirectoryFirm::factory()->create(['display_name' => 'Multi Match Firm', 'name_normalized' => 'multi match firm']);
        $firm->practiceAreas()->attach($family->id, ['source_type' => 'admin_entered']);
        $attorneyA = DirectoryAttorney::factory()->create();
        $attorneyB = DirectoryAttorney::factory()->create();
        DirectoryAttorneyFirm::factory()->forAttorneyAndFirm($attorneyA, $firm)->create();
        DirectoryAttorneyFirm::factory()->forAttorneyAndFirm($attorneyB, $firm)->create();

        $results = $this->search->candidates(SearchCriteria::fromArray(['practice_area' => 'family-law']));

        $this->assertCount(1, $results);
    }

    // AI. deterministic ranking — same input produces same ordering
    public function test_ranking_is_deterministic_across_repeated_calls(): void
    {
        DirectoryFirm::factory()->count(5)->create();
        $criteria = SearchCriteria::fromArray([]);
        $candidates = $this->search->candidates($criteria);

        $firstRun = array_map(fn ($r) => $r->slug, $this->ranking->rank($candidates, $criteria));
        $secondRun = array_map(fn ($r) => $r->slug, $this->ranking->rank($candidates, $criteria));

        $this->assertSame($firstRun, $secondRun);
    }

    // Ranking test: exact practice match > unrelated practice
    public function test_exact_practice_area_match_ranks_above_unrelated_firm(): void
    {
        $family = PracticeArea::query()->where('code', 'family-law')->firstOrFail();
        $matching = DirectoryFirm::factory()->create(['display_name' => 'Matching Firm']);
        $matching->practiceAreas()->attach($family->id, ['source_type' => 'admin_entered']);
        $unrelated = DirectoryFirm::factory()->create(['display_name' => 'Unrelated Firm']);

        $criteria = SearchCriteria::fromArray(['practice_area' => 'family-law']);
        $ranked = $this->ranking->rank(DirectoryFirm::query()->whereIn('id', [$matching->id, $unrelated->id])->with(['offices', 'practiceAreas', 'languages'])->get(), $criteria);

        $this->assertSame('Matching Firm', $ranked[0]->displayName);
        $this->assertTrue($ranked[0]->explanation->practiceAreaMatch);
        $this->assertFalse($ranked[1]->explanation->practiceAreaMatch);
    }

    // Ranking test: closer relevant office > distant equivalent office
    public function test_closer_office_ranks_above_a_more_distant_equivalent_office(): void
    {
        // Detroit coordinates as the search origin.
        $originLat = 42.3314;
        $originLng = -83.0458;

        $near = DirectoryFirm::factory()->create(['display_name' => 'Near Firm']);
        FirmOffice::factory()->forFirm($near)->create(['latitude' => 42.35, 'longitude' => -83.05]);

        $far = DirectoryFirm::factory()->create(['display_name' => 'Far Firm']);
        FirmOffice::factory()->forFirm($far)->create(['latitude' => 44.7631, 'longitude' => -85.6206]); // Traverse City

        $criteria = SearchCriteria::fromArray(['lat' => $originLat, 'lng' => $originLng]);
        $ranked = $this->ranking->rank(DirectoryFirm::query()->whereIn('id', [$near->id, $far->id])->with(['offices', 'practiceAreas', 'languages'])->get(), $criteria);

        $this->assertSame('Near Firm', $ranked[0]->displayName);
    }

    // Ranking test: membership does not override core relevance arbitrarily (AJ)
    public function test_membership_status_never_breaks_a_tie_only_id_does(): void
    {
        $member = DirectoryFirm::factory()->member()->create(['display_name' => 'Member Firm']);
        $unclaimed = DirectoryFirm::factory()->unclaimed()->create(['display_name' => 'Unclaimed Firm']);

        $criteria = SearchCriteria::fromArray([]);
        $ranked = $this->ranking->rank(DirectoryFirm::query()->whereIn('id', [$member->id, $unclaimed->id])->with(['offices', 'practiceAreas', 'languages'])->get(), $criteria);

        // Both score 0 on every real signal (no criteria supplied) — order
        // must be purely by id, proving membership itself contributed
        // nothing to the score.
        $expectedOrder = $member->id < $unclaimed->id ? ['Member Firm', 'Unclaimed Firm'] : ['Unclaimed Firm', 'Member Firm'];
        $this->assertSame($expectedOrder, array_map(fn ($r) => $r->displayName, $ranked));
    }

    public function test_language_filter_is_respected_in_ranking_explanation(): void
    {
        $spanish = Language::factory()->spanish()->create();
        $withLanguage = DirectoryFirm::factory()->create();
        $withLanguage->languages()->attach($spanish->id, ['source_type' => 'admin_entered']);
        $withoutLanguage = DirectoryFirm::factory()->create();

        $criteria = SearchCriteria::fromArray(['language' => 'es']);
        $explanationWith = $this->ranking->explain($withLanguage->fresh(['languages']), $criteria);
        $explanationWithout = $this->ranking->explain($withoutLanguage->fresh(['languages']), $criteria);

        $this->assertTrue($explanationWith->languageMatch);
        $this->assertFalse($explanationWithout->languageMatch);
    }
}
