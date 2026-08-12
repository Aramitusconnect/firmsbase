<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\AiUsageActionType;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 5 — the
 * new IntakeClassification action type must never be treated as
 * high-risk (it never produces client-facing content, legal advice,
 * or anything requiring human approval before use).
 */
class AiUsageActionTypeIntakeClassificationTest extends TestCase
{
    public function test_intake_classification_is_not_high_risk(): void
    {
        $this->assertFalse(AiUsageActionType::IntakeClassification->isHighRisk());
    }

    public function test_intake_classification_has_no_approval_category(): void
    {
        $this->assertNull(AiUsageActionType::IntakeClassification->toApprovalCategory());
    }

    public function test_intake_classification_is_backed_by_the_expected_string_value(): void
    {
        $this->assertSame('intake_classification', AiUsageActionType::IntakeClassification->value);
    }
}
