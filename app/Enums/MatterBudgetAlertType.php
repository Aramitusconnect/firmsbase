<?php

namespace App\Enums;

/**
 * MatterBudgetAlertType — Predictive Matter Budget Alerts, item 12/13.
 * The closed vocabulary matter_budget_alerts.alert_type may take.
 * RoleHoursThreshold/ExpenseThreshold/TotalLaborThreshold are pure
 * percent-of-budget-consumed tiers (metric_key names the specific role
 * or MatterBudgetExpenseCategory being measured). UsageAheadOfProgress
 * and MarginBelowTarget are the "smarter" comparative alerts the spec
 * explicitly asks for (item 12: "compare budget consumption to
 * progress" — never spammed for a tiny variance, see
 * MatterBudgetAlertService's own materiality gate). ProjectedOverrun
 * fires from the forecasting engine's own run-rate projection, not
 * from current consumption alone.
 */
enum MatterBudgetAlertType: string
{
    case RoleHoursThreshold = 'role_hours_threshold';
    case TotalLaborThreshold = 'total_labor_threshold';
    case ExpenseThreshold = 'expense_threshold';
    case UsageAheadOfProgress = 'usage_ahead_of_progress';
    case MarginBelowTarget = 'margin_below_target';
    case ProjectedOverrun = 'projected_overrun';
}
