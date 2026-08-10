<?php

namespace App\Services\Automation;

use App\Enums\AutomationActionExecutionStatus;
use App\Enums\AutomationExecutionStatus;
use App\Models\AutomationExecution;

/**
 * AutomationExecutionCompletionService — Event-Driven Automation
 * Engine, item 9. AutomationRuleMatchingService leaves a matched
 * AutomationExecution in status=Pending once it has created every
 * action's AutomationActionExecution row — matching is done, but the
 * actions themselves (a separate claim/retry lifecycle, see
 * DomainEventClaimService's own docblock on why the two are kept
 * apart) have not necessarily run yet. This service is the ONLY place
 * that closes the loop: once every sibling action for an execution has
 * reached a terminal status (Succeeded or Failed — never while one is
 * still Pending/Running/RetryScheduled/RequiresReview), the execution
 * itself flips to Completed (all actions succeeded) or Failed (at
 * least one did not), with its own completed_at stamped for
 * observability (item 18 — "oldest queued automation" would otherwise
 * be misleading if a fully-finished execution stayed Pending forever).
 */
class AutomationExecutionCompletionService
{
    private const TERMINAL_ACTION_STATUSES = [
        AutomationActionExecutionStatus::Succeeded,
        AutomationActionExecutionStatus::Failed,
    ];

    public function refresh(AutomationExecution $execution): void
    {
        $execution = $execution->fresh();

        if ($execution === null || $execution->status !== AutomationExecutionStatus::Pending) {
            return;
        }

        $actionExecutions = $execution->actionExecutions()->get();

        if ($actionExecutions->isEmpty()) {
            return;
        }

        $allTerminal = $actionExecutions->every(
            fn ($action) => in_array($action->status, self::TERMINAL_ACTION_STATUSES, true)
        );

        if (! $allTerminal) {
            return;
        }

        $anyFailed = $actionExecutions->contains(
            fn ($action) => $action->status === AutomationActionExecutionStatus::Failed
        );

        $execution->update([
            'status' => $anyFailed ? AutomationExecutionStatus::Failed : AutomationExecutionStatus::Completed,
            'completed_at' => now(),
            'failure_reason' => $anyFailed
                ? 'One or more actions failed; see automation_action_executions for detail.'
                : null,
        ]);
    }
}
