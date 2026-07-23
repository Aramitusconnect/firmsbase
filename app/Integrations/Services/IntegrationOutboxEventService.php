<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Data\SanitizedPayloadReference;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Services\WebhookRetryPolicyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * IntegrationOutboxEventService — the ONLY writer of
 * `integration_outbox_events` (Checkpoint 6,
 * reviews/checkpoint-06/frozen-design-post-review.md §6/§7/§11;
 * agent-6d-outbox-claiming.md). Persistence + claim/release PRIMITIVES
 * only — this class does NOT poll, does NOT dispatch a queued job, and
 * has no scheduler/cron wiring; that is explicitly later-checkpoint
 * scope (agent-6d §13).
 *
 * recordOnce() MUST be called by the caller's OWN already-open
 * transaction (the same one performing the triggering business write)
 * — this method deliberately does not open a transaction of its own,
 * since the entire point of the outbox pattern is that both writes
 * commit or roll back together.
 *
 * claim()/complete()/release()/fail()/cancel() are each a single,
 * atomic, guarded SQL statement — never a bare SELECT followed by a
 * separate UPDATE — mirroring
 * IntegrationOAuthStateService::claimAndDecrypt()'s proven discipline,
 * extended here to a multi-row SKIP LOCKED pool claim (frozen-design-
 * post-review.md §7's exact SQL, reproduced verbatim below).
 */
final class IntegrationOutboxEventService
{
    private const DEFAULT_STALE_LOCK_MINUTES = 15;

    private const DEFAULT_MAX_ATTEMPTS = 10;

    public function __construct(private readonly WebhookRetryPolicyService $retryPolicy)
    {
    }

    /**
     * Idempotent, atomic write via insertOrIgnoreReturning() + a
     * re-SELECT fallback (agent-6c-idempotency-concurrency.md §5) —
     * never throws on a legitimate retry with the SAME $domainEventId;
     * the caller always gets back the durable row either way. Must be
     * called inside the SAME transaction as the triggering business
     * write (see class docblock).
     *
     * $payload MUST already be a SanitizedPayloadReference (built via
     * IntegrationOutboxPayloadBuilderService) — this method's signature
     * structurally cannot accept a raw Eloquent Model.
     */
    public function recordOnce(
        int $firmId,
        ?int $firmIntegrationId,
        string $domainEventId,
        string $eventType,
        ?SanitizedPayloadReference $payload = null,
        ?int $maxAttempts = null,
    ): IntegrationOutboxEvent {
        $payloadArray = $payload?->toArray() ?? ['resource_type' => null, 'resource_id' => null, 'fields' => []];

        $rows = DB::table('integration_outbox_events')->insertOrIgnoreReturning(
            [
                'firm_id' => $firmId,
                'firm_integration_id' => $firmIntegrationId,
                'domain_event_id' => $domainEventId,
                'event_type' => $eventType,
                'resource_type' => $payload?->resourceType->value,
                'resource_id' => $payload?->resourceId,
                'payload_json' => json_encode($payloadArray, JSON_THROW_ON_ERROR),
                'payload_hash' => $payload?->hash(),
                'status' => 'pending',
                'attempts' => 0,
                'max_attempts' => $maxAttempts ?? self::DEFAULT_MAX_ATTEMPTS,
                'next_attempt_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            returning: ['*'],
            uniqueBy: ['firm_id', 'domain_event_id'],
        );

        $row = $rows->first() ?? DB::table('integration_outbox_events')
            ->where('firm_id', $firmId)
            ->where('domain_event_id', $domainEventId)
            ->first();

        return IntegrationOutboxEvent::hydrate([(array) $row])->first();
    }

    /**
     * Atomic multi-row claim.
     *
     * POST-DIFF-REVIEW FIX (checkpoint-06 verification pass) — rewritten
     * as a CTE. The original frozen-design-post-review.md §7 shape
     * (`UPDATE ... WHERE id IN (SELECT ... LIMIT ... FOR UPDATE SKIP
     * LOCKED)`) is vulnerable, under FORCE RLS, to a Nested Loop Semi
     * Join plan that re-executes the inner LIMIT subquery once per
     * outer-loop candidate row rather than evaluating it exactly once —
     * which can claim MORE rows than $limit, or claim a set
     * inconsistent with the intended FOR UPDATE SKIP LOCKED semantics
     * (a core correctness property: "two workers cannot claim the same
     * event", "exactly N claimed"). Rewritten as `WITH candidate AS
     * (... FOR UPDATE SKIP LOCKED) UPDATE ... WHERE id IN (SELECT id
     * FROM candidate)`: PostgreSQL never inlines/flattens a CTE that
     * contains FOR UPDATE (locking is treated as a side effect, which
     * disqualifies the optimizer's CTE-inlining path entirely — see
     * PostgreSQL's WITH Queries documentation), so `candidate` is
     * always materialized and its LIMIT + FOR UPDATE SKIP LOCKED
     * evaluated EXACTLY ONCE, before the UPDATE ever runs, regardless
     * of what join plan the UPDATE's own WHERE id IN (...) chooses. The
     * 15-minute stale-lock bound remains folded into the SAME predicate
     * (no second reclaim mechanism), config-driven via
     * config('integrations.outbox.stale_lock_minutes'); this
     * checkpoint's frozen file allowlist does not include
     * config/integrations.php, so the default (15) is supplied inline
     * here rather than via a config-file entry.
     *
     * POST-DIFF-REVIEW FIX 2 (outbox timestamp-precision race
     * remediation — agent-r5-remediation-design-review.md §2.2) — the
     * eligibility predicate, the stale-lock predicate, and the
     * locked_at write below now use statement_timestamp() instead of
     * now(): now() is frozen at transaction START, so a still-open
     * transaction (e.g. recordOnce() + claim() sharing one transaction)
     * could see a same-transaction "due now" insert as not-yet-due,
     * producing a false-negative race. statement_timestamp() is live
     * per statement instead. locked_at is additionally written via
     * to_timestamp(ceil(extract(epoch from statement_timestamp())))
     * rather than a bare now()/statement_timestamp() assignment,
     * because it is a lower-bound protective gate (stale-lock
     * reclaim) and the column's implicit round-half-up cast to
     * timestamp(0) could otherwise round the stored instant slightly
     * EARLY roughly half the time; an explicit ceiling guarantees the
     * stored value is never earlier than the true instant. The same
     * ceiling is applied to fail()'s retry next_attempt_at write below
     * for the identical reason (it is also a lower-bound gate).
     * recordOnce()'s PHP-bound writes are deliberately untouched — see
     * the design review for why floor-only PHP truncation is already
     * correct on that side once the read side is live.
     *
     * @return Collection<int, IntegrationOutboxEvent>
     */
    public function claim(int $firmId, int $limit = 1): Collection
    {
        $lockToken = (string) Str::uuid();
        $staleLockMinutes = (int) config('integrations.outbox.stale_lock_minutes', self::DEFAULT_STALE_LOCK_MINUTES);

        $rows = DB::select(
            'WITH candidate AS ('.
            '  SELECT id FROM integration_outbox_events '.
            '  WHERE firm_id = ? AND ('.
            '    (status = ? AND next_attempt_at <= statement_timestamp()) '.
            "    OR (status = ? AND locked_at <= statement_timestamp() - (? || ' minutes')::interval)".
            '  ) '.
            '  ORDER BY next_attempt_at ASC, id ASC LIMIT ? '.
            '  FOR UPDATE SKIP LOCKED'.
            ') '.
            'UPDATE integration_outbox_events '.
            'SET status = ?, lock_token = ?, locked_at = to_timestamp(ceil(extract(epoch from statement_timestamp()))), attempts = attempts + 1 '.
            'WHERE id IN (SELECT id FROM candidate) '.
            'RETURNING *',
            [
                $firmId,
                'pending',
                'processing', $staleLockMinutes,
                $limit,
                'processing', $lockToken,
            ]
        );

        return IntegrationOutboxEvent::hydrate(array_map(fn ($row) => (array) $row, $rows));
    }

    /**
     * Completion guard: WHERE id = ? AND lock_token = ? AND status =
     * 'processing' — both clauses independently load-bearing (see
     * frozen-design-post-review.md §7). Returns null when the guard
     * matched zero rows (stale token, already-terminal row, or unknown
     * id) — the caller must check for null, never assume success.
     */
    public function complete(int $id, string $lockToken): ?IntegrationOutboxEvent
    {
        $row = DB::selectOne(
            'UPDATE integration_outbox_events '.
            "SET status = 'completed', completed_at = now() ".
            "WHERE id = ? AND lock_token = ? AND status = 'processing' ".
            'RETURNING *',
            [$id, $lockToken]
        );

        return $row === null ? null : IntegrationOutboxEvent::hydrate([(array) $row])->first();
    }

    /**
     * Voluntary release (no failure) — re-enters the claimable pool
     * immediately. lock_token IS nulled here (unlike complete()) — a
     * released row's null lock_token unambiguously reads "not currently
     * claimed". Does not decrement attempts (the claim episode already
     * happened and already cost an attempt regardless of why it ended
     * without success).
     */
    public function release(int $id, string $lockToken): ?IntegrationOutboxEvent
    {
        $row = DB::selectOne(
            'UPDATE integration_outbox_events '.
            "SET status = 'pending', lock_token = NULL, locked_at = NULL ".
            "WHERE id = ? AND lock_token = ? AND status = 'processing' ".
            'RETURNING *',
            [$id, $lockToken]
        );

        return $row === null ? null : IntegrationOutboxEvent::hydrate([(array) $row])->first();
    }

    /**
     * Failure handling — chooses retry (attempts remain) vs. dead-
     * letter (attempts exhausted, per WebhookRetryPolicyService's
     * shared, reused-not-reimplemented backoff calculator — see class
     * docblock; agent-6d §7) as ONE guarded UPDATE either way, keyed by
     * the SAME lock_token/status guard as complete()/release(). Never
     * rethrows the caller's original exception detail — $sanitizedError
     * must already be a short, non-secret reason string (mirrors
     * App\Integrations\Exceptions\SanitizedProviderHttpException's own
     * category-only discipline).
     *
     * CHECKPOINT 8 addition (agent-8h-architecture-security-review.md
     * §1 item 2 / §2 item 2): optional fourth parameter $category,
     * threaded straight into both isExhausted()/nextAttemptDelaySeconds()
     * as ['max_attempts' => $maxAttempts, 'category' => $category].
     * $category = null (the default) reproduces today's exact
     * attempt-count-only behavior byte-for-byte — no existing caller or
     * test passes a fourth argument. The generic outbox dispatcher is
     * responsible for classifying every caught handler exception into
     * one of the nine closed retry categories
     * (App\Services\WebhookRetryPolicyService::TERMINAL_CATEGORIES plus
     * the retryable ones) and passing that string through here — this
     * class itself makes no HTTP/provider decisions, consistent with its
     * own "primitives only" docblock.
     */
    public function fail(int $id, string $lockToken, string $sanitizedError, ?string $category = null): ?IntegrationOutboxEvent
    {
        $current = DB::table('integration_outbox_events')
            ->where('id', $id)
            ->where('lock_token', $lockToken)
            ->where('status', 'processing')
            ->first();

        if ($current === null) {
            return null;
        }

        $attempts = (int) $current->attempts;
        $maxAttempts = (int) $current->max_attempts;

        if ($this->retryPolicy->isExhausted($attempts, ['max_attempts' => $maxAttempts, 'category' => $category])) {
            $row = DB::selectOne(
                'UPDATE integration_outbox_events '.
                "SET status = 'dead_lettered', dead_lettered_at = now(), last_error = ? ".
                "WHERE id = ? AND lock_token = ? AND status = 'processing' ".
                'RETURNING *',
                [$sanitizedError, $id, $lockToken]
            );

            return $row === null ? null : IntegrationOutboxEvent::hydrate([(array) $row])->first();
        }

        $delaySeconds = $this->retryPolicy->nextAttemptDelaySeconds($attempts, ['max_attempts' => $maxAttempts, 'category' => $category]);

        $row = DB::selectOne(
            'UPDATE integration_outbox_events '.
            "SET status = 'pending', lock_token = NULL, locked_at = NULL, ".
            'next_attempt_at = to_timestamp(ceil(extract(epoch from statement_timestamp()))) + (? * interval \'1 second\'), last_error = ? '.
            "WHERE id = ? AND lock_token = ? AND status = 'processing' ".
            'RETURNING *',
            [$delaySeconds, $sanitizedError, $id, $lockToken]
        );

        return $row === null ? null : IntegrationOutboxEvent::hydrate([(array) $row])->first();
    }

    /**
     * Deliberately restricted to status = 'pending' only — never
     * callable against a 'processing' row (agent-6d §8): a processing
     * row may already have in-flight external side effects, and
     * cancelling it out from under an active claim would create an
     * unresolvable race between the claiming worker and this call.
     */
    public function cancel(int $id): ?IntegrationOutboxEvent
    {
        $row = DB::selectOne(
            'UPDATE integration_outbox_events '.
            "SET status = 'cancelled', cancelled_at = now() ".
            "WHERE id = ? AND status = 'pending' ".
            'RETURNING *',
            [$id]
        );

        return $row === null ? null : IntegrationOutboxEvent::hydrate([(array) $row])->first();
    }
}
