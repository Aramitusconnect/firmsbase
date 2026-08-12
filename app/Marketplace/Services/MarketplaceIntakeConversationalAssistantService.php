<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Enums\AiProvider;
use App\Enums\AiUsageActionType;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\ValueObjects\MarketplaceIntakeConversationalTurnResult;
use App\Models\Firm;
use App\Services\AiBudgetEnforcementService;
use App\Services\AiModeResolutionService;
use App\Services\AiProviderAdapterInterface;
use App\Services\AiStructuredOutputSchemaRegistry;
use App\Services\PromptInjectionResistanceService;
use App\Services\TenantContextService;
use App\ValueObjects\AiPromptRequest;

/**
 * MarketplaceIntakeConversationalAssistantService — Mission 3
 * (MyAttorney Conversion + AI Intake), checkpoint 6. The AI-ON turn
 * handler for the Firm-scoped detailed intake, once a Firm has
 * already been selected (mission requirement #7: "both AI ON and AI
 * OFF paths mandatory"). Every turn:
 *
 *   1. Determines the pending question BEFORE anything else — that
 *      question, never AI free-text, decides what gets asked/saved.
 *   2. Preserves the visitor's raw message into
 *      conversation_transcript FIRST, so it survives any later
 *      failure (requirement #11: "provider failure/timeout must
 *      preserve answers").
 *   3. Checks the Firm's own AiModeResolutionService gate, its own
 *      AiBudgetEnforcementService::checkFirmBudget() (both Firm-only,
 *      no User needed — reused as-is, unmodified), and the
 *      anonymous-specific MarketplaceAiUsageThrottleService ceiling —
 *      any failure here falls back to the deterministic questionnaire
 *      rather than blocking the visitor outright.
 *   4. Places the visitor's message ONLY in
 *      AiPromptRequest::documentDerivedText, never instructionText —
 *      the target question_code is embedded via the system-authored
 *      EXTRACT_FIELD: marker instead (mirrors FakeAiProviderAdapter's
 *      own REQUEST_TOOL: convention). Nothing the visitor types can
 *      ever choose which question gets written to, or bind this
 *      intake to a different Firm — that binding is $intake->firm_id
 *      itself, asserted server-side, never re-derived from AI output.
 *   5. Wraps the provider call in try/catch(\Throwable) — never
 *      exposes a stack trace, always falls back to deterministic on
 *      any failure.
 *   6. Explicitly rejects a mismatched question_code in the AI's own
 *      response (defense-in-depth: authorization/binding lives
 *      outside the model, not merely inside the fake adapter's
 *      current inability to produce a mismatch).
 *   7. Saves the extracted value through
 *      MarketplaceIntakeAnswerService::saveAnswers() — the SAME
 *      validator the deterministic path uses. The AI conversation
 *      itself never becomes the source of truth.
 *
 * A duplicate retry of the same visitor message against an
 * already-answered question is not a special case here: nextQuestion()
 * simply advances past an already-answered field, so re-submitting the
 * same message twice (e.g. a client-side retry after a dropped
 * response) can save the same field again with the same validated
 * value, or move straight to the next question if it was already
 * saved — it never creates a second MarketplaceIntake or a duplicate
 * question entry.
 */
class MarketplaceIntakeConversationalAssistantService
{
    private const RESPONSE_SCHEMA_KEY = 'intake_field_extraction';

    private const EXTRACT_FIELD_TRIGGER = 'EXTRACT_FIELD:';

