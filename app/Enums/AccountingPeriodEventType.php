<?php

namespace App\Enums;

/**
 * AccountingPeriodEventType — Accounting Integrity Hardening Pass, item
 * 7. The closed set of transitions AccountingPeriodCloseService may
 * record to accounting_period_events.
 */
enum AccountingPeriodEventType: string
{
    case Closed = 'closed';
    case Reopened = 'reopened';
}
