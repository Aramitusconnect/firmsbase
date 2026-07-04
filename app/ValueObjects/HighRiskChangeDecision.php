<?php

namespace App\ValueObjects;

use App\Enums\HighRiskChangeRequestStatus;

/**
 * HighRiskChangeDecision — returned by HighRiskPlatformChangePolicyService
 * at each step of the reason-required, two-person-approval workflow
 * (request/first-approve/second-approve/deny). This is decision-state
 * only — it never triggers execution of the underlying change (trust
 * mode activation, production deletion, payment/trust setting change,
 * emergency access). Execution remains entirely out of Phase 7 scope.
 */
final readonly class HighRiskChangeDecision
{
    public function __construct(
        public HighRiskChangeRequestStatus $status,
        public bool $requiresSecondApproval,
        public ?string $reason = null,
    ) {
    }
}
