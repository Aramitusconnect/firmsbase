<?php

namespace App\Enums;

/**
 * PlatformPaymentAttemptStatus — platform_payment_attempts.status.
 * Proposed during Phase 6 planning and approved.
 */
enum PlatformPaymentAttemptStatus: string
{
    case Attempted = 'attempted';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
