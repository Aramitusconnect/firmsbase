<?php

namespace App\Enums;

/**
 * PendingPaymentAllocationStatus — Mixed-Invoice Revenue Allocation
 * pass, item 3. A pending allocation is created once
 * (PaymentApplicationService, via ManualPaymentService::submit()) and
 * reaches exactly one of two terminal states, each exactly once —
 * never reopened, never re-decided:
 *
 *   Resolved — an authorized user supplied the fee/cost split
 *   (PaymentAllocationResolutionService::resolve()).
 *
 *   Cancelled — Pending-Cash Accounting pass. The underlying Payment
 *   was fully refunded or charged back BEFORE its allocation was ever
 *   resolved (OperatingPaymentRefundService/OperatingChargebackService).
 *   No revenue was ever recognized for it, so there is nothing to
 *   reclassify — only the cash-received entry is reversed. Distinct
 *   from Resolved: a Cancelled row's resolved_fee_cents/
 *   resolved_cost_cents/resolved_by_firm_user_id stay null, since no
 *   human ever decided a split for money that was given back.
 */
enum PendingPaymentAllocationStatus: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Cancelled = 'cancelled';
}
