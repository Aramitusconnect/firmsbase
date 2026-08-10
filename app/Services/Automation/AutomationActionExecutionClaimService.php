<?php

namespace App\Services\Automation;

use App\Models\AutomationActionExecution;
use App\Services\WebhookRetryPolicyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AutomationActionExecutionClaimService — Event-Driven Automation
 * Engine, item 9/10. Same claim/complete/fail primitive shape as
 * DomainEventClaimService, scoped to automation_action_executions —
 * the layer that actually invokes an AutomationActionHandler. Kept
 * deliberately separate from DomainEventClaimService so a slow/failing
 * action can never block rule-matching for other events, and so each
 * action gets its own independent retry/backoff lifecycle.
 *
 * Only claims rows whose status is Pending or RetryScheduled — never
 * RequiresReview (an action awaiting human approval is claimed only
 * after AutomationApprovalService flips it back to Pending, never by
 * this claim loop itself; "automation may not approve itself").
 */
class AutomationActionExecutionClaimService
{
    private const STALE_LOCK_MINUTES = 15;

    private const DEFAULT_LIMIT = 25;

    public function __construct(private readonly WebhookRetryPolicyService $retryPolicy) {}

    /**
     * @return Collection<int, AutomationActionExecution>
     */
    public function claim(int $firmId, int $limit = self::DEFAULT_LIMIT): Collection
    {
        $lockToken = (string) Str::uuid();

        $rows = DB::select(
            'WITH candidate AS ('.
            '  SELECT id FROM automation_action_executions '.
            '  WHERE firm_id = ? AND ('.
            "    (status IN ('pending', 'retry_scheduled') AND (next_attempt_at IS NULL OR next_attempt_at <= statement_timestamp())) ".
            "    OR (status = 'running' AND started_at <= statement_timestamp() - (? || ' minutes')::interval)".
            '  ) '.
            '  ORDER BY id ASC LIMIT ? '.
            '  FOR UPDATE SKIP LOCKED'.
            ') '.
            'UPDATE automation_action_executions '.
            "SET status = 'running', started_at = to_timestamp(ceil(extract(epoch from statement_timestamp()))), attempts = attempts + 1 ".
            'WHERE id IN (SELECT id FROM candidate) '.
            'RETURNING *',
            [$firmId, self::STALE_LOCK_MINUTES, $limit]
        );

        return AutomationActionExecution::hydrate(array_map(fn ($row) => (array) $row, $rows));
    }

    public function complete(int $id, ?string $resultReferenceType = null, ?int $resultReferenceId = null): ?AutomationActionExecution
    {
        $row = DB::selectOne(
            'UPDATE automation_action_executions '.
            "SET status = 'succeeded', completed_at = now(), result_reference_type = ?, result_reference_id = ? ".
            "WHERE id = ? AND status = 'running' ".
            'RETURNING *',
            [$resultReferenceType, $resultReferenceId, $id]
        );

        return $row === null ? null : AutomationActionExecution::hydrate([(array) $row])->first();
    }

    /**
     * A Skipped outcome is recorded as succeeded (it ran to completion,
     * no error) but carries the reason in last_error for visibility —
     * distinct from a genuine failure.
     */
    public function skip(int $id, string $reason): ?AutomationActionExecution
    {
        $row = DB::selectOne(
            'UPDATE automation_action_executions '.
            "SET status = 'succeeded', completed_at = now(), last_error = ? ".
            "WHERE id = ? AND status = 'running' ".
            'RETURNING *',
            [$reason, $id]
        );

        return $row === null ? null : AutomationActionExecution::hydrate([(array) $row])->first();
    }

    /**
     * $terminal=true forces immediate Failed status (never retried) —
     * used for AutomationActionPermanentException. Otherwise schedules
     * a retry with backoff (RetryScheduled), or Failed once max_attempts
     * is exhausted.
     */
    public function fail(int $id, string $reason, bool $terminal = false): ?AutomationActionExecution
    {
        $current = DB::table('automation_action_executions')
            ->where('id', $id)
            ->where('status', 'running')
            ->first();

        if ($current === null) {
            return null;
        }

        $attempts = (int) $current->attempts;
        $maxAttempts = (int) $current->max_attempts;
        $category = $terminal ? 'validation_failed' : null;

        if ($this->retryPolicy->isExhausted($attempts, ['max_attempts' => $maxAttempts, 'category' => $category])) {
            $row = DB::selectOne(
                'UPDATE automation_action_executions '.
                "SET status = 'failed', completed_at = now(), last_error = ? ".
                "WHERE id = ? AND status = 'running' ".
                'RETURNING *',
                [$reason, $id]
            );

            return $row === null ? null : AutomationActionExecution::hydrate([(array) $row])->first();
        }

        $delaySeconds = $this->retryPolicy->nextAttemptDelaySeconds($attempts, ['max_attempts' => $maxAttempts]);

        $row = DB::selectOne(
            'UPDATE automation_action_executions '.
            "SET status = 'retry_scheduled', ".
            'next_attempt_at = to_timestamp(ceil(extract(epoch from statement_timestamp()))) + (? * interval \'1 second\'), last_error = ? '.
            "WHERE id = ? AND status = 'running' ".
            'RETURNING *',
            [$delaySeconds, $reason, $id]
        );

        return $row === null ? null : AutomationActionExecution::hydrate([(array) $row])->first();
    }

    /**
     * Transitions a claimed row straight to RequiresReview instead of
     * running the handler — item 7's actual gate. Never claims a
     * RequiresReview row again afterward (excluded from claim()'s own
     * status IN (...) predicate above); only AutomationApprovalService
     * moves it back to Pending, and only after a real human decision.
     */
    public function requireReview(int $id, string $reason): ?AutomationActionExecution
    {
        $row = DB::selectOne(
            'UPDATE automation_action_executions '.
            "SET status = 'requires_review', last_error = ? ".
            "WHERE id = ? AND status = 'running' ".
            'RETURNING *',
            [$reason, $id]
        );

        return $row === null ? null : AutomationActionExecution::hydrate([(array) $row])->first();
    }
}
