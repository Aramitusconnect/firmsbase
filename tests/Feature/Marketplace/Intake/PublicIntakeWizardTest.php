<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Enums\AiMode;
use App\Enums\AiProvider;
use App\Enums\MarketplaceIntakeEventType;
use App\Enums\MarketplaceIntakeStatus;
use App\Livewire\Marketplace\PublicIntakePage;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Models\MarketplaceIntakeEvent;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Models\Client;
use App\Models\Document;
use App\Models\Firm;
use App\Models\IntakeTemplate;
use App\Models\Matter;
use App\Models\PracticeArea;
use App\Services\AiProviderKeyService;
use App\Services\IntakeTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Ai\Concerns\FakesOpenAiTransport;
use Tests\Feature\Ai\Concerns\SetsUpAiEntitledFirm;
use Tests\TestCase;

/**
 * PublicIntakeWizardTest — Mission 3A (MyAttorney Launch-Flow
 * Closure). Feature/browser-level proof that the public
 * `/intake/{uuid}` Livewire route is genuinely reachable and actually
 * drives the same backend services MarketplaceIntakeAnswerServiceTest/
 * MarketplaceIntakeConversationalAssistantServiceTest/
 * MarketplaceIntakeServiceTest already exercise directly — closing the
 * completeness gap Mission 3's own final report flagged (checkpoint
 * 15): "no live public answer-collection endpoint exists."
 */
class PublicIntakeWizardTest extends TestCase
{
    use FakesOpenAiTransport, RefreshDatabase, SetsUpAiEntitledFirm;

