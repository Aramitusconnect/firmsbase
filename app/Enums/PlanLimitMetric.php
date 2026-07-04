<?php

namespace App\Enums;

/**
 * PlanLimitMetric — plan_limits.metric. Not given exact values by the
 * master plan; proposed during Phase 6 planning and approved. Seat
 * limits are tracked per SeatClass here (seats_attorney/seats_staff/
 * seats_read_only) rather than a single generic "seats" metric, since
 * downgrade evaluation must validate seat classes individually
 * (project rule 5/10). support_access_level is deliberately NOT a
 * limit metric — it lives on plans.support_access_level as a plan
 * setting, not a numeric/enforceable limit (approved decision).
 */
enum PlanLimitMetric: string
{
    case SeatsAttorney = 'seats_attorney';
    case SeatsStaff = 'seats_staff';
    case SeatsReadOnly = 'seats_read_only';
    case StorageGb = 'storage_gb';
    case AiTokensMonthly = 'ai_tokens_monthly';
    case ApiCallsMonthly = 'api_calls_monthly';
}
