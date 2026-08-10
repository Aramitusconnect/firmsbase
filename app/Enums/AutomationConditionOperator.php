<?php

namespace App\Enums;

/**
 * AutomationConditionOperator — Event-Driven Automation Engine, item 5.
 * The closed, typed condition vocabulary — exactly the operators named
 * in the master prompt, no more. No eval, no eloquent method
 * invocation, no reflection: ConditionEvaluatorService switches on this
 * enum against a plain scalar value already extracted from an
 * event-type-specific field allowlist (AutomationFieldAllowlistRegistry)
 * — there is no path from a stored condition row to executable code.
 */
enum AutomationConditionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case GreaterThan = 'greater_than';
    case GreaterThanOrEqual = 'greater_than_or_equal';
    case LessThan = 'less_than';
    case Contains = 'contains';
    case In = 'in';
    case NotIn = 'not_in';
    case IsNull = 'is_null';
    case IsNotNull = 'is_not_null';

    /**
     * days_since(field) >= value — field must resolve to a date/datetime.
     */
    case DaysSince = 'days_since';

    /**
     * days_until(field) <= value — field must resolve to a date/datetime.
     */
    case DaysUntil = 'days_until';
}
