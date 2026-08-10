<?php

namespace App\Enums;

/**
 * AutomationActionExecutionStatus — Event-Driven Automation Engine,
 * item 10. Exactly the six states the master prompt names.
 * RequiresReview covers BOTH "this action's risk level demands human
 * approval before it may run" AND "this action failed in a way that is
 * a genuine business-state problem, not a transient fault" — both are
 * "stop and let a human decide," the same terminal-pending shape.
 */
enum AutomationActionExecutionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case RetryScheduled = 'retry_scheduled';
    case RequiresReview = 'requires_review';
}
