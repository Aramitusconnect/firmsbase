<?php

namespace App\Enums;

/**
 * CommissionEventStatus — commission_events.status. This is the
 * commission's OWN lifecycle, distinct from any Phase 6 platform
 * billing status. A commission_event's status is derived by
 * CommissionEligibilityService from platform_invoices/platform_payments/
 * platform_billing_events — it never becomes a substitute ledger for
 * those tables.
 */
enum CommissionEventStatus: string
{
    case Pending = 'pending';
    case Payable = 'payable';
    case Blocked = 'blocked';
    case Reversed = 'reversed';
    case Paid = 'paid';
}
