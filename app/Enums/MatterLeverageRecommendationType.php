<?php

namespace App\Enums;

/**
 * MatterLeverageRecommendationType — Leverage Ratio Optimizer, item
 * 12. The closed vocabulary LeverageRecommendationService may create.
 * Exactly the nine types the master spec itself names — never created
 * merely because the enum case exists; each requires its own
 * deterministic triggering metric (see that service's own docblock for
 * the exact rule per type).
 */
enum MatterLeverageRecommendationType: string
{
    case AttorneyTimeHigh = 'attorney_time_high';
    case SupportStaffUnderused = 'support_staff_underused';
    case TaskRoleMismatch = 'task_role_mismatch';
    case ProjectedMarginAtRisk = 'projected_margin_at_risk';
    case LaborCostAheadOfProgress = 'labor_cost_ahead_of_progress';
    case StaffBottleneck = 'staff_bottleneck';
    case OverCapacity = 'over_capacity';
    case UnderutilizedCapacity = 'underutilized_capacity';
    case FlatFeeLaborRisk = 'flat_fee_labor_risk';
}
