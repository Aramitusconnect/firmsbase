<?php

namespace App\ValueObjects;

use App\Enums\ExpenseApprovalStatus;

/**
 * ExpenseApprovalDecision — pure decision logic, separated from
 * persistence, mirroring Phase 3's PaymentClassificationService /
 * PaymentClassificationResult split exactly. ExpenseApprovalService::decide()
 * returns this VO; ExpenseApprovalService::recordDecision() persists it.
 */
final readonly class ExpenseApprovalDecision
{
    public function __construct(
        public ExpenseApprovalStatus $status,
        public bool $accepted,
        public ?string $reason = null,
    ) {
    }
}
