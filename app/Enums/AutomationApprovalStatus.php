<?php

namespace App\Enums;

/**
 * AutomationApprovalStatus — automation_action_executions.approval_status.
 * Null for an action that never needed approval (AutoAllowed).
 * Populated only for a RequiresApproval action: Pending the moment its
 * AutomationActionExecution row is created (status=RequiresReview),
 * Approved/Rejected only via AutomationApprovalService — never by any
 * automated process, "automation may not approve itself."
 */
enum AutomationApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
