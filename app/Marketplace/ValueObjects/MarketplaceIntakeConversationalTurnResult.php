<?php

declare(strict_types=1);

namespace App\Marketplace\ValueObjects;

use App\Models\IntakeTemplateQuestion;

/**
 * MarketplaceIntakeConversationalTurnResult — Mission 3 (MyAttorney
 * Conversion + AI Intake), checkpoint 6. Read-only result of
 * MarketplaceIntakeConversationalAssistantService::respond(). Always
 * carries $pendingQuestion (or null once complete) so the visitor's
 * UI can always show progress ("question 3 of 7"-style) and always
 * has something well-defined to render, regardless of whether the
 * turn used AI or fell back to the deterministic questionnaire.
 *
 * $usedAi is false for every fallback/validation-failure/complete-
 * without-a-final-AI-turn case — a UI can use it to tell the visitor
 * "AI assistance is unavailable right now, continuing without it"
 * without needing to know why.
 *
 * $fallbackReason is INTERNAL diagnostic detail only (logging/tests) —
 * never render it to a public visitor, matching this codebase's
 * established "collapse to false, never disclose why" convention.
 */
final class MarketplaceIntakeConversationalTurnResult
{
    /**
     * @param  array<string, string>  $validationErrors
     */
    public function __construct(
        public readonly bool $complete,
        public readonly bool $usedAi,
        public readonly ?IntakeTemplateQuestion $pendingQuestion = null,
        public readonly ?string $fallbackReason = null,
        public readonly array $validationErrors = [],
    ) {}

    public static function complete(bool $usedAi): self
    {
        return new self(complete: true, usedAi: $usedAi);
    }

    public static function askNext(IntakeTemplateQuestion $question, bool $usedAi): self
    {
        return new self(complete: false, usedAi: $usedAi, pendingQuestion: $question);
    }

    public static function fallbackToDeterministic(IntakeTemplateQuestion $question, string $fallbackReason): self
    {
        return new self(complete: false, usedAi: false, pendingQuestion: $question, fallbackReason: $fallbackReason);
    }

    /**
     * @param  array<string, string>  $validationErrors
     */
    public static function validationFailed(IntakeTemplateQuestion $question, array $validationErrors): self
    {
        return new self(complete: false, usedAi: false, pendingQuestion: $question, validationErrors: $validationErrors);
    }
}
