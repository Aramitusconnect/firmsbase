<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Models\MarketplaceIntake;
use App\Models\Firm;
use App\Models\IntakeTemplate;
use App\Models\IntakeTemplateQuestion;
use App\Services\IntakeTemplateService;
use App\Services\TenantContextService;

/**
 * MarketplaceIntakeAnswerService — Mission 3 (MyAttorney Conversion +
 * AI Intake), checkpoint 6. The SINGLE writer of
 * MarketplaceIntake::structured_data — every path that produces an
 * answer (the deterministic questionnaire directly, or
 * MarketplaceIntakeConversationalAssistantService's AI-assisted
 * extraction) goes through saveAnswers() here, and every save is
 * validated through IntakeTemplateService::validateResponses() against
 * the SAME merged (existing + new) answer set. This is what "AI
 * conversation must not become the source of truth" means in code: an
 * AI-assisted turn never writes structured_data directly — it always
 * hands its extracted value to this same validator.
 *
 * conversation_transcript is a strictly separate column, appended to
 * only by appendTranscriptEntry() — never read by validateResponses(),
 * never merged into structured_data. A raw transcript is audit/context
 * only, never itself authoritative intake data.
 */
class MarketplaceIntakeAnswerService
{
    public function __construct(
        private readonly IntakeTemplateService $templateService = new IntakeTemplateService,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function attachTemplate(Firm $firm, MarketplaceIntake $intake, IntakeTemplate $template): MarketplaceIntake
    {
        $this->assertBelongsToFirm($firm, $intake);

        return $this->tenantContext->runWithFirmContext($firm, function () use ($intake, $template) {
            $intake->update(['intake_template_id' => $template->id]);

            return $intake->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $responses
     * @return array<string, string> validation errors (empty = saved)
     */
    public function saveAnswers(Firm $firm, MarketplaceIntake $intake, array $responses): array
    {
        $this->assertBelongsToFirm($firm, $intake);

        $template = $intake->intakeTemplate;

        if ($template === null) {
            throw new \RuntimeException('This intake has no attached template — cannot save answers.');
        }

        $existing = $intake->structured_data ?? [];
        $merged = array_merge($existing, $responses);

        // validateResponses() checks the FULL merged set, including
        // later questions this turn never touched — a legitimate
        // partial save (question 1 of N) would otherwise always be
        // rejected for a later required question's own missing value.
        // Only errors on the field(s) THIS call is actually saving are
        // relevant here; whether every question has been answered is
        // enforced separately, at final submission time.
        $allErrors = $this->templateService->validateResponses($template, $merged);
        $errors = array_intersect_key($allErrors, $responses);

        if ($errors !== []) {
            return $errors;
        }

        $this->tenantContext->runWithFirmContext($firm, function () use ($intake, $merged) {
            $intake->update(['structured_data' => $merged]);
        });

        return [];
    }

    /**
     * First applicable (not conditionally-hidden), unanswered question
     * in sort_order — null once every applicable question has an
     * answer. Used identically by the deterministic questionnaire and
     * the AI-assisted conversational flow, so both paths ask the exact
     * same next question in the exact same order.
     */
    public function nextQuestion(Firm $firm, MarketplaceIntake $intake): ?IntakeTemplateQuestion
    {
        $this->assertBelongsToFirm($firm, $intake);

        $template = $intake->intakeTemplate;

        if ($template === null) {
            return null;
        }

        $responses = $intake->structured_data ?? [];

        foreach ($this->templateService->questionsFor($template) as $question) {
            if (! $this->templateService->isQuestionApplicable($question, $responses)) {
                continue;
            }

            $answer = $responses[$question->question_code] ?? null;

            if ($answer === null || $answer === '') {
                return $question;
            }
        }

        return null;
    }

    public function appendTranscriptEntry(Firm $firm, MarketplaceIntake $intake, string $role, string $content): MarketplaceIntake
    {
        $this->assertBelongsToFirm($firm, $intake);

        return $this->tenantContext->runWithFirmContext($firm, function () use ($intake, $role, $content) {
            $transcript = $intake->conversation_transcript ?? [];

            $transcript[] = [
                'role' => $role,
                'content' => $content,
                'at' => now()->toIso8601String(),
            ];

            $intake->update(['conversation_transcript' => $transcript]);

            return $intake->fresh();
        });
    }

    private function assertBelongsToFirm(Firm $firm, MarketplaceIntake $intake): void
    {
        if ((int) $intake->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This marketplace intake does not belong to this firm.');
        }
    }
}
