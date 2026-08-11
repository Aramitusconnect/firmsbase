<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\PublicProfiles;

use App\Marketplace\Enums\DirectoryAttorneyFirmRelationshipState;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryAttorneyFirm;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\FirmOffice;
use App\Models\Language;
use App\Models\PracticeArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PublicFirmAttorneyProfileTest — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 4. Real HTTP-level proof against the myattorney
 * host: published profiles render, non-published profiles 404
 * identically to non-existent ones (section 68), embedded
 * relationships respect publication/relationship state, and no
 * internal field (id, firm_id, source_reference) ever reaches the
 * response body.
 */
class PublicFirmAttorneyProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_loads_on_the_myattorney_host(): void
    {
        $response = $this->get($this->myAttorneyUrl('/'));

        $response->assertOk();
        $response->assertSee('MyAttorney', false);
    }

    public function test_a_published_firm_profile_page_loads(): void
    {
        $firm = DirectoryFirm::factory()->create([
            'display_name' => 'Acme Legal Group',
            'slug' => 'acme-legal-group',
            'publication_state' => DirectoryPublicationState::Published,
        ]);

        $response = $this->get($this->myAttorneyUrl('/firms/acme-legal-group'));

        $response->assertOk();
        $response->assertSee('Acme Legal Group', false);
    }

    public function test_a_draft_firm_profile_404s_identically_to_a_nonexistent_one(): void
    {
        DirectoryFirm::factory()->draft()->create(['slug' => 'draft-firm']);

        $draftResponse = $this->get($this->myAttorneyUrl('/firms/draft-firm'));
        $missingResponse = $this->get($this->myAttorneyUrl('/firms/does-not-exist'));

        $draftResponse->assertNotFound();
        $missingResponse->assertNotFound();
    }

    public function test_a_suspended_firm_profile_404s(): void
    {
        DirectoryFirm::factory()->suspended()->create(['slug' => 'suspended-firm']);

        $this->get($this->myAttorneyUrl('/firms/suspended-firm'))->assertNotFound();
    }

    public function test_firm_profile_shows_practice_areas_languages_offices_and_current_attorneys(): void
    {
        $firm = DirectoryFirm::factory()->create(['slug' => 'family-firm', 'display_name' => 'Family Firm PLLC']);
        $category = PracticeArea::query()->where('code', 'family-law')->firstOrFail();
        $firm->practiceAreas()->attach($category->id, ['source_type' => 'admin_entered']);
        $language = Language::factory()->spanish()->create();
        $firm->languages()->attach($language->id, ['source_type' => 'admin_entered']);
        FirmOffice::factory()->forFirm($firm)->create(['city' => 'Detroit', 'address_line1' => '123 Main St']);

        $currentAttorney = DirectoryAttorney::factory()->create(['name' => 'Jordan Rivera']);
        DirectoryAttorneyFirm::factory()->forAttorneyAndFirm($currentAttorney, $firm)->create(['relationship_state' => DirectoryAttorneyFirmRelationshipState::Current]);

        $formerAttorney = DirectoryAttorney::factory()->create(['name' => 'Taylor Former']);
        DirectoryAttorneyFirm::factory()->forAttorneyAndFirm($formerAttorney, $firm)->create(['relationship_state' => DirectoryAttorneyFirmRelationshipState::Unpublished]);

        $response = $this->get($this->myAttorneyUrl('/firms/family-firm'));

        $response->assertOk();
        $response->assertSee('Family Law', false);
        $response->assertSee('Spanish', false);
        $response->assertSee('Detroit', false);
        $response->assertSee('Jordan Rivera', false);
        $response->assertDontSee('Taylor Former', false);
    }

    public function test_firm_profile_never_leaks_internal_identifiers_or_provenance(): void
    {
        $firm = DirectoryFirm::factory()->create([
            'slug' => 'no-leak-firm',
            'source_reference' => 'internal-source-reference-marker-xyz',
        ]);

        $response = $this->get($this->myAttorneyUrl('/firms/no-leak-firm'));

        $response->assertOk();
        $response->assertDontSee((string) $firm->id, false);
        $response->assertDontSee($firm->uuid, false);
        $response->assertDontSee('internal-source-reference-marker-xyz', false);
    }

    public function test_a_published_attorney_profile_page_loads(): void
    {
        $attorney = DirectoryAttorney::factory()->create(['name' => 'Morgan Lee', 'slug' => 'morgan-lee']);

        $response = $this->get($this->myAttorneyUrl('/attorneys/morgan-lee'));

        $response->assertOk();
        $response->assertSee('Morgan Lee', false);
    }

    public function test_a_draft_attorney_profile_404s(): void
    {
        DirectoryAttorney::factory()->draft()->create(['slug' => 'draft-attorney']);

        $this->get($this->myAttorneyUrl('/attorneys/draft-attorney'))->assertNotFound();
    }

    public function test_attorney_profile_shows_only_current_and_published_firm_affiliations(): void
    {
        $attorney = DirectoryAttorney::factory()->create(['name' => 'Casey Attorney', 'slug' => 'casey-attorney']);

        $currentFirm = DirectoryFirm::factory()->create(['display_name' => 'Current Firm LLP']);
        DirectoryAttorneyFirm::factory()->forAttorneyAndFirm($attorney, $currentFirm)->create(['relationship_state' => DirectoryAttorneyFirmRelationshipState::Current]);

        $unpublishedFirm = DirectoryFirm::factory()->create(['display_name' => 'Hidden Firm LLP']);
        DirectoryAttorneyFirm::factory()->forAttorneyAndFirm($attorney, $unpublishedFirm)->create(['relationship_state' => DirectoryAttorneyFirmRelationshipState::Unpublished]);

        $draftFirm = DirectoryFirm::factory()->draft()->create(['display_name' => 'Draft Firm LLP']);
        DirectoryAttorneyFirm::factory()->forAttorneyAndFirm($attorney, $draftFirm)->create(['relationship_state' => DirectoryAttorneyFirmRelationshipState::Current]);

        $response = $this->get($this->myAttorneyUrl('/attorneys/casey-attorney'));

        $response->assertOk();
        $response->assertSee('Current Firm LLP', false);
        $response->assertDontSee('Hidden Firm LLP', false);
        $response->assertDontSee('Draft Firm LLP', false);
    }

    public function test_response_never_contains_prohibited_attorney_client_relationship_language(): void
    {
        $firm = DirectoryFirm::factory()->create(['slug' => 'wording-firm']);

        $response = $this->get($this->myAttorneyUrl('/firms/wording-firm'));

        $response->assertOk();
        foreach (['is your attorney', 'creates representation', 'guarantee acceptance', 'guaranteed result'] as $prohibited) {
            $response->assertDontSee($prohibited, false);
        }
    }
}
