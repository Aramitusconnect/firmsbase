<?php

namespace App\Enums;

/**
 * PendingPaymentAllocationStatus — Mixed-Invoice Revenue Allocation
 * pass, item 3. Exactly two states: a pending allocation is created
 * once (PaymentApplicationService, via ManualPaymentService::submit())
 * and resolved at most once (PaymentAllocationResolutionService) —
 * never reopened, never re-resolved.
 */
enum PendingPaymentAllocationStatus: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';
}
