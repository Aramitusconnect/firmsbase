<?php

namespace App\Services\Automation;

use App\Models\DomainEvent;
use App\Services\WebhookRetryPolicyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DomainEventClaimService — Event-Driven Automation Engine, item 9/12.
 * Atomic claim/complete/fail primitives for domain_events, mirroring
 * IntegrationOutboxEventService's own proven shape (this session's own
 * audit recommendation: reuse the pattern, not literally repoint that
 * integration-scoped table). claim()'s SQL is the exact CTE-based
 * "WITH candidate AS (... FOR UPDATE SKIP LOCKED) UPDATE ... WHERE id
 * IN (SELECT id FROM candidate)" shape that service's own docblock
 * documents as required under FORCE RLS (a naive
 * "UPDATE ... WHERE id IN (SELECT ... FOR UPDATE SKIP LOCKED)" is
 * vulnerable to a Nested Loop Semi Join plan that re-evaluates the
 * inner LIMIT once per outer candidate, claiming more/fewer rows than
 * intended) — reused verbatim here, not re-derived.
 *
 * A domain_event's own processing_status tracks whether RULE MATCHING
 * completed for it (AutomationRuleMatchingService found every
 * currently-enabled applicable rule and recorded an AutomationExecution
 * per one) — NOT whether the individual actions those rules triggered
 * ultimately succeeded. Actions have their own, separate claim/retry
 * lifecycle (AutomationActionExecutionClaimService) so one action's
 * failure can never block matching, or a DIFFERENT rule's actions, for
 * the same event.
 */
class DomainEventClaimService
{
    private const STALE_LOCK_MINUTES = 15;

    private const DEFAULT_LIMIT = 25;

    public function __construct(private readonly WebhookRetryPolicyService $retryPolicy) {}

    /**
     * @return Collection<int, DomainEvent>
     */
    public function claim(int $firmId, int $limit = self::DEFAULT_LIMIT): Collection
    {
        $lockToken = (string) Str::uuid();

        $rows = DB::select(
            'WITH candidate AS ('.
            '  SELECT id FROM domain_events '.
            '  WHERE firm_id = ? AND ('.
            '    (processing_status = ? AND (next_attempt_at IS NULL OR next_attempt_at <= statement_timestamp())) '.
            "    OR (processing_status = ? AND locked_at <= statement_timestamp() - (? || ' minutes')::interval)".
            '  ) '.
            '  ORDER BY id ASC LIMIT ? '.
            '  FOR UPDATE SKIP LOCKED'.
            ') '.
            'UPDATE domain_events '.
            'SET processing_status = ?, lock_token = ?, locked_at = to_timestamp(ceil(extract(epoch from statement_timestamp()))), attempts = attempts + 1 '.
            'WHERE id IN (SELECT id FROM candidate) '.
            'RETURNING *',
            [
                $firmId,
                'pending',
                'claimed', self::STALE_LOCK_MINUTES,
                $limit,
                'claimed', $lockToken,
            ]
        );

        return DomainEvent::hydrate(array_map(fn ($row) => (array) $row, $rows));
    }

    public function complete(int $id, string $lockToken): ?DomainEvent
    {
        $row = DB::selectOne(
            'UPDATE domain_events '.
            "SET processing_status = 'processed', processed_at = now() ".
            "WHERE id = ? AND lock_token = ? AND processing_status = 'claimed' ".
            'RETURNING *',
            [$id, $lockToken]
        );

        return $row === null ? null : DomainEvent::hydrate([(array) $row])->first();
    }

    /**
     * $terminal=true forces immediate dead-lettering regardless of
     * attempts remaining — reserved for a genuinely non-retryable
     * systemic condition (there is none in this pass's own call sites;
     * every real failure path retries with backoff up to max_attempts,
     * consistent with rule-matching itself being a cheap, idempotent
     * operation that should keep trying rather than give up early).
     */
    public function fail(int $id, string $lockToken, string $reason, bool $terminal = false): ?DomainEvent
    {
        $current = DB::table('domain_events')
            ->where('id', $id)
            ->where('lock_token', $lockToken)
            ->where('processing_status', 'claimed')
            ->first();

        if ($current === null) {
            return null;
        }

        $attempts = (int) $current->attempts;
        $maxAttempts = (int) $current->max_attempts;
        $category = $terminal ? 'validation_failed' : null;

        if ($this->retryPolicy->isExhausted($attempts, ['max_attempts' => $maxAttempts, 'category' => $category])) {
            $row = DB::selectOne(
                'UPDATE domain_events '.
                "SET processing_status = 'dead_lettered', dead_lettered_at = now(), last_error = ? ".
                "WHERE id = ? AND lock_token = ? AND processing_status = 'claimed' ".
                'RETURNING *',
                [$reason, $id, $lockToken]
            );

            return $row === null ? null : DomainEvent::hydrate([(array) $row])->first();
        }

        $delaySeconds = $this->retryPolicy->nextAttemptDelaySeconds($attempts, ['max_attempts' => $maxAttempts]);

        $row = DB::selectOne(
            'UPDATE domain_events '.
            "SET processing_status = 'pending', lock_token = NULL, locked_at = NULL, ".
            'next_attempt_at = to_timestamp(ceil(extract(epoch from statement_timestamp()))) + (? * interval \'1 second\'), last_error = ? '.
            "WHERE id = ? AND lock_token = ? AND processing_status = 'claimed' ".
            'RETURNING *',
            [$delaySeconds, $reason, $id, $lockToken]
        );

        return $row === null ? null : DomainEvent::hydrate([(array) $row])->first();
    }
}
