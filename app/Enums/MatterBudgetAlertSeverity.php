<?php

namespace App\Enums;

/**
 * MatterBudgetAlertSeverity — Predictive Matter Budget Alerts, item 16.
 * Exactly the four levels the master spec names. Deterministic, never
 * inferred: Info backs the "smarter" comparative alerts (usage ahead
 * of progress, margin below target) which are informational by nature
 * rather than tied to a specific consumption tier; Warning/High/
 * OverBudget map directly to a template's own warning_threshold_percent/
 * high_threshold_percent/100% tiers.
 */
enum MatterBudgetAlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case High = 'high';
    case OverBudget = 'over_budget';
}
