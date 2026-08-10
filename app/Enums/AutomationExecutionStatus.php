<?php

namespace App\Enums;

/**
 * AutomationExecutionStatus — automation_executions.status. One row per
 * (rule, domain_event) match attempt.
 */
enum AutomationExecutionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