    public function __construct(
        private readonly AiProviderAdapterInterface $provider,
        private readonly AiModeResolutionService $modeResolution,
        private readonly AiBudgetEnforcementService $budgetEnforcement,
        private readonly MarketplaceAiUsageThrottleService $throttle,
        private readonly MarketplaceAiUsageRecorderService $usageRecorder,
        private readonly MarketplaceIntakeAnswerService $answers,
        private readonly PromptInjectionResistanceService $promptInjectionResistance,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function respond(
        Firm $firm,
        MarketplaceIntake $intake,
        string $visitorMessage,
        string $sessionHash,
        ?string $ipAddress,
    ): MarketplaceIntakeConversationalTurnResult {
        $this->assertBelongsToFirm($firm, $intake);

        $pendingQuestion = $this->answers->nextQuestion($firm, $intake);

        if ($pendingQuestion === null) {
            return MarketplaceIntakeConversationalTurnResult::complete(usedAi: false);
        }

        $trimmedMessage = trim($visitorMessage);

        $this->answers->appendTranscriptEntry($firm, $intake, 'visitor', $trimmedMessage);

        if ($trimmedMessage === '') {
            return MarketplaceIntakeConversationalTurnResult::fallbackToDeterministic($pendingQuestion, 'empty_message');
        }

        // firm_ai_settings.intake_ai_assist_enabled (checkpoint 4's
        // reserved column) is the Firm's own explicit opt-in for
        // AI-assisted intake specifically — distinct from, and checked
        // before, the general AiModeResolutionService gate below. A
        // firm with AI enabled platform-wide has NOT thereby opted
        // into AI touching its public intake surface; this column
        // defaults to false, so the deterministic questionnaire is
        // what every firm gets until it explicitly turns this on.
        //
        // Both firm_ai_settings and firm_settings (read inside
        // AiModeResolutionService::evaluate()) have FORCE ROW LEVEL
        // SECURITY. This is a public, anonymous-visitor flow with no
        // middleware guaranteed to have already established firm
        // context, so both reads are wrapped in ONE short,
        // non-transactional context call here — mirrors
        // runWithFirmContextWithoutTransaction()'s own documented
        // purpose (a pure-read check, not a write needing transactional
        // consistency), and deliberately does NOT hold a transaction
        // across the provider call further below.
        [$intakeAiAssistEnabled, $accessDecision] = $this->tenantContext->runWithFirmContextWithoutTransaction(
            $firm,
            fn () => [
                $firm->aiSettings?->intake_ai_assist_enabled ?? false,
                $this->modeResolution->evaluate($firm),
            ],
        );

        if (! $intakeAiAssistEnabled) {
            return MarketplaceIntakeConversationalTurnResult::fallbackToDeterministic($pendingQuestion, 'intake_ai_assist_disabled');
        }

        $throttleDecision = $this->throttle->evaluate($firm, $sessionHash, $ipAddress);

        if (! $accessDecision->allowed) {
            return MarketplaceIntakeConversationalTurnResult::fallbackToDeterministic($pendingQuestion, 'ai_unavailable');
        }

        if (! $throttleDecision->allowed) {
            return MarketplaceIntakeConversationalTurnResult::fallbackToDeterministic($pendingQuestion, 'throttled');
        }

        $request = new AiPromptRequest(
            provider: AiProvider::OpenAi,
            model: 'fake-model-1',
            actionType: AiUsageActionType::IntakeFieldExtraction,
            instructionText: self::EXTRACT_FIELD_TRIGGER.$pendingQuestion->question_code,
            documentDerivedText: $trimmedMessage,
            matterIds: [],
            allowToolActions: false,
            responseSchemaKey: self::RESPONSE_SCHEMA_KEY,
        );

        // Audit visibility only — FakeAiProviderAdapter's structural
        // design already makes an injection attempt in
        // documentDerivedText powerless to alter instructionText's
        // own EXTRACT_FIELD_TRIGGER target.
        $this->promptInjectionResistance->evaluate($request);

        try {
            $response = $this->provider->generate($request);
        } catch (\Throwable) {
            return MarketplaceIntakeConversationalTurnResult::fallbackToDeterministic($pendingQuestion, 'provider_error');
        }

        $totalTokens = $response->tokensIn + $response->tokensOut;
        [$periodStartsAt, $periodEndsAt] = $this->currentPeriod();

        // checkFirmBudget() reads firm_ai_settings and ai_usage_events,
        // both FORCE ROW LEVEL SECURITY — same reasoning as the
        // intake_ai_assist_enabled/modeResolution read above.
        $budgetResult = $this->tenantContext->runWithFirmContextWithoutTransaction(
            $firm,
            fn () => $this->budgetEnforcement->checkFirmBudget($firm, $totalTokens, 0, $periodStartsAt, $periodEndsAt),
        );

        if (! $budgetResult->allowed()) {
            return MarketplaceIntakeConversationalTurnResult::fallbackToDeterministic($pendingQuestion, 'firm_budget_exceeded');
        }

        $this->throttle->recordAttempt($sessionHash, $ipAddress);
        $this->usageRecorder->record($firm, $intake, $sessionHash, $ipAddress, $request, $response);

        if ($response->structuredOutput === null) {
            return MarketplaceIntakeConversationalTurnResult::fallbackToDeterministic($pendingQuestion, 'no_structured_output');
        }

        $errors = AiStructuredOutputSchemaRegistry::validate(self::RESPONSE_SCHEMA_KEY, $response->structuredOutput);

        if ($errors !== []) {
            return MarketplaceIntakeConversationalTurnResult::fallbackToDeterministic($pendingQuestion, 'invalid_structured_output');
        }

        if ($response->structuredOutput['question_code'] !== $pendingQuestion->question_code) {
            return MarketplaceIntakeConversationalTurnResult::fallbackToDeterministic($pendingQuestion, 'question_mismatch');
        }

        $saveErrors = $this->answers->saveAnswers($firm, $intake, [
            $pendingQuestion->question_code => $response->structuredOutput['extracted_value'],
        ]);

        if ($saveErrors !== []) {
            return MarketplaceIntakeConversationalTurnResult::validationFailed($pendingQuestion, $saveErrors);
        }

        $this->answers->appendTranscriptEntry($firm, $intake, 'assistant', $response->outputText);
        $this->markAiAssisted($firm, $intake);

        $next = $this->answers->nextQuestion($firm, $intake);

        return $next === null
            ? MarketplaceIntakeConversationalTurnResult::complete(usedAi: true)
            : MarketplaceIntakeConversationalTurnResult::askNext($next, usedAi: true);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function currentPeriod(): array
    {
        $start = new \DateTimeImmutable('first day of this month 00:00:00');
        $end = new \DateTimeImmutable('last day of this month 23:59:59');

        return [$start, $end];
    }

    private function markAiAssisted(Firm $firm, MarketplaceIntake $intake): void
    {
        if ($intake->ai_assisted) {
            return;
        }

        $this->tenantContext->runWithFirmContext($firm, function () use ($intake) {
            $intake->update(['ai_assisted' => true]);
        });
    }

    private function assertBelongsToFirm(Firm $firm, MarketplaceIntake $intake): void
    {
        if ((int) $intake->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This marketplace intake does not belong to this firm.');
        }
    }
}
