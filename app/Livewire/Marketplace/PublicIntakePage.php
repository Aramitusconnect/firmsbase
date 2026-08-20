<?php

declare(strict_types=1);

namespace App\Livewire\Marketplace;

use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Services\MarketplaceIntakeAnswerService;
use App\Marketplace\Services\MarketplaceIntakeConversationalAssistantService;
use App\Marketplace\Services\MarketplaceIntakeDocumentService;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Marketplace\Support\IntakeLinkPossession;
use App\Models\IntakeTemplate;
use App\Models\IntakeTemplateQuestion;
use App\Services\IntakeTemplateService;
use App\Services\TenantContextService;
use Livewire\Component;

/**
 * PublicIntakePage — Mission 3 (MyAttorney Conversion + AI Intake)
 * checkpoint 2 built the resume/status shell; Mission 3A (MyAttorney
 * Launch-Flow Closure) completes it into the actual reachable
 * answer-collection wizard: disclosure -> intake questions
 * (AI-assisted OR deterministic) -> progress -> edit answers ->
 * save/resume -> review -> submit -> confirmation.
 *
 * Reuses every backend service unmodified — this class is pure
 * orchestration, exactly like ConvertMarketplaceProspectService's own
 * "never a parallel shortcut" rule:
 *   - IntakeTemplateService / MarketplaceIntakeAnswerService for the
 *     deterministic question structure, per-question saves, and
 *     server-authoritative "what's next" ordering.
 *   - MarketplaceIntakeConversationalAssistantService for the
 *     optional AI-assisted turn — ALWAYS falls back safely, so the
 *     deterministic field below it remains fully functional with AI
 *     OFF (mission requirement).
 *   - MarketplaceIntakeService for status transitions
 *     (markInProgress/markSubmitted) and the resume-shell logic this
 *     checkpoint 2 already built, unchanged.
 *   - MarketplaceIntakeDocumentService::visitorSummary() for the
 *     already-wired document-quarantine flow (checkpoint 7) — this
 *     class never touches file upload directly; the Blade view posts
 *     straight to the existing, already-tested
 *     MarketplaceIntakeDocumentController route.
 *
 * Every mutating action re-resolves the intake fresh from its own
 * uuid via resolveByUuid() (the same RLS self-lookup mount() uses),
 * never trusts a previously-hydrated Livewire snapshot for anything
 * security-relevant — a visitor cannot smuggle another prospect's
 * intake into this component's state (Livewire's own signed snapshot
 * checksum already prevents tampering with $uuid itself; re-resolving
 * on every action is defense in depth against a since-changed status,
 * e.g. expiry or Firm review pulling the intake out from under an
 * open tab).
 *
 * No "skip this question" affordance exists — MarketplaceIntakeAnswerService::
 * nextQuestion()'s own docblock states it is "used identically by the
 * deterministic questionnaire and the AI-assisted conversational
 * flow, so both stay in lockstep"; inventing UI-only skip semantics
 * here would desynchronize the two paths (an AI turn would still
 * target whatever nextQuestion() itself considers next). Optional
 * (non-required) questions simply do not block a later resume/final
 * submission if never reached — IntakeTemplateService::
 * validateResponses() only requires REQUIRED fields.
 */
class PublicIntakePage extends Component
{
    public string $uuid;

    public bool $found = false;

    public bool $resumable = false;

    public string $firmDisplayName = '';

    public string $status = '';

    // Wizard state — populated only when resumable && editable.
    public bool $editable = false;

    public bool $disclosureAcknowledged = false;

    public bool $identityCaptured = false;

    public string $identityName = '';

    public string $identityEmail = '';

    public string $identityPhone = '';

    public bool $aiAssistAvailable = false;

    public ?string $questionCode = null;

    public string $questionLabel = '';

    public ?string $questionHelp = null;

    public string $questionType = '';

    public bool $questionRequired = false;

    public array $questionOptions = [];

    public mixed $answerValue = null;

    public int $answeredCount = 0;

    public int $totalCount = 0;

    public string $chatMessage = '';

    public ?string $aiNotice = null;

    /** @var array<string, string> */
    public array $validationErrors = [];

    public bool $reviewing = false;

    /** @var array<int, array{code: string, label: string, value: mixed}> */
    public array $reviewItems = [];

    public bool $communicationsConsent = false;

    public bool $portalConsent = false;

    /** @var array<int, array{id: int, original_filename: string, accepted: bool, pending: bool}> */
    public array $documentSummary = [];

    public bool $editingFromReview = false;

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;

        $service = app(MarketplaceIntakeService::class);
        $intake = $service->resolveByUuid($uuid);

        if ($intake === null) {
            $this->found = false;

            return;
        }

        $this->found = true;

