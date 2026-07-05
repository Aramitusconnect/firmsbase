<?php

namespace App\ValueObjects;

/**
 * ExpenseInvoiceEligibilityDecision — the explicit, never-implicit
 * result of ReimbursableExpenseInvoiceEligibilityService::evaluate().
 * Mirrors Phase 11's PdfAccessDecision: a boolean is never returned
 * bare, so every caller (and every test) can see WHY an expense was or
 * was not allowed onto an invoice.
 */
final readonly class ExpenseInvoiceEligibilityDecision
{
    public function __construct(
        public bool $allowed,
        public string $reason,
    ) {
    }

    public static function allow(string $reason = 'Expense is approved, reimbursable, and entitlement/firm-setting allow invoice reimbursement.'): self
    {
        return new self(allowed: true, reason: $reason);
    }

    public static function deny(string $reason): self
    {
        return new self(allowed: false, reason: $reason);
    }
}
