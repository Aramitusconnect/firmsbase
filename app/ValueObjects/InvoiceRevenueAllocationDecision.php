<?php

namespace App\ValueObjects;

/**
 * InvoiceRevenueAllocationDecision — Mixed-Invoice Revenue Allocation
 * pass, item 4. The result of
 * PaymentApplicationService::resolveInvoiceRevenueAllocation() — a
 * pure computation, never a write. Exactly one of two shapes:
 *
 *   - resolved (isAmbiguous() === false): feeCents/costCents are safe
 *     to post now and sum exactly to the payment amount that produced
 *     this decision.
 *   - ambiguous (isAmbiguous() === true): no accounting/application
 *     consequence may be posted yet — the caller must create a
 *     PendingPaymentAllocation instead. feeCents/costCents are both 0
 *     and must never be read in this state.
 */
class InvoiceRevenueAllocationDecision
{
    private function __construct(
        public readonly bool $isAmbiguous,
        public readonly int $feeCents,
        public readonly int $costCents,
        public readonly ?string $reason,
    ) {}

    public static function resolved(int $feeCents, int $costCents): self
    {
        return new self(false, $feeCents, $costCents, null);
    }

    public static function ambiguous(string $reason): self
    {
        return new self(true, 0, 0, $reason);
    }

    public function isAmbiguous(): bool
    {
        return $this->isAmbiguous;
    }
}
