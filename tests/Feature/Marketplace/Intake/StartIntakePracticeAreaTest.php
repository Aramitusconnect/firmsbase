<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Services\MarketplaceIntakeEligibilityService;
use App\Models\Firm;
use App\Models\IntakeTemplate;
use App\Models\PracticeArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Practice area is chosen when the intake starts, not repaired at conversion.
 *
 * Every intake the public flow used to create carried practice_area_id = null,
 * which offered zero Matter Types at conversion and stranded the prospect at
 * the last step of the funnel. These pin the three cases and, just as
 * importantly, that a visitor cannot file an intake under a practice area the
 * firm never published.
 */
final class StartIntakePracticeAreaTest extends TestCase
{
    use RefreshDatabase;

    private function listing(int $areaCount = 1, bool $marketplaceVisible = true, bool $active = true): array
    {
        IntakeTemplate::factory()->marketplaceDefault()->create(['is_active' => true]);

        $firm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->member()->create([
            'firm_id' => $firm->id,
            'accepting_inquiries' => true,
        ]);

        $areas = PracticeArea::factory()->count($areaCount)->create([
            'is_active' => $active,
            'is_marketplace_visible' => $marketplaceVisible,
        ]);

        $directoryFirm->practiceAreas()->sync(
            $areas->mapWithKeys(fn ($a) => [$a->id => ['source_type' => 'firm_submitted']])->all()
        );

        return [$directoryFirm, $areas];
    }

    private function start(DirectoryFirm $firm, array $payload = [])
    {
        return $this->post($this->myAttorneyUrl("/firms/{$firm->slug}/start-intake"), $payload);
    }

    // -----------------------------------------------------------------
    // Case A — exactly one published area
    // -----------------------------------------------------------------

    public function test_a_firm_with_one_practice_area_starts_the_intake_without_asking(): void
    {
        // A selector with a single option is a click that teaches nobody
        // anything, so the visitor goes straight to the questionnaire.
        [$directoryFirm, $areas] = $this->listing(areaCount: 1);

        $response = $this->start($directoryFirm);

        $response->assertRedirect();
        $this->assertStringContainsString('/intake/', $response->headers->get('Location'));

        $intake = $this->runWithFirmContext($directoryFirm->firm, fn () => MarketplaceIntake::query()->latest('id')->first());
        $this->assertSame($areas->first()->id, $intake->practice_area_id);
    }

    // -----------------------------------------------------------------
    // Case B — several published areas
    // -----------------------------------------------------------------

    public function test_a_firm_with_several_practice_areas_asks_before_creating_anything(): void
    {
        [$directoryFirm, $areas] = $this->listing(areaCount: 3);

        $response = $this->start($directoryFirm);

        $response->assertRedirect($this->myAttorneyUrl("/firms/{$directoryFirm->slug}/start-intake"));

        // The whole point of asking first: an abandoned choice leaves no row.
        $this->assertSame(0, $this->runWithFirmContext($directoryFirm->firm, fn () => MarketplaceIntake::query()->count()));

        $chooser = $this->get($this->myAttorneyUrl("/firms/{$directoryFirm->slug}/start-intake"));
        $chooser->assertOk();
        $chooser->assertSee('What do you need help with?');

        foreach ($areas as $area) {
            $chooser->assertSee($area->name);
        }
    }

    public function test_the_visitors_choice_is_the_one_recorded(): void
    {
        [$directoryFirm, $areas] = $this->listing(areaCount: 3);
        $chosen = $areas->last();

        $response = $this->start($directoryFirm, ['practice_area_id' => $chosen->id]);

        $response->assertRedirect();
        $intake = $this->runWithFirmContext($directoryFirm->firm, fn () => MarketplaceIntake::query()->latest('id')->first());
        $this->assertSame($chosen->id, $intake->practice_area_id);
    }

    // -----------------------------------------------------------------
    // Case C — none published
    // -----------------------------------------------------------------

    public function test_a_firm_with_no_published_practice_area_creates_no_intake_at_all(): void
    {
        // Creating one anyway would move the dead end further down the funnel,
        // where it costs the prospect their time instead of a page load.
        [$directoryFirm] = $this->listing(areaCount: 0);

        $response = $this->start($directoryFirm);

        $response->assertRedirect($this->myAttorneyUrl("/firms/{$directoryFirm->slug}"));
        $response->assertSessionHas('intake_unavailable');
        $this->assertSame(0, $this->runWithFirmContext($directoryFirm->firm, fn () => MarketplaceIntake::query()->count()));
    }

