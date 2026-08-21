<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Livewire\Marketplace\PublicIntakePage;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Models\Firm;
use App\Models\IntakeTemplate;
use App\Models\PracticeArea;
use App\Services\IntakeTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * The public intake wizard must actually work in a browser, not just on the
 * server.
 *
 * Found by the owner: the signed intake page rendered, "Start Secure Intake"
 * was visible, and clicking it did nothing at all — no request, no error, no
 * message. Livewire's JavaScript was never loading, because the MyAttorney
 * catch-all route is domain-scoped and Laravel resolves domain-scoped routes
 * ahead of domain-less ones: it answered /livewire/livewire.min.js with
 * "MyAttorney — coming soon." as text/html.
 *
 * Every server-side test kept passing throughout, because /livewire/update is
 * a POST and the catch-all is GET-only. Component tests call the action
 * directly and never fetch the asset that makes the button work. So the test
 * that matters here is the one that asks for the script the browser asks for.
 */
final class PublicIntakeLivewireBootTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Firm, 1: MarketplaceIntake}
     */
    private function signedIntake(): array
    {
        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create(['is_active' => true, 'is_marketplace_visible' => true]);
        $template = IntakeTemplate::factory()->marketplaceDefault()->forPracticeArea($practiceArea)->create(['is_active' => true]);
        app(IntakeTemplateService::class)->createQuestion($template, 'legal_issue', 'Describe your issue', 'textarea', isRequired: true, sortOrder: 10);

        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]);
        $directoryFirm->practiceAreas()->sync([$practiceArea->id => ['source_type' => 'firm_submitted']]);

        return [$firm, app(MarketplaceIntakeService::class)->startForDirectoryFirm($directoryFirm, $practiceArea)];
    }

    public function test_the_catch_all_never_answers_anything_under_livewire(): void
    {
        // The whole bug in one invariant. The catch-all used to answer
        // /livewire/livewire.min.js with an HTML placeholder, so the browser
        // loaded 27 bytes of prose as a script and Livewire never started.
        //
        // Asserted as "never the placeholder" rather than "always 200":
        // which asset paths exist varies with APP_DEBUG (staging serves
        // .min.js, the test environment serves livewire.js), and a 404 is an
        // honest answer while a cheerful 200 of prose is the failure mode.
        foreach (['/livewire/livewire.js', '/livewire/livewire.min.js', '/livewire/anything-else'] as $path) {
            $response = $this->get($this->myAttorneyUrl($path));

            // A streamed asset response returns false from getContent();
            // that is fine — it is self-evidently not the placeholder.
            $body = $response->baseResponse instanceof BinaryFileResponse
                ? ''
                : (string) $response->getContent();

            $this->assertNotSame(
                'MyAttorney — coming soon.',
                trim($body),
                "The catch-all must not answer {$path} — that is how Livewire silently failed to boot.",
            );
        }
    }

    public function test_the_signed_intake_page_references_a_script_that_resolves(): void
    {
        // Guards the pairing rather than the filename: whatever src the page
        // emits, that URL must serve JavaScript on this host.
        [, $intake] = $this->signedIntake();

        $page = $this->get(app(MarketplaceIntakeService::class)->signedUrl($intake));
        $page->assertOk();

        preg_match('/<script[^>]+src="([^"]+)"/', $page->getContent(), $matches);

        $this->assertNotEmpty($matches, 'The intake page must load a script, or no wire:click can ever fire.');

        $asset = $this->get($this->myAttorneyUrl(parse_url($matches[1], PHP_URL_PATH)));

        $asset->assertOk();
        $this->assertStringContainsString('javascript', (string) $asset->headers->get('Content-Type'));
    }

    public function test_the_catch_all_still_answers_everything_else(): void
    {
        // The exclusion must be narrow: the placeholder is still the response
        // for an unknown path.
        $this->get($this->myAttorneyUrl('/some-unknown-page'))->assertOk()->assertSee('coming soon');
    }

    public function test_the_disclosure_action_advances_the_wizard(): void
    {
        // The server side was always correct; pinned here so a future change
        // cannot break the transition the button triggers.
        [, $intake] = $this->signedIntake();

        Livewire::test(PublicIntakePage::class, ['uuid' => $intake->uuid])
            ->assertSet('disclosureAcknowledged', false)
            ->call('acknowledgeDisclosure')
            ->assertSet('disclosureAcknowledged', true)
            ->assertHasNoErrors();
    }

    public function test_a_tampered_signature_is_refused(): void
    {
        [, $intake] = $this->signedIntake();

        $signed = app(MarketplaceIntakeService::class)->signedUrl($intake);
        $tampered = preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $signed);

        $this->get($tampered)->assertForbidden();
    }

    public function test_an_unsigned_intake_url_is_refused(): void
    {
        [, $intake] = $this->signedIntake();

        $this->get($this->myAttorneyUrl('/intake/'.$intake->uuid))->assertForbidden();
    }
}
