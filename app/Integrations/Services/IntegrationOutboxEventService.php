<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Data\SanitizedPayloadReference;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Models\Firm;
use App\Services\TimelineEventRecorder;
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

    /**
     * Checkpoint 9 (frozen design §7, agent-9e §5.3, APPROVED "raise
     * the ceiling, never decrement"): requeue() never resets `attempts`
     * — it raises `max_attempts` by this small fixed increment so
     * `attempts < max_attempts` becomes true again without erasing the
     * lifetime attempt count.
     */
    private const REQUEUE_MAX_ATTEMPTS_INCREMENT = 3;

    public function __construct(
        private readonly WebhookRetryPolicyService $retryPolicy,
        private readonly TimelineEventRecorder $events,
        private readonly IntegrationRequeueAuditLogger $requeueAudit,
    ) {
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

            if ($row === null) {
                return null;
            }

            $deadLettered = IntegrationOutboxEvent::hydrate([(array) $row])->first();

            // Checkpoint 9 addition (frozen design §3): the dead-letter
            // branch of fail() only — never the retry branch below.
            // firm_id is already known from $current (read before the
            // guarded UPDATE above), so the Firm lookup here needs no
            // additional tenant-context wrap: this method is always
            // invoked from within an already-active runWithFirmContext()
            // call (see class docblock precedent throughout this
            // checkpoint series), and `firms` itself carries no RLS
            // predicate.
            $firm = Firm::query()->find($current->firm_id);

            if ($firm !== null) {
                $this->events->record($firm, 'integration_outbox.event_dead_lettered', $deadLettered, null, [
                    'outbox_event_id' => $deadLettered->id,
                    'firm_integration_id' => $deadLettered->firm_integration_id,
                    'event_type' => $deadLettered->event_type,
                    'attempts' => $deadLettered->attempts,
                    'max_attempts' => $deadLettered->max_attempts,
                    'category' => $category,
                ]);
            }

            return $deadLettered;
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

    /**
     * Requeue a dead-lettered outbox event (Checkpoint 9, frozen design
     * §7; agent-9e-requeue-governance.md §5/§8). A single guarded
     * UPDATE, never check-then-write — mirrors every other method in
     * this class. Guard order (agent-9e §8, verbatim): firm ownership
     * -> correct terminal status (dead_lettered) -> not yet at the
     * requeue ceiling -> not superseded by a newer event for the same
     * logical operation -> connection not disconnected -> credential
     * not revoked.
     *
     * Actor authority is checked by the CALLER before invocation
     * (`IntegrationAccessPolicyService::assertCanConfigure()`, per
     * frozen design §7/§9 — no dedicated `assertCanRetryOperation()`
     * method), never inside this guarded SQL — role authority is not a
     * row-state fact a WHERE clause can or should encode.
     * $actorFirmUserId is accepted purely as evidence to record, never
     * re-derived or re-authorized here.
     *
     * Attempt-reset design (agent-9e §5.3, APPROVED "raise the ceiling,
     * never decrement"): `attempts` is left untouched; `max_attempts` is
     * raised by `self::REQUEUE_MAX_ATTEMPTS_INCREMENT` so
     * `attempts < max_attempts` becomes true again without erasing the
     * lifetime attempt count. `last_error` is likewise left untouched —
     * it continues to hold the dead-letter reason until this row's
     * *next* genuine failure/success overwrites it (existing, accepted
     * lifecycle, not something requeue introduces).
     *
     * Idempotency of a duplicate requeue request falls out of the same
     * `AND status = 'dead_lettered'` guard used for every rejection case
     * below: the first successful requeue already transitions the row
     * away from `dead_lettered`, so a second call's guard clause
     * structurally fails to match, returns zero rows, and this method
     * returns null — exactly like every other guarded method in this
     * class when its precondition no longer holds. The audit-evidence
     * write below only fires when a row was actually returned, so a
     * losing duplicate call never produces a phantom audit entry.
     */
    public function requeue(
        int $id,
        int $firmId,
        string $reasonCode,
        ?int $actorFirmUserId = null,
    ): ?IntegrationOutboxEvent {
        $row = DB::selectOne(
            'UPDATE integration_outbox_events e '.
            "SET status = 'pending', ".
            'lock_token = NULL, '.
            'locked_at = NULL, '.
            'next_attempt_at = statement_timestamp(), '.
            'max_attempts = e.max_attempts + ?, '.
            'requeue_count = e.requeue_count + 1, '.
            'requeued_at = statement_timestamp(), '.
            'updated_at = statement_timestamp() '.
            'WHERE e.id = ? '.
            '  AND e.firm_id = ? '.
            "  AND e.status = 'dead_lettered' ".
            '  AND e.requeue_count < e.max_requeues '.
            '  AND NOT EXISTS ('.
            '    SELECT 1 FROM integration_outbox_events newer '.
            '    WHERE newer.firm_id = e.firm_id '.
            '      AND newer.firm_integration_id IS NOT DISTINCT FROM e.firm_integration_id '.
            '      AND newer.resource_type IS NOT DISTINCT FROM e.resource_type '.
            '      AND newer.resource_id IS NOT DISTINCT FROM e.resource_id '.
            '      AND newer.id <> e.id '.
            '      AND newer.created_at > e.created_at '.
            "      AND newer.status IN ('pending', 'processing', 'completed')".
            '  ) '.
            '  AND ('.
            '    e.firm_integration_id IS NULL '.
            '    OR EXISTS ('.
            '      SELECT 1 FROM firm_integrations fi '.
            "      WHERE fi.id = e.firm_integration_id AND fi.status <> 'disconnected'".
            '    )'.
            '  ) '.
            '  AND ('.
            '    e.firm_integration_id IS NULL '.
            '    OR EXISTS ('.
            '      SELECT 1 FROM integration_credentials ic '.
            "      WHERE ic.firm_integration_id = e.firm_integration_id AND ic.status = 'active'".
            '    )'.
            '  ) '.
            'RETURNING e.*',
            [self::REQUEUE_MAX_ATTEMPTS_INCREMENT, $id, $firmId]
        );

        if ($row === null) {
            return null;
        }

        $requeued = IntegrationOutboxEvent::hydrate([(array) $row])->first();

        $this->requeueAudit->record(
            IntegrationRequeueAuditLogger::EVENT_OUTBOX_EVENT_REQUEUED,
            table: 'integration_outbox_events',
            firmId: $firmId,
            id: $id,
            reasonCode: $reasonCode,
            actorFirmUserId: $actorFirmUserId,
            requeueCount: $requeued->requeue_count,
        );

        return $requeued;
    }
}