    public function test_no_platform_wide_default_is_ever_substituted(): void
    {
        // Practice areas exist in the platform taxonomy; the firm simply
        // published none. Filing a real matter under a specialization the firm
        // never claimed would be worse than not starting.
        PracticeArea::factory()->count(5)->create(['is_active' => true, 'is_marketplace_visible' => true]);
        [$directoryFirm] = $this->listing(areaCount: 0);

        $this->start($directoryFirm);

        $this->assertSame(0, $this->runWithFirmContext($directoryFirm->firm, fn () => MarketplaceIntake::query()->count()));
    }

    // -----------------------------------------------------------------
    // Trust boundary
    // -----------------------------------------------------------------

    public function test_a_forged_practice_area_id_is_refused(): void
    {
        [$directoryFirm] = $this->listing(areaCount: 2);

        $response = $this->start($directoryFirm, ['practice_area_id' => 999999]);

        $response->assertRedirect($this->myAttorneyUrl("/firms/{$directoryFirm->slug}/start-intake"));
        $this->assertSame(0, $this->runWithFirmContext($directoryFirm->firm, fn () => MarketplaceIntake::query()->count()));
    }

    public function test_a_practice_area_this_firm_does_not_publish_is_refused(): void
    {
        // Real, active, marketplace-visible — but belonging to another firm's
        // listing. Membership is what is checked, not existence.
        [$directoryFirm] = $this->listing(areaCount: 2);
        [, $otherFirmAreas] = $this->listing(areaCount: 1);

        $response = $this->start($directoryFirm, ['practice_area_id' => $otherFirmAreas->first()->id]);

        $response->assertRedirect($this->myAttorneyUrl("/firms/{$directoryFirm->slug}/start-intake"));
        $this->assertSame(0, $this->runWithFirmContext($directoryFirm->firm, fn () => MarketplaceIntake::query()->where('directory_firm_id', $directoryFirm->id)->count()));
    }

    public function test_an_unpublished_practice_area_is_not_eligible_even_when_associated(): void
    {
        // The firm may factually practise in an area without marketing it —
        // is_marketplace_visible is deliberately independent of is_active.
        [$directoryFirm, $areas] = $this->listing(areaCount: 1, marketplaceVisible: false);

        $this->assertTrue(
            app(MarketplaceIntakeEligibilityService::class)->eligiblePracticeAreas($directoryFirm)->isEmpty(),
            'An area the firm has not published must not be offered to a visitor.',
        );

        $response = $this->start($directoryFirm, ['practice_area_id' => $areas->first()->id]);

        $response->assertRedirect($this->myAttorneyUrl("/firms/{$directoryFirm->slug}"));
        $this->assertSame(0, $this->runWithFirmContext($directoryFirm->firm, fn () => MarketplaceIntake::query()->count()));
    }

    public function test_an_inactive_practice_area_is_not_eligible(): void
    {
        // Matter types hang off the practice area, so an inactive one would
        // produce an intake nothing can be converted into.
        [$directoryFirm] = $this->listing(areaCount: 1, active: false);

        $this->assertTrue(app(MarketplaceIntakeEligibilityService::class)->eligiblePracticeAreas($directoryFirm)->isEmpty());
    }

    public function test_the_chooser_404s_for_a_hidden_listing_exactly_like_an_unknown_one(): void
    {
        [$directoryFirm] = $this->listing(areaCount: 2);
        $directoryFirm->update(['publication_state' => DirectoryPublicationState::Draft]);

        $this->get($this->myAttorneyUrl("/firms/{$directoryFirm->slug}/start-intake"))->assertNotFound();
        $this->get($this->myAttorneyUrl('/firms/no-such-firm-at-all/start-intake'))->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Persistence through the funnel
    // -----------------------------------------------------------------

    public function test_the_practice_area_survives_a_resume(): void
    {
        [$directoryFirm, $areas] = $this->listing(areaCount: 2);

        $this->start($directoryFirm, ['practice_area_id' => $areas->first()->id]);

        $intake = $this->runWithFirmContext($directoryFirm->firm, fn () => MarketplaceIntake::query()->latest('id')->first());
        $reloaded = $this->runWithFirmContext($directoryFirm->firm, fn () => MarketplaceIntake::query()->where('uuid', $intake->uuid)->first());

        $this->assertSame($areas->first()->id, $reloaded->practice_area_id);
    }

    public function test_the_template_is_resolved_from_the_chosen_practice_area(): void
    {
        // Practice-area-aware template resolution already exists; this proves
        // the new selection actually feeds it rather than bypassing it.
        [$directoryFirm, $areas] = $this->listing(areaCount: 1);
        $specific = IntakeTemplate::factory()->create([
            'is_active' => true,
            'practice_area_id' => $areas->first()->id,
        ]);

        $this->start($directoryFirm);

        $intake = $this->runWithFirmContext($directoryFirm->firm, fn () => MarketplaceIntake::query()->latest('id')->first());
        $this->assertSame($specific->id, $intake->intake_template_id);
    }
}