        // Reaching here means this session presented a VALID SIGNED link for
        // this intake — the 'signed' middleware on the route has already run.
        // Same-session follow-up actions that are not themselves signed (the
        // document upload) check this rather than trusting a bare uuid.
        IntakeLinkPossession::remember($uuid);

        (new TenantContextService)->runWithFirmContext($intake->firm, function () use ($intake, $service) {
            if (! $intake->isResumable()) {
                if ($intake->status->isTerminal()) {
                    $this->hydrateDisplayFrom($intake->fresh());

                    return;
                }

                $intake = $service->markExpired($intake->firm, $intake);
                $this->hydrateDisplayFrom($intake);

                return;
            }

            $service->recordLinkResumed($intake->firm, $intake, request()->ip());
            $fresh = $intake->fresh();
            $this->hydrateDisplayFrom($fresh);
            $this->refreshWizardState($fresh);
        });
    }

    /**
     * Called from the disclosure step — a pure UI acknowledgement,
     * not a data mutation. No new column exists for this (nor does
     * one need to): a returning visitor who has already saved at
     * least one answer has, by definition, already passed disclosure
     * in an earlier session, so refreshWizardState() skips it
     * automatically for them.
     */
    public function acknowledgeDisclosure(): void
    {
        $this->disclosureAcknowledged = true;
    }

    /**
     * Mission 3A — closes a gap this checkpoint's own build surfaced:
     * no caller anywhere in Mission 3 ever wrote prospect_name/
     * prospect_email, which ConvertMarketplaceProspectService's own
     * FirmLead::create() call hard-requires (firm_leads.name is NOT
     * NULL). Deliberately separate from the template question
     * mechanism — these are identity fields, never structured_data,
     * matching ConvertMarketplaceProspectService's own documented
     * distinction. Shown once, right after disclosure; a returning
     * visitor who already supplied identity skips it automatically
     * (identityCaptured is derived from the row itself, not UI-only
     * state, unlike disclosureAcknowledged).
     */
    public function saveIdentity(): void
    {
        $intake = $this->requireEditableIntake();

        if ($intake === null) {
            return;
        }

        $name = trim($this->identityName);
        $email = trim($this->identityEmail);

        if ($name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->validationErrors = ['identity' => 'Please enter your name and a valid email address.'];

            return;
        }

        app(MarketplaceIntakeService::class)->saveProspectIdentity($intake->firm, $intake, $name, $email, $this->identityPhone);

        $this->validationErrors = [];

        $fresh = $this->resolveFreshIntake();

        if ($fresh !== null) {
            $this->refreshWizardState($fresh);
        }
    }

    public function saveAnswer(): void
    {
        $intake = $this->requireEditableIntake();

        if ($intake === null || $this->questionCode === null) {
            return;
        }

        $firm = $intake->firm;
        $value = is_string($this->answerValue) ? trim($this->answerValue) : $this->answerValue;

        $errors = app(MarketplaceIntakeAnswerService::class)->saveAnswers($firm, $intake, [
            $this->questionCode => $value,
        ]);

        if ($errors !== []) {
            $this->validationErrors = $errors;

            return;
        }

        app(MarketplaceIntakeService::class)->markInProgress($firm, $intake);

        $this->editingFromReview = false;
        $this->validationErrors = [];

        // Never $intake->fresh() here — every service call above opens
        // and closes its OWN runWithFirmContext internally, so by this
        // point no firm context is active and a bare ->fresh() would
        // fail-closed under FORCE RLS (silently return null). Re-resolve
        // via the same uuid self-lookup mount() itself uses instead.
        $fresh = $this->resolveFreshIntake();

        if ($fresh !== null) {
            $this->refreshWizardState($fresh);
        }
    }

    public function sendChatMessage(): void
    {
        $intake = $this->requireEditableIntake();

        if ($intake === null) {
            return;
        }

        $message = trim($this->chatMessage);

        if ($message === '') {
            return;
        }

        $this->chatMessage = '';
        $firm = $intake->firm;

        $result = app(MarketplaceIntakeConversationalAssistantService::class)->respond(
            $firm,
            $intake,
            $message,
            $this->sessionHash(),
            request()->ip(),
        );

        $this->aiNotice = match (true) {
            ! $result->usedAi && $result->fallbackReason !== null => 'AI assistance is unavailable right now — please continue with the form below.',
            // The turn reached the provider and came back with something the
            // deterministic validator would not accept — an answer that does
            // not fit this question's own type or options. Silence here is the
            // worst outcome: the question does not move, nothing is explained,
            // and the visitor sends the same message again, spending the firm's
            // tokens each time.
            $result->validationErrors !== [] => 'That did not answer the question above — please answer it directly, or use the form below.',
            default => null,
        };

        $this->editingFromReview = false;

        // Same fail-closed-outside-context pitfall as saveAnswer() —
        // re-resolve fresh via uuid self-lookup, never a bare ->fresh()
        // here (respond()'s own internal writes have already closed
        // their contexts by this point).
        $fresh = $this->resolveFreshIntake();

        if ($fresh === null) {
            return;
        }

        app(MarketplaceIntakeService::class)->markInProgress($fresh->firm, $fresh);

        $fresh = $this->resolveFreshIntake();

        if ($fresh !== null) {
            $this->refreshWizardState($fresh);
        }

        // AFTER the refresh, deliberately. refreshWizardState() clears
        // validationErrors as part of moving to a question, which silently
        // discarded this turn's errors when they were assigned before the call.
        $this->validationErrors = $result->validationErrors;
    }

    /**
     * Jumps back to a previously-answered question from the review
     * screen. $code is validated against this intake's OWN template
     * question set below — never trusted as an arbitrary string.
     */
    public function editAnswer(string $code): void
    {
        $intake = $this->requireEditableIntake();

        if ($intake === null) {
            return;
        }

        $template = $intake->intakeTemplate;

        if ($template === null) {
            return;
        }

        $templateService = app(IntakeTemplateService::class);
        $question = $templateService->questionsFor($template)->firstWhere('question_code', $code);

        if ($question === null) {
            return;
        }

        $this->hydrateQuestion($question);
        $this->answerValue = ($intake->structured_data ?? [])[$code] ?? null;
        $this->editingFromReview = true;
        $this->reviewing = false;
        $this->validationErrors = [];
    }

    public function backToReview(): void
    {
        $this->editingFromReview = false;
        $this->reviewing = true;
        $this->validationErrors = [];
    }

    public function submitIntake(): void
    {
        // resolveByUuid() (via resolveFreshIntake()) always queries
        // fresh — no ->fresh() call needed on the result itself.
        $intake = $this->resolveFreshIntake();

        if ($intake === null) {
            return;
        }

        $this->hydrateDisplayFrom($intake);

        if (! $intake->status->isEditableByProspect()) {
            // Already submitted — e.g. a duplicate click's second
            // request arriving after the first already succeeded.
            // Idempotent: just show whatever the intake's own current
            // (post-submission) state already is, never a second
            // markSubmitted() call and never an error toward the
            // visitor.
            $this->reviewing = false;
            $this->questionCode = null;

            return;
        }

        if ($intake->prospect_name === null || $intake->prospect_email === null) {
            // Fail closed server-side too, not just via the wizard's
            // own forward-only step ordering — a forged/replayed
            // submitIntake() call must never reach markSubmitted()
            // without the identity fields ConvertMarketplaceProspectService's
            // own FirmLead::create() call hard-requires later.
            $this->validationErrors = ['_general' => 'Please provide your contact information before submitting.'];

            return;
        }

        $firm = $intake->firm;
        $template = $intake->intakeTemplate;
        $answers = $intake->structured_data ?? [];

        if ($template === null) {
            $this->validationErrors = ['_general' => 'This intake has no template attached — please contact the firm directly.'];

            return;
        }

        $errors = app(IntakeTemplateService::class)->validateResponses($template, $answers);

        if ($errors !== []) {
            $this->validationErrors = $errors;
            $this->reviewing = true;

            return;
        }

        app(MarketplaceIntakeService::class)->markSubmitted($firm, $intake, $this->communicationsConsent, $this->portalConsent);

        $fresh = $this->resolveFreshIntake();

        if ($fresh !== null) {
            $this->hydrateDisplayFrom($fresh);
        }

        $this->reviewing = false;
        $this->questionCode = null;
    }

    private function resolveFreshIntake(): ?MarketplaceIntake
    {
        $intake = app(MarketplaceIntakeService::class)->resolveByUuid($this->uuid);

        if ($intake === null) {
            $this->found = false;

            return null;
        }

        return $intake;
    }

    /**
     * Re-derives resumable/editable/status fresh on every action —
     * never trusts a prior request's own hydrated Livewire state for
     * anything that gates a mutation.
     */
    private function requireEditableIntake(): ?MarketplaceIntake
    {
        $intake = $this->resolveFreshIntake();

        if ($intake === null) {
            return null;
        }

        $this->resumable = $intake->isResumable();
        $this->status = $intake->status->value;
        $this->editable = $intake->status->isEditableByProspect();

        if (! $this->resumable || ! $this->editable) {
            return null;
        }

        return $intake;
    }

    /**
     * Safe to call regardless of whether a firm context is currently
     * active — firm_settings (branding_settings_json) is FORCE RLS,
     * so this establishes its own short read-only context rather than
     * assuming a caller already has one open (unlike mount()'s
     * original single call site, this method now has several callers
     * at different points in each action's own context lifecycle).
     */
    private function hydrateDisplayFrom(MarketplaceIntake $intake): void
    {
        $this->resumable = $intake->isResumable();
        $this->status = $intake->status->value;
        $this->firmDisplayName = (new TenantContextService)->runWithFirmContextWithoutTransaction(
            $intake->firm,
            fn () => $intake->firm->firmSettings?->branding_settings_json['display_name_override']
                ?? $intake->firm->legal_name
                ?? $intake->firm->name,
        );
    }

    private function refreshWizardState(MarketplaceIntake $intake): void
    {
        $this->editable = $intake->status->isEditableByProspect();
        $this->identityCaptured = $intake->prospect_name !== null && $intake->prospect_email !== null;

        if (! $this->editable) {
            $this->reviewing = false;
            $this->questionCode = null;

            return;
        }

        $firm = $intake->firm;

        // Independent of identity capture — the chat-assist
        // availability flag and the document-upload summary are both
        // meaningful to show on the identity step's own surrounding
        // page chrome (and cheap to compute), so they're never gated
        // behind identityCaptured the way question/review state is
        // below.
        $this->documentSummary = app(MarketplaceIntakeDocumentService::class)->visitorSummary($firm, $intake);
        $this->aiAssistAvailable = (new TenantContextService)->runWithFirmContextWithoutTransaction(
            $firm,
            fn () => (bool) ($firm->aiSettings?->intake_ai_assist_enabled ?? false),
        );

        if (! $this->identityCaptured) {
            return;
        }

        $answerService = app(MarketplaceIntakeAnswerService::class);
        $templateService = app(IntakeTemplateService::class);
        $template = $intake->intakeTemplate;

        if ($template === null) {
            $this->questionCode = null;
            $this->totalCount = 0;
            $this->answeredCount = 0;

            return;
        }

        $answers = $intake->structured_data ?? [];
        $this->populateProgress($templateService, $template, $answers);

        $next = $answerService->nextQuestion($firm, $intake);

        if ($next === null) {
            $this->questionCode = null;
            $this->reviewing = ! $this->editingFromReview;
            $this->buildReviewItems($templateService, $template, $answers);

            return;
        }

        if (! $this->editingFromReview) {
            $this->hydrateQuestion($next);
        }
    }

    private function populateProgress(IntakeTemplateService $templateService, IntakeTemplate $template, array $answers): void
    {
        $applicable = $templateService->questionsFor($template)
            ->filter(fn (IntakeTemplateQuestion $q) => $templateService->isQuestionApplicable($q, $answers));

        $this->totalCount = $applicable->count();
        $this->answeredCount = $applicable->filter(function (IntakeTemplateQuestion $q) use ($answers) {
            $value = $answers[$q->question_code] ?? null;

            return $value !== null && $value !== '';
        })->count();
    }

    /**
     * Deliberately NOT named hydrateReviewItems() — Livewire's own
     * SupportLifecycleHooks::hydrate() automatically invokes
     * hydrate{StudlyPropertyName}($value) for every public property on
     * every request (a "magic hydration hook" convention), and this
     * class has a public $reviewItems property. A same-named private
     * helper collided with that convention and was invoked as the hook
     * instead, with Livewire's own container trying (and failing) to
     * implicit-bind $reviewItems' array value against this method's
     * IntakeTemplate parameter. Keep every private helper here clear
     * of hydrate{PropertyName}/dehydrate{PropertyName}/updating{...}/
     * updated{...} for any public property on this component.
     */
    private function buildReviewItems(IntakeTemplateService $templateService, IntakeTemplate $template, array $answers): void
    {
        $this->reviewItems = $templateService->questionsFor($template)
            ->filter(fn (IntakeTemplateQuestion $q) => $templateService->isQuestionApplicable($q, $answers))
            ->map(fn (IntakeTemplateQuestion $q) => [
                'code' => $q->question_code,
                'label' => $q->label,
                'value' => $answers[$q->question_code] ?? '',
            ])
            ->values()
            ->all();
    }

    private function hydrateQuestion(IntakeTemplateQuestion $question): void
    {
        $this->questionCode = $question->question_code;
        $this->questionLabel = $question->label;
        $this->questionHelp = $question->help_text;
        $this->questionType = $question->question_type->value;
        $this->questionRequired = $question->is_required;
        $this->questionOptions = $question->options_json ?? [];
        $this->answerValue = null;
        $this->validationErrors = [];
    }

    /**
     * An opaque, per-visitor-session throttle key — mirrors
     * MarketplaceIssueClassifierService's own caller convention for
     * this same parameter. Never the raw session id itself.
     */
    private function sessionHash(): string
    {
        return hash('sha256', session()->getId());
    }

    public function render()
    {
        return view('livewire.marketplace.public-intake-page')
            ->layout('layouts.public-intake');
    }
}
