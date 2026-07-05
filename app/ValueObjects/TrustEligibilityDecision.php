<?php

namespace App\ValueObjects;

/**
 * TrustEligibilityDecision — the explicit, never-implicit result of
 * TrustEligibilityService::evaluate(). Mirrors Phase 11's
 * PdfAccessDecision / Phase 12's ExpenseInvoiceEligibilityDecision: a
 * boolean is never returned bare, so every caller and every test can
 * see exactly which of the five required conditions failed.
 */
final readonly class TrustEligibilityDecision
{
    public function __construct(
        public bool $allowed,
        public string $reason,
    ) {
    }

    public static function allow(): self
    {
        return new self(allowed: true, reason: 'Firm satisfies all trust eligibility conditions.');
    }

    public static function deny(string $reason): self
    {
        return new self(allowed: false, reason: $reason);
    }
}
