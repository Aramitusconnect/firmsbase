<?php

namespace App\Enums;

/**
 * PlatformSalesTaskStatus — platform_sales_tasks.status. Platform sales
 * follow-up tasks only. Deliberately unrelated to Phase 4's generic
 * task status values (firm/matter task management) — never shared or
 * reused, per the Phase 7 naming-conflict decision.
 */
enum PlatformSalesTaskStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
