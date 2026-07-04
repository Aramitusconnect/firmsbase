<?php

namespace App\Enums;

/**
 * PlatformRefundStatus — platform_refunds.status. Proposed during
 * Phase 6 planning and approved.
 */
enum PlatformRefundStatus: string
{
    case Requested = 'requested';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
