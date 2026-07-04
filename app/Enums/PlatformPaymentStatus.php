<?php

namespace App\Enums;

/**
 * PlatformPaymentStatus — platform_payments.status. Deliberately
 * separate from Phase 3's PaymentStatus (firm-client payments) — never
 * shared or reused, per project rule 1. Proposed during Phase 6
 * planning and approved.
 */
enum PlatformPaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
}