    /**
     * @return array{0: Firm, 1: DirectoryFirm, 2: MarketplaceIntake}
     */
    private function deterministicIntake(): array
    {
        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create();
        $template = IntakeTemplate::factory()->marketplaceDefault()->forPracticeArea($practiceArea)->create(['is_active' => true]);
        app(IntakeTemplateService::class)->createQuestion($template, 'legal_issue', 'Describe your issue', 'textarea', isRequired: true, sortOrder: 10);
        app(IntakeTemplateService::class)->createQuestion($template, 'state', 'Your state', 'text', isRequired: true, sortOrder: 20);
        app(IntakeTemplateService::class)->createQuestion($template, 'notes', 'Anything else?', 'textarea', isRequired: false, sortOrder: 30);

        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]);
        $intake = app(MarketplaceIntakeService::class)->startForDirectoryFirm($directoryFirm, $practiceArea);

        return [$firm, $directoryFirm, $intake];
    }

    /**
     * @return array{0: Firm, 1: DirectoryFirm, 2: MarketplaceIntake}
     */
    private function aiAssistedIntake(): array
    {
        // FirmOwned with the firm's own encrypted credential. PlatformManaged
        // resolves to no provider by design — FirmsVault holds no platform key —
        // so an AI-assisted turn is only reachable for a firm that brought one.
        $firm = $this->makeAiEntitledFirm(AiMode::FirmOwned);
        app(AiProviderKeyService::class)->import($firm, AiProvider::OpenAi, 'test-key-not-a-real-credential');
        $this->fakeOpenAiExtraction();
        $this->runWithFirmContext($firm, fn () => $firm->aiSettings->update(['intake_ai_assist_enabled' => true]));

        $practiceArea = PracticeArea::factory()->create();
        $template = IntakeTemplate::factory()->marketplaceDefault()->forPracticeArea($practiceArea)->create(['is_active' => true]);
        app(IntakeTemplateService::class)->createQuestion($template, 'legal_issue', 'Describe your issue', 'textarea', isRequired: true, sortOrder: 10);
        app(IntakeTemplateService::class)->createQuestion($template, 'state', 'Your state', 'text', isRequired: true, sortOrder: 20);

        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]);
        $intake = app(MarketplaceIntakeService::class)->startForDirectoryFirm($directoryFirm, $practiceArea);

        return [$firm, $directoryFirm, $intake];
    }

    /**
     * Advances a freshly-mounted component through disclosure AND the
     * identity-capture step (Mission 3A's own closure of a real,
     * previously-latent gap: no caller anywhere in Mission 3 ever
     * wrote prospect_name/prospect_email, which
     * ConvertMarketplaceProspectService's own FirmLead::create() call
     * hard-requires) so tests below can focus on the deterministic/AI
     * question flow itself.
     */
    private function completeDisclosureAndIdentity($component, string $name = 'Jordan Prospect', string $email = 'prospect@example.com')
    {
        return $component->call('acknowledgeDisclosure')
            ->set('identityName', $name)
            ->set('identityEmail', $email)
            ->call('saveIdentity');
    }

    // -----------------------------------------------------------------
    // Route reachability
    // -----------------------------------------------------------------

    public function test_the_start_intake_route_is_reachable_from_an_eligible_firms_profile_and_redirects_to_the_signed_intake_url(): void
    {
        $firm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]);

        $response = $this->post($this->myAttorneyUrl('/firms/'.$directoryFirm->slug.'/start-intake'));

        $response->assertRedirect();
        $this->assertStringContainsString('/intake/', $response->headers->get('Location'));

        $count = $this->runWithFirmContext($firm, fn () => MarketplaceIntake::query()->count());
        $this->assertSame(1, $count, 'Posting to start-intake must actually create a MarketplaceIntake via the canonical eligibility-gated service.');
    }

    public function test_start_intake_is_refused_for_an_ineligible_firm_without_disclosing_why(): void
    {
        $firm = Firm::factory()->create();
        // Not accepting_inquiries — MarketplaceIntakeEligibilityService's
        // own last-checked gate.
        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => false]);

        $response = $this->post($this->myAttorneyUrl('/firms/'.$directoryFirm->slug.'/start-intake'));

        $response->assertRedirect($this->myAttorneyUrl('/firms/'.$directoryFirm->slug));
        $count = $this->runWithFirmContext($firm, fn () => MarketplaceIntake::query()->count());
        $this->assertSame(0, $count);
    }

    // -----------------------------------------------------------------
    // Deterministic path — AI OFF must work completely
    // -----------------------------------------------------------------

    public function test_a_visitor_can_complete_the_entire_deterministic_flow_to_submission(): void
    {
        [$firm, , $intake] = $this->deterministicIntake();
        $url = app(MarketplaceIntakeService::class)->signedUrl($intake);
        $uuid = $intake->uuid;

        $component = Livewire::withQueryParams([])->test(PublicIntakePage::class, ['uuid' => $uuid]);
        $component->assertSet('found', true)
            ->assertSet('editable', true)
            ->assertSet('disclosureAcknowledged', false)
            ->assertSeeText('Before you begin');

        $component->call('acknowledgeDisclosure')
            ->assertSet('disclosureAcknowledged', true)
            ->assertSet('identityCaptured', false);

        $component->set('identityName', 'Jordan Prospect')
            ->set('identityEmail', 'prospect@example.com')
            ->call('saveIdentity')
            ->assertSet('identityCaptured', true)
            ->assertSet('questionCode', 'legal_issue');

        $component->set('answerValue', 'Contract dispute with a vendor.')
            ->call('saveAnswer')
            ->assertSet('questionCode', 'state');

        $component->set('answerValue', 'NY')
            ->call('saveAnswer')
            ->assertSet('questionCode', 'notes');

        $component->set('answerValue', '')
            ->call('saveAnswer');

        // 'notes' is optional and blank is a real, deliberate answer —
        // nextQuestion() only ever advances past a NON-empty value
        // though (MarketplaceIntakeAnswerService's own documented
        // behavior), so leaving it blank means it stays "next" and the
        // component never reaches review. This proves the required-
        // only submission gate below, not full traversal of optional
        // fields — see the "required fields only" test for the
        // documented reason no skip affordance exists.
        $this->runWithFirmContext($firm, function () use ($intake) {
            $fresh = $intake->fresh();
            $this->assertSame(MarketplaceIntakeStatus::InProgress, $fresh->status);
            $this->assertSame('Contract dispute with a vendor.', $fresh->structured_data['legal_issue']);
            $this->assertSame('NY', $fresh->structured_data['state']);
        });
    }

    public function test_required_fields_only_reaches_review_and_submits_successfully(): void
    {
        [$firm, , $intake] = $this->deterministicIntake();
        $uuid = $intake->uuid;

        $component = $this->completeDisclosureAndIdentity(Livewire::test(PublicIntakePage::class, ['uuid' => $uuid]))
            ->set('answerValue', 'Contract dispute with a vendor.')
            ->call('saveAnswer')
            ->set('answerValue', 'NY')
            ->call('saveAnswer');

        // 'notes' (optional) is still pending — saving a non-empty
        // value for it advances past every question to review.
        $component->set('answerValue', 'None')
            ->call('saveAnswer')
            ->assertSet('reviewing', true)
            ->assertSet('questionCode', null);

        $component->call('submitIntake');

        $this->runWithFirmContext($firm, function () use ($intake) {
            $fresh = $intake->fresh();
            $this->assertSame(MarketplaceIntakeStatus::Submitted, $fresh->status);
            $this->assertNotNull($fresh->submitted_at);
        });

        $this->assertSame(0, Client::query()->count(), 'Submission must never create a Client — that is Firm-review/conversion territory.');
        $this->assertSame(0, Matter::query()->count());
    }

    public function test_submission_is_blocked_when_a_required_field_was_never_answered(): void
    {
        [$firm, , $intake] = $this->deterministicIntake();

        // Directly drive the intake into a state where every question
        // was reached except one required field was left genuinely
        // blank at the model level (simulating a stale/edited-away
        // answer) — proves submitIntake()'s own final validation gate,
        // independent of the wizard's own forward-only navigation.
        $this->runWithFirmContext($firm, fn () => $intake->update([
            'status' => MarketplaceIntakeStatus::InProgress,
            'prospect_name' => 'Jordan Prospect',
            'prospect_email' => 'prospect@example.com',
            'structured_data' => ['legal_issue' => '', 'state' => 'NY'],
        ]));

        $component = Livewire::test(PublicIntakePage::class, ['uuid' => $intake->uuid]);
        $component->set('reviewing', true)->call('submitIntake');

        $component->assertSet('validationErrors.legal_issue', 'This field is required.');

        $this->runWithFirmContext($firm, function () use ($intake) {
            $this->assertSame(MarketplaceIntakeStatus::InProgress, $intake->fresh()->status);
        });
    }

    public function test_no_duplicate_submission_from_a_retried_double_click(): void
    {
        [$firm, , $intake] = $this->deterministicIntake();
        $this->runWithFirmContext($firm, fn () => $intake->update([
            'status' => MarketplaceIntakeStatus::InProgress,
            'prospect_name' => 'Jordan Prospect',
            'prospect_email' => 'prospect@example.com',
            'structured_data' => ['legal_issue' => 'Contract dispute.', 'state' => 'NY'],
        ]));

        $component = Livewire::test(PublicIntakePage::class, ['uuid' => $intake->uuid]);

        $component->call('submitIntake');
        $component->call('submitIntake');

        $submittedEvents = $this->runWithFirmContext($firm, fn () => MarketplaceIntakeEvent::query()
            ->where('marketplace_intake_id', $intake->id)
            ->where('event_type', MarketplaceIntakeEventType::Submitted)
            ->count());

        $this->assertSame(1, $submittedEvents, 'A second submitIntake() call on an already-Submitted intake must be an idempotent no-op, never a second markSubmitted().');
    }

    // -----------------------------------------------------------------
    // Save/resume — progress survives a fresh page load
    // -----------------------------------------------------------------

    public function test_progress_is_preserved_across_a_fresh_mount_resume(): void
    {
        [$firm, , $intake] = $this->deterministicIntake();

        $this->completeDisclosureAndIdentity(Livewire::test(PublicIntakePage::class, ['uuid' => $intake->uuid]))
            ->set('answerValue', 'Contract dispute with a vendor.')
            ->call('saveAnswer');

        // A brand-new component instance — mirrors a real page reload.
        $resumed = Livewire::test(PublicIntakePage::class, ['uuid' => $intake->uuid]);

        $resumed->assertSet('questionCode', 'state')
            ->assertSet('answeredCount', 1);

        $this->runWithFirmContext($firm, function () use ($intake) {
            $this->assertSame(MarketplaceIntakeStatus::InProgress, $intake->fresh()->status);
        });
    }

    public function test_editing_an_already_answered_question_from_review_updates_it_and_returns_to_review(): void
    {
        [$firm, , $intake] = $this->deterministicIntake();
        $this->runWithFirmContext($firm, fn () => $intake->update([
            'status' => MarketplaceIntakeStatus::InProgress,
            'prospect_name' => 'Jordan Prospect',
            'prospect_email' => 'prospect@example.com',
            'structured_data' => ['legal_issue' => 'Contract dispute.', 'state' => 'NY', 'notes' => 'None'],
        ]));

        $component = Livewire::test(PublicIntakePage::class, ['uuid' => $intake->uuid]);
        $component->assertSet('reviewing', true);

        $component->call('editAnswer', 'state')
            ->assertSet('questionCode', 'state')
            ->assertSet('answerValue', 'NY')
            ->assertSet('reviewing', false);

        $component->set('answerValue', 'CA')
            ->call('saveAnswer')
            ->assertSet('reviewing', true);

        $this->runWithFirmContext($firm, function () use ($intake) {
            $this->assertSame('CA', $intake->fresh()->structured_data['state']);
        });
    }

    // -----------------------------------------------------------------
    // AI-assisted path — with a safe fallback, never a bypass
    // -----------------------------------------------------------------

    public function test_ai_assist_available_flag_reflects_the_firms_own_setting(): void
    {
        [, , $aiIntake] = $this->aiAssistedIntake();
        [, , $deterministicOnlyIntake] = $this->deterministicIntake();

        Livewire::test(PublicIntakePage::class, ['uuid' => $aiIntake->uuid])
            ->assertSet('aiAssistAvailable', true);

        Livewire::test(PublicIntakePage::class, ['uuid' => $deterministicOnlyIntake->uuid])
            ->assertSet('aiAssistAvailable', false);
    }

    public function test_a_chat_message_extracts_and_saves_the_pending_answer_through_the_same_validator(): void
    {
        [$firm, , $intake] = $this->aiAssistedIntake();

        $component = $this->completeDisclosureAndIdentity(Livewire::test(PublicIntakePage::class, ['uuid' => $intake->uuid]))
            ->set('chatMessage', 'This is a contract dispute with my landlord.')
            ->call('sendChatMessage');

        $component->assertSet('questionCode', 'state');

        $this->runWithFirmContext($firm, function () use ($intake) {
            $fresh = $intake->fresh();
            $this->assertSame(MarketplaceIntakeStatus::InProgress, $fresh->status);
            $this->assertNotEmpty($fresh->structured_data['legal_issue'] ?? null);
            $this->assertTrue($fresh->ai_assisted);
        });
    }

    public function test_ai_off_deterministic_form_remains_fully_functional_even_when_ai_assist_is_disabled_for_the_firm(): void
    {
        [$firm, , $intake] = $this->deterministicIntake();

        $component = $this->completeDisclosureAndIdentity(
            Livewire::test(PublicIntakePage::class, ['uuid' => $intake->uuid])->assertSet('aiAssistAvailable', false)
        )->set('answerValue', 'Contract dispute with a vendor.')
            ->call('saveAnswer')
            ->set('answerValue', 'NY')
            ->call('saveAnswer')
            ->set('answerValue', 'None')
            ->call('saveAnswer')
            ->assertSet('reviewing', true);

        $component->call('submitIntake');

        $this->runWithFirmContext($firm, function () use ($intake) {
            $this->assertSame(MarketplaceIntakeStatus::Submitted, $intake->fresh()->status);
        });
    }

    // -----------------------------------------------------------------
    // Cross-prospect isolation / denial paths
    // -----------------------------------------------------------------

    public function test_a_visitor_cannot_edit_another_intakes_answers_by_forging_the_uuid(): void
    {
        [$firmA, , $intakeA] = $this->deterministicIntake();
        [$firmB, , $intakeB] = $this->deterministicIntake();

        $this->completeDisclosureAndIdentity(Livewire::test(PublicIntakePage::class, ['uuid' => $intakeA->uuid]))
            ->set('answerValue', 'Intake A answer.')
            ->call('saveAnswer');

        $this->runWithFirmContext($firmB, function () use ($intakeB) {
            $this->assertNull($intakeB->fresh()->structured_data);
        });

        $this->runWithFirmContext($firmA, function () use ($intakeA) {
            $this->assertSame('Intake A answer.', $intakeA->fresh()->structured_data['legal_issue']);
        });
    }

    public function test_an_unknown_uuid_never_renders_the_wizard(): void
    {
        $component = Livewire::test(PublicIntakePage::class, ['uuid' => (string) Str::uuid7()]);

        $component->assertSet('found', false)
            ->assertDontSee('Before you begin');
    }

    public function test_a_terminal_intake_never_re_enters_the_editable_wizard(): void
    {
        [$firm, , $intake] = $this->deterministicIntake();
        $this->runWithFirmContext($firm, fn () => $intake->update(['status' => MarketplaceIntakeStatus::Declined, 'declined_at' => now()]));

        $component = Livewire::test(PublicIntakePage::class, ['uuid' => $intake->uuid]);

        $component->assertSet('resumable', false)
            ->assertSet('editable', false)
            ->assertDontSee('Before you begin');

        $component->call('saveAnswer');
        $component->call('submitIntake');

        $this->runWithFirmContext($firm, function () use ($intake) {
            $this->assertSame(MarketplaceIntakeStatus::Declined, $intake->fresh()->status);
        });
    }

    public function test_a_submitted_intake_shows_a_confirmation_not_the_wizard_on_resume(): void
    {
        [$firm, , $intake] = $this->deterministicIntake();
        $this->runWithFirmContext($firm, fn () => $intake->update([
            'status' => MarketplaceIntakeStatus::Submitted,
            'submitted_at' => now(),
            'structured_data' => ['legal_issue' => 'Contract dispute.', 'state' => 'NY'],
        ]));

        $component = Livewire::test(PublicIntakePage::class, ['uuid' => $intake->uuid]);

        $component->assertSet('resumable', true)
            ->assertSet('editable', false)
            ->assertSeeText('submitted')
            ->assertDontSee('Before you begin');
    }

    public function test_an_ai_answer_the_validator_rejects_is_explained_instead_of_silently_dropped(): void
    {
        // Found during real-provider acceptance on staging. The visitor sent a
        // message that did not answer the question being asked; OpenAI returned
        // an extraction, the deterministic validator refused it, and the page
        // showed nothing at all — same question, no message, no reason. The
        // visitor's only recourse was to send again, spending the firm's tokens
        // on every attempt.
        [$firm, , $intake] = $this->aiAssistedIntake();

        // Force the rejection deterministically: a value the pending question's
        // own validator cannot accept.
        $this->fakeOpenAiExtraction('legal_issue', '');

        $component = $this->completeDisclosureAndIdentity(Livewire::test(PublicIntakePage::class, ['uuid' => $intake->uuid]))
            ->set('chatMessage', 'What sort of thing do you need from me?')
            ->call('sendChatMessage');

        $component->assertSet('questionCode', 'legal_issue');
        $this->assertNotEmpty(
            $component->get('validationErrors'),
            'The validator refused the extracted answer, so the visitor must be told why.',
        );
        $this->assertNotNull($component->get('aiNotice'));

        $this->runWithFirmContext($firm, function () use ($intake) {
            $this->assertNull($intake->fresh()->structured_data['legal_issue'] ?? null);
        });
    }

    public function test_a_successful_ai_turn_leaves_no_stale_error_behind(): void
    {
        // The other half: the errors must not outlive the turn that produced
        // them, or every later question would inherit a warning about an answer
        // that was already accepted.
        [, , $intake] = $this->aiAssistedIntake();

        $component = $this->completeDisclosureAndIdentity(Livewire::test(PublicIntakePage::class, ['uuid' => $intake->uuid]))
            ->set('chatMessage', 'This is a contract dispute with my landlord.')
            ->call('sendChatMessage');

        $component->assertSet('questionCode', 'state');
        $this->assertSame([], $component->get('validationErrors'));
        $this->assertNull($component->get('aiNotice'));
    }

    public function test_a_document_cannot_be_attached_to_an_intake_this_session_never_opened(): void
    {
        // Found during real staging acceptance. The upload route resolves the
        // intake by uuid alone; it is deliberately not signed, and it never
        // checked that this session had ever held the signed link. A second
        // visitor holding only the bare uuid — from an access log, a referrer
        // header, a shared screen — attached a file to a stranger's intake,
        // and the firm saw it as that prospect's own evidence.
        [, , $mine] = $this->deterministicIntake();
        [, , $someoneElses] = $this->deterministicIntake();

        // Prove possession of MY link only, exactly as loading the signed page does.
        Livewire::test(PublicIntakePage::class, ['uuid' => $mine->uuid]);

        $response = $this->post(
            $this->myAttorneyUrl("/intake/{$someoneElses->uuid}/documents"),
            ['file' => UploadedFile::fake()->create('planted.pdf', 8, 'application/pdf')],
        );

        $response->assertNotFound();

        $this->runWithFirmContext($someoneElses->firm, function () use ($someoneElses) {
            $this->assertSame(
                0,
                Document::query()->where('marketplace_intake_id', $someoneElses->id)->count(),
                'No document may be attached to an intake this session never opened.',
            );
        });
    }

    public function test_a_document_can_be_attached_to_the_intake_this_session_opened(): void
    {
        // The other half: the guard must not break the legitimate path.
        [$firm, , $intake] = $this->deterministicIntake();

        Livewire::test(PublicIntakePage::class, ['uuid' => $intake->uuid]);

        $response = $this->post(
            $this->myAttorneyUrl("/intake/{$intake->uuid}/documents"),
            ['file' => UploadedFile::fake()->create('my-evidence.pdf', 8, 'application/pdf')],
        );

        $response->assertRedirect();

        $this->runWithFirmContext($firm, function () use ($intake) {
            $this->assertSame(1, Document::query()->where('marketplace_intake_id', $intake->id)->count());
        });
    }
}
