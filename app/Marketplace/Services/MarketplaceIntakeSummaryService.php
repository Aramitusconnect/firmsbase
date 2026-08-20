<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Enums\AiUsageActionType;
use App\Marketplace\Models\MarketplaceIntake;
use App\Models\Firm;
use App\Models\IntakeTemplateQuestion;
use App\Models\User;
use App\Services\AiModeResolutionService;
use App\Services\AiProviderResolver;
use App\Services\AiUsageRecorderService;
use App\Services\IntakeTemplateService;
use App\Services\PromptInjectionResistanceService;
use App\Services\TenantContextService;
use App\ValueObjects\AiPromptRequest;

/**
 * MarketplaceIntakeSummaryService — Mission 3 (MyAttorney Conversion +
 * AI Intake), checkpoint 9. A disposable, regenerable Firm-reviewer aid
 * — never authoritative, never shown to the anonymous visitor, and
 * never a substitute for the reviewer actually reading
 * structured_data/documents/conflict signals themselves. This is the
 * FIRST real Firm-User-authenticated AI call in this codebase:
 * AiUsageRecorderService::record(Firm, User, ...) — the mature,
 * Firm+User-scoped usage recorder every earlier Mission 3 checkpoint
 * deliberately left untouched — is called here for real, for the
 * first time from a UI action, with its own existing mode/entitlement/
 * budget gates applying exactly as they do for every other Firm AI
 * feature in this codebase. This is NOT the anonymous-actor
 * MarketplaceAiUsageRecorderService path checkpoint 6 built — a
 * Firm-authenticated reviewer generating a summary has a real User
 * row, so the canonical recorder is the correct one, not the
 * anonymous-actor one.
 *
 * The visitor's own submitted answers are placed ONLY in
 * AiPromptRequest::documentDerivedText, never instructionText — the
 * same rule every other AI call site in this codebase follows (see
 * AiPromptRequest's own docblock) — so nothing a prospect typed during
 * intake can ever be interpreted as an instruction to this call.
 * PromptInjectionResistanceService::evaluate() runs the same defense-
 * in-depth detection/audit pass MarketplaceIssueClassifierService uses
 * — structured_data is visitor-controlled, so this call site earns
 * the same layer.
 *
 * Mission 3, checkpoint 15 (adversarial audit) fix: the platform AI
 * kill switch is now checked via AiModeResolutionService::
 * assertEnabled() BEFORE the provider call below, not only via
 * AiUsageRecorderService::record()'s own post-hoc check — the earlier
 * ordering meant a real (non-fake) provider would already have been
 * invoked with the prospect's intake data before the kill switch's
 * effect was ever observed.
 */
class MarketplaceIntakeSummaryService
{
    private const MAX_SUMMARY_LENGTH = 4000;

    public function __construct(
        private readonly AiProviderResolver $providerResolver,
        private readonly AiUsageRecorderService $usageRecorder,
        private readonly AiModeResolutionService $modeResolution,
        private readonly PromptInjectionResistanceService $promptInjectionResistance,
        private readonly IntakeTemplateService $templateService = new IntakeTemplateService,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    /**
     * @throws \RuntimeException if the provider call or usage recording fails
     *                           (mode disabled, budget exceeded, entitlement missing) — the caller
     *                           decides how to surface that to the reviewer; this method never
     *                           silently persists a partial/failed summary.
     */
    public function generate(Firm $firm, MarketplaceIntake $intake, User $user): MarketplaceIntake
    {
        $this->assertBelongsToFirm($firm, $intake);
        $this->modeResolution->assertEnabled($firm);

        // Resolved from the intake's OWN firm, so a summary is generated with
        // that firm's credential, against that firm's budget, and never with a
        // platform-wide provider. FirmsVault holds no platform credential and
        // deliberately does not intend to.
        $adapter = $this->tenantContext->runWithFirmContextWithoutTransaction(
            $firm,
            fn () => $this->providerResolver->adapterFor($firm),
        );

        if ($adapter === null) {
            // Fail closed. Firm review must still work without a summary, so
            // callers treat this as "summary unavailable", not a page error.
            throw new \RuntimeException('No AI provider is configured for this firm.');
        }

        $digest = $this->tenantContext->runWithFirmContext($firm, fn () => $this->buildAnswerDigest($intake));

        $request = new AiPromptRequest(
            provider: $adapter->provider(),
            model: $adapter->model(),
            actionType: AiUsageActionType::Summarization,
            instructionText: 'Summarize this legal intake for the reviewing attorney. This is a proposal only, never legal advice, and must be verified by the reviewer before acting on it.',
            documentDerivedText: $digest,
            matterIds: [],
        );

        $this->promptInjectionResistance->evaluate($request);

        $response = $adapter->generate($request);

        $this->usageRecorder->record($firm, $user, $request, $response);

        return $this->tenantContext->runWithFirmContext($firm, function () use ($intake, $response) {
            $intake->update([
                'ai_summary' => mb_substr($response->outputText, 0, self::MAX_SUMMARY_LENGTH),
                'ai_summary_generated_at' => now(),
            ]);

            return $intake->fresh();
        });
    }

    /**
     * Resolves each answered question_code to its own template label
     * (never the raw code) before handing it to the provider —
     * IntakeTemplateService::questionsFor() is the single source of
     * truth for what a code means, mirrored here rather than
     * duplicated. Never includes conversation_transcript (raw AI
     * back-and-forth, not the validated answer of record) or any
     * document content — only the SAME structured_data
     * saveAnswers()/the deterministic questionnaire itself treats as
     * the intake's own source of truth.
     */
    private function buildAnswerDigest(MarketplaceIntake $intake): string
    {
        $responses = $intake->structured_data ?? [];

        if ($responses === [] || $intake->intakeTemplate === null) {
            return 'No structured intake answers were captured for this prospect.';
        }

        $labelsByCode = $this->templateService->questionsFor($intake->intakeTemplate)
            ->keyBy('question_code')
            ->map(fn (IntakeTemplateQuestion $question) => $question->label);

        $lines = [];

        foreach ($responses as $code => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $label = $labelsByCode[$code] ?? $code;
            $stringValue = is_scalar($value) ? (string) $value : json_encode($value);
            $lines[] = "{$label}: {$stringValue}";
        }

        return $lines === [] ? 'No structured intake answers were captured for this prospect.' : implode("\n", $lines);
    }

    private function assertBelongsToFirm(Firm $firm, MarketplaceIntake $intake): void
    {
        if ((int) $intake->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This marketplace intake does not belong to this firm.');
        }
    }
}
