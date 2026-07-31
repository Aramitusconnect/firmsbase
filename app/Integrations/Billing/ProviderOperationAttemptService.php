<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use App\Integrations\Enums\ProviderOperationAttemptState;
use App\Integrations\Enums\ProviderOperationClaimDecision;
use App\Integrations\Exceptions\ProviderOperationOwnershipLostException;
use App\Integrations\Exceptions\ProviderOperationTenantMismatchException;
use App\Integrations\Models\ProviderOperationAttempt;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ProviderOperationAttemptService — the sole reader and sole writer of
 * `provider_operation_attempts`, the durable at-most-once gate for
 * outbound provider calls (Checkpoint 8.2 §A4).
 *
 * WHAT PROBLEM THIS SOLVES. Before this service, "we already sent this
 * request" lived only inside the caller's own database transaction. When
 * that transaction rolled back — a post-call exception, a crash, a
 * deploy killing the worker — the evidence vanished, and the retry
 * re-sent a request the provider had already billed. The original defect
 * (Checkpoint 8, C3) was exactly that.
 *
 * WHY A SEPARATE CONNECTION. Because the caller's transaction is
 * precisely what must not be able to erase this evidence, every write
 * here happens on `self::DURABLE_CONNECTION` — a structurally identical
 * second connection to the same physical database
 * (`config/database.php`), i.e. a genuinely separate database session.
 * Postgres transactions are all-or-nothing per session, so there is no
 * SAVEPOINT-based alternative; this is the same established mechanism
 * `App\Services\TimelineEventRecorder::recordOnIndependentConnection()`
 * uses for audit rows that must outlive a rollback.
 *
 * THREE PROPERTIES THAT MAKE THAT SAFE — Checkpoint 8.1 got each of
 * these wrong and was rejected for it:
 *
 *   1. NO FOREIGN KEYS on the target table. A cross-session INSERT whose
 *      FK references a row the caller holds FOR UPDATE must acquire FOR
 *      KEY SHARE and waits for a transaction that cannot commit until
 *      the caller finishes — a real production deadlock, proven live via
 *      pg_stat_activity/pg_locks against `PullSyncJob`'s
 *      `lockForUpdate()` on `firm_integrations`. Hence the scalar
 *      `firm_id`/`firm_integration_id` columns and this service's
 *      explicit, mandatory `firm_id` filtering.
 *
 *   2. NO TRANSACTIONS ON THIS CONNECTION. Every operation below is a
 *      SINGLE autocommitted statement — an INSERT, or an UPDATE whose
 *      WHERE clause is the compare-and-set. Nothing here can leave an
 *      open transaction, hold a lock across a network call, or leave the
 *      session in a state a later reuse would inherit. Atomicity comes
 *      from the statement itself plus the `logical_operation_key` unique
 *      index, not from a transaction.
 *
 *   3. NO SESSION SETTINGS. Unlike TimelineEventRecorder, this service
 *      never pushes `app.current_firm_id`, because the target table is
 *      registered Global/EXEMPT in
 *      `App\Services\RowLevelSecurityCoverageMappingService` and carries
 *      no RLS policy. It therefore cannot leak tenant context onto a
 *      pooled connection — and, critically, the pre-claim read works
 *      before any firm context exists, which is what lets the gate be
 *      consulted at the very start of an operation.
 *
 * THE AT-MOST-ONCE RULE, CONCRETELY. `claim()` is the only way to obtain
 * permission to send, and it grants that permission
 * (`ProviderOperationClaimDecision::Proceed`) only when the row proves
 * the request has never left the process. `markAttemptStarted()` is
 * called BEFORE the send and its compare-and-set requires
 * `send_count = 0`, so a second send for the same logical operation is
 * structurally impossible rather than merely unlikely. Once
 * `attempt_started` is recorded, no code path in this service can ever
 * return `Proceed` for that key again: an abandoned attempt becomes
 * `provider_outcome_uncertain` and then a reconciliation, never a retry.
 */
class ProviderOperationAttemptService
{
    private const DURABLE_CONNECTION = 'pgsql_audit';

    private const TABLE = 'provider_operation_attempts';

    /**
     * How long a claim owns a logical operation before another worker
     * may consider it abandoned. Deliberately generous relative to
     * provider HTTP timeouts: a lease that expires while the owner is
     * still waiting on the provider does NOT license a resend (the state
     * machine forbids it), it only permits reclassification to
     * "uncertain" — so the cost of a short lease is unnecessary
     * reconciliation work, not a duplicate charge.
     */
    public const DEFAULT_LEASE_SECONDS = 300;

    /**
     * Obtain (or refuse) permission to perform ONE logical provider
     * operation. Committed immediately on the durable connection, before
     * the caller sends anything.
     *
     * Never throws for ordinary contention — the caller branches on the
     * returned decision. Throws only when continuing would be unsafe:
     * a tenant mismatch on an existing key.
     */
    public function claim(
        string $logicalOperationKey,
        string $providerKey,
        int $firmId,
        ?int $firmIntegrationId,
        string $operationType,
        int $operationVersion = 1,
        ?int $leaseSeconds = null,
    ): ProviderOperationClaim {
        $leaseSeconds ??= self::DEFAULT_LEASE_SECONDS;
        $ownerToken = $this->newOwnerToken();

        $fresh = $this->insertFreshClaim(
            logicalOperationKey: $logicalOperationKey,
            providerKey: $providerKey,
            firmId: $firmId,
            firmIntegrationId: $firmIntegrationId,
            operationType: $operationType,
            operationVersion: $operationVersion,
            ownerToken: $ownerToken,
            leaseSeconds: $leaseSeconds,
        );

        if ($fresh !== null) {
            return new ProviderOperationClaim(
                ProviderOperationClaimDecision::Proceed,
                $fresh,
                $ownerToken,
            );
        }

        // The unique index rejected the insert, so a row for this
        // logical operation already exists. Everything from here is a
        // decision about somebody else's (or our own earlier) attempt.
        $existing = $this->findByLogicalKey($logicalOperationKey);

        if ($existing === null) {
            // Vanishingly unlikely: the row was hard-deleted between the
            // failed insert and this read. Fail closed rather than
            // looping — a caller that genuinely has no gate row can call
            // claim() again.
            return new ProviderOperationClaim(
                ProviderOperationClaimDecision::InFlightElsewhere,
                $this->newDetachedRowFor($logicalOperationKey, $providerKey, $firmId, $firmIntegrationId, $operationType),
                null,
            );
        }

        $this->assertBelongsToFirm($existing, $firmId);

        return $this->decideForExistingAttempt($existing, $leaseSeconds);
    }

    /**
     * Record durably, BEFORE the request leaves this process, that a
     * provider call is being made. The compare-and-set requires the
     * claimed state, this worker's owner token, and `send_count = 0`, so
     * this transition can succeed at most once per logical operation for
     * the lifetime of the row.
     *
     * @throws ProviderOperationOwnershipLostException when this worker no longer owns the operation, or the operation has already been sent
     */
    public function markAttemptStarted(
        ProviderOperationAttempt $attempt,
        string $ownerToken,
        ?string $providerRequestReference = null,
    ): ProviderOperationAttempt {
        $affected = $this->baseQuery($attempt)
            ->where('owner_token', $ownerToken)
            ->where('attempt_state', ProviderOperationAttemptState::Claimed->value)
            ->where('send_count', 0)
            ->update([
                'attempt_state' => ProviderOperationAttemptState::AttemptStarted->value,
                'state_reason' => null,
                'send_count' => DB::raw('send_count + 1'),
                'total_send_count' => DB::raw('total_send_count + 1'),
                'provider_request_reference' => $providerRequestReference,
                'provider_started_at' => now(),
                'updated_at' => now(),
            ]);

        return $this->assertApplied($attempt, $affected, 'attempt_started');
    }

    /**
     * Give back a claim that was never used, because a later check in the
     * caller's own pipeline decided not to send after all.
     *
     * Safe by construction: the compare-and-set requires the `claimed`
     * state, this worker's owner token AND `send_count = 0`, so this can
     * only ever release an operation that provably never left the process.
     * It moves the row to `retry_allowed` with the caller's reason rather
     * than deleting it, so the aborted attempt stays visible.
     *
     * Returns true when the release applied. Deliberately does NOT throw:
     * a caller reaching this point is already abandoning the operation, and
     * failing there would convert a clean no-op into an error.
     */
    public function releaseUnusedClaim(
        ProviderOperationAttempt $attempt,
        string $ownerToken,
        string $reason,
    ): bool {
        $affected = $this->baseQuery($attempt)
            ->where('owner_token', $ownerToken)
            ->where('attempt_state', ProviderOperationAttemptState::Claimed->value)
            ->where('send_count', 0)
            ->update([
                'attempt_state' => ProviderOperationAttemptState::RetryAllowed->value,
                'state_reason' => $reason,
                'owner_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        return $affected === 1;
    }

    /**
     * The provider definitely completed the work. Recorded before any
     * local post-processing runs, so a later local failure can never be
     * mistaken for "the provider never ran".
     *
     * @param  string|null  $redactedResultMetadata  Safe, already-redacted summary only — never a token, never a raw provider payload (§A8).
     */
    public function recordProviderSucceeded(
        ProviderOperationAttempt $attempt,
        string $ownerToken,
        ?string $providerOutcome = null,
        ?string $billableClassification = null,
        ?string $redactedResultMetadata = null,
        ?string $resultChecksum = null,
    ): ProviderOperationAttempt {
        $affected = $this->baseQuery($attempt)
            ->where('owner_token', $ownerToken)
            ->where('attempt_state', ProviderOperationAttemptState::AttemptStarted->value)
            ->update([
                'attempt_state' => ProviderOperationAttemptState::ProviderSucceeded->value,
                'state_reason' => null,
                'provider_outcome' => $providerOutcome,
                'billable_classification' => $billableClassification,
                'redacted_result_metadata' => $redactedResultMetadata,
                'result_checksum' => $resultChecksum,
                'provider_completed_at' => now(),
                'updated_at' => now(),
            ]);

        return $this->assertApplied($attempt, $affected, 'provider_succeeded');
    }

    /**
     * The provider definitely refused BEFORE doing billable work. This
     * is positive knowledge that no charge occurred, and it is the only
     * post-send outcome from which a fresh attempt is ever permitted.
     */
    public function recordProviderRejected(
        ProviderOperationAttempt $attempt,
        string $ownerToken,
        string $reason,
        ?string $providerOutcome = null,
    ): ProviderOperationAttempt {
        $affected = $this->baseQuery($attempt)
            ->where('owner_token', $ownerToken)
            ->where('attempt_state', ProviderOperationAttemptState::AttemptStarted->value)
            ->update([
                'attempt_state' => ProviderOperationAttemptState::ProviderRejected->value,
                'state_reason' => $reason,
                'provider_outcome' => $providerOutcome,
                'provider_completed_at' => now(),
                // This worker is done and the operation is no longer in
                // flight, so the lease is released immediately — a
                // definite pre-billing refusal should be retryable now,
                // not only once a lease happens to lapse.
                'owner_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        return $this->assertApplied($attempt, $affected, 'provider_rejected');
    }

    /**
     * We genuinely cannot tell whether the provider did the work
     * (timeout, connection reset, unknown error). Never auto-retried;
     * escalate with `markReconciliationRequired()`.
     */
    public function recordProviderOutcomeUncertain(
        ProviderOperationAttempt $attempt,
        string $ownerToken,
        string $reason,
    ): ProviderOperationAttempt {
        $affected = $this->baseQuery($attempt)
            ->where('owner_token', $ownerToken)
            ->where('attempt_state', ProviderOperationAttemptState::AttemptStarted->value)
            ->update([
                'attempt_state' => ProviderOperationAttemptState::ProviderOutcomeUncertain->value,
                'state_reason' => $reason,
                'provider_completed_at' => now(),
                // Release ownership: nothing further may be done to this
                // row by a worker, only escalated to reconciliation.
                'owner_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        return $this->assertApplied($attempt, $affected, 'provider_outcome_uncertain');
    }

    /**
     * The provider succeeded but this side's own post-processing threw.
     * The provider-success evidence is preserved; a later retry resumes
     * local work WITHOUT calling the provider again.
     *
     * @param  string|null  $localProcessingState  A short, resumable marker (e.g. a cursor or page identifier) — never a payload.
     */
    public function markLocalProcessingFailed(
        ProviderOperationAttempt $attempt,
        string $ownerToken,
        string $reason,
        ?string $localProcessingState = null,
    ): ProviderOperationAttempt {
        $affected = $this->baseQuery($attempt)
            ->where('owner_token', $ownerToken)
            ->where('attempt_state', ProviderOperationAttemptState::ProviderSucceeded->value)
            ->update([
                'attempt_state' => ProviderOperationAttemptState::LocalProcessingFailed->value,
                'state_reason' => $reason,
                'local_processing_state' => $localProcessingState,
                // This worker is giving up, so it releases the lease
                // immediately — the retry that resumes local processing
                // should not have to wait for a lease to lapse. The
                // provider-success evidence and send_count are untouched,
                // so the resume still cannot re-send.
                'owner_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        return $this->assertApplied($attempt, $affected, 'local_processing_failed');
    }

    /**
     * Provider succeeded AND local post-processing finished. Terminal,
     * idempotent exit. Reachable both from a straight-through success
     * and from a resumed `local_processing_failed` row.
     */
    public function markLocalProcessingComplete(
        ProviderOperationAttempt $attempt,
        string $ownerToken,
        ?string $localProcessingState = null,
    ): ProviderOperationAttempt {
        $affected = $this->baseQuery($attempt)
            ->where('owner_token', $ownerToken)
            ->whereIn('attempt_state', [
                ProviderOperationAttemptState::ProviderSucceeded->value,
                ProviderOperationAttemptState::LocalProcessingFailed->value,
            ])
            ->update([
                'attempt_state' => ProviderOperationAttemptState::LocalProcessingComplete->value,
                'state_reason' => null,
                'local_processing_state' => $localProcessingState,
                'local_processing_completed_at' => now(),
                'finalized_at' => now(),
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        return $this->assertApplied($attempt, $affected, 'local_processing_complete');
    }

    /**
     * Escalate to a human. Deliberately does NOT require an owner token:
     * the whole point of this state is that the owning worker may be
     * gone, and a sweeper or a later claim must be able to escalate an
     * abandoned uncertain/failed row. It is still a compare-and-set on
     * the source state, so it can never overwrite a completed operation.
     */
    public function markReconciliationRequired(
        ProviderOperationAttempt $attempt,
        string $reason,
    ): ProviderOperationAttempt {
        $affected = $this->baseQuery($attempt)
            ->whereIn('attempt_state', [
                ProviderOperationAttemptState::ProviderOutcomeUncertain->value,
                ProviderOperationAttemptState::LocalProcessingFailed->value,
            ])
            ->update([
                'attempt_state' => ProviderOperationAttemptState::ReconciliationRequired->value,
                'state_reason' => null,
                'reconciliation_reason' => $reason,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        return $this->assertApplied($attempt, $affected, 'reconciliation_required');
    }

    /**
     * The one authorized exit from `reconciliation_required`, and the
     * only method here that a platform operator's action reaches.
     *
     * Two resolutions are legal, and the choice is a factual claim the
     * operator is making about the provider side:
     *
     *   - `LocalProcessingComplete` — the operator established that the
     *     provider DID the work and this side is settled. Nothing is
     *     ever re-sent.
     *   - `RetryAllowed` — the operator established that the provider
     *     did NOT do the work, so a fresh attempt is safe. This is the
     *     ONLY way a row that reached `attempt_started` can ever become
     *     sendable again; no automated path can produce it.
     *
     * @param  int|null  $resolvedByUserId  Recorded in `state_reason` for attribution; the authoritative audit trail is the caller's own audit event.
     *
     * @throws \InvalidArgumentException when asked for a resolution that is not one of the two legal outcomes
     */
    public function resolveReconciliation(
        ProviderOperationAttempt $attempt,
        ProviderOperationAttemptState $resolution,
        string $reason,
        ?int $resolvedByUserId = null,
    ): ProviderOperationAttempt {
        $legal = [
            ProviderOperationAttemptState::LocalProcessingComplete,
            ProviderOperationAttemptState::RetryAllowed,
        ];

        if (! in_array($resolution, $legal, true)) {
            throw new \InvalidArgumentException(
                'A reconciliation may only be resolved to local_processing_complete or retry_allowed, not "'
                    .$resolution->value.'".'
            );
        }

        $attribution = 'operator_resolved:'.$reason
            .($resolvedByUserId !== null ? ':user_'.$resolvedByUserId : '');

        $updates = [
            'attempt_state' => $resolution->value,
            'state_reason' => $attribution,
            'lease_expires_at' => null,
            'owner_token' => null,
            'updated_at' => now(),
        ];

        if ($resolution === ProviderOperationAttemptState::LocalProcessingComplete) {
            $updates['local_processing_completed_at'] = now();
            $updates['finalized_at'] = now();
        }

        $affected = $this->baseQuery($attempt)
            ->where('attempt_state', ProviderOperationAttemptState::ReconciliationRequired->value)
            ->update($updates);

        return $this->assertApplied($attempt, $affected, 'reconciliation_resolved');
    }

    /**
     * Bounded sweep of abandoned leases. Deliberately performs only the
     * two transitions that are provably safe without knowing anything
     * the row does not already record:
     *
     *   - `claimed` + expired lease  -> `retry_allowed`
     *     (the state itself proves the request never left the process)
     *   - `attempt_started` + expired lease -> `provider_outcome_uncertain`
     *     (the request may have been delivered; NEVER retryable)
     *
     * Escalation from uncertain to reconciliation is left to an explicit
     * decision rather than folded in here, so an operator always sees
     * "uncertain because the worker vanished" as its own recorded step.
     *
     * @return array{retry_allowed: int, outcome_uncertain: int}
     */
    public function sweepExpiredLeases(int $limit = 100): array
    {
        $now = now();

        $retryAllowed = $this->sweepBatch(
            fromState: ProviderOperationAttemptState::Claimed,
            toState: ProviderOperationAttemptState::RetryAllowed,
            reason: 'lease_expired_before_send',
            limit: $limit,
            now: $now,
        );

        $uncertain = $this->sweepBatch(
            fromState: ProviderOperationAttemptState::AttemptStarted,
            toState: ProviderOperationAttemptState::ProviderOutcomeUncertain,
            reason: 'lease_expired_after_attempt_started',
            limit: $limit,
            now: $now,
        );

        return ['retry_allowed' => $retryAllowed, 'outcome_uncertain' => $uncertain];
    }

    /**
     * Read one attempt by its logical operation key. Public so callers
     * can inspect durable evidence (e.g. to resume local processing)
     * without duplicating the connection choice.
     *
     * `$firmId` is optional ONLY because `claim()` must be able to read
     * a row before it knows whether the key belongs to the requesting
     * firm — that read is immediately followed by
     * `assertBelongsToFirm()`. Every other caller passes it.
     */
    public function findByLogicalKey(string $logicalOperationKey, ?int $firmId = null): ?ProviderOperationAttempt
    {
        $query = ProviderOperationAttempt::on(self::DURABLE_CONNECTION)
            ->where('logical_operation_key', $logicalOperationKey);

        if ($firmId !== null) {
            $query->where('firm_id', $firmId);
        }

        return $query->first();
    }

    /** Re-read an attempt row from the durable connection. */
    public function refresh(ProviderOperationAttempt $attempt): ?ProviderOperationAttempt
    {
        return ProviderOperationAttempt::on(self::DURABLE_CONNECTION)
            ->where('firm_id', $attempt->firm_id)
            ->find($attempt->id);
    }

    /**
     * CHECKPOINT 8.2 (§A-reconciliation) addition. The base query for the
     * Platform Admin reconciliation surface — every row a human must act
     * on before it can ever be automatically retried or resumed. Public
     * so a Filament page can layer its own filters/pagination without
     * duplicating the connection choice or the state predicate; this
     * class remains the sole reader.
     */
    public function queryRequiringReconciliation(): EloquentBuilder
    {
        return ProviderOperationAttempt::on(self::DURABLE_CONNECTION)
            ->where('attempt_state', ProviderOperationAttemptState::ReconciliationRequired->value);
    }

    /**
     * CHECKPOINT 8.2 (§A-reconciliation) addition. Read one attempt by
     * its numeric id, scoped to a specific firm — the lookup a Filament
     * action uses, since Filament passes back only the array record's
     * own cached fields (including `id` and `firm_id`), never a live
     * model. Never trusts anything about the record beyond the id itself
     * for a mutation; the firm_id match here is a defense-in-depth
     * sanity check on top of that.
     */
    public function findByIdForFirm(int $id, int $firmId): ?ProviderOperationAttempt
    {
        return ProviderOperationAttempt::on(self::DURABLE_CONNECTION)
            ->where('id', $id)
            ->where('firm_id', $firmId)
            ->first();
    }

    // ------------------------------------------------------------------
    // internals
    // ------------------------------------------------------------------

    /**
     * Attempt the fresh INSERT. Returns the created row when this worker
     * won the race, or null when the `logical_operation_key` unique
     * index rejected it.
     *
     * Runs OUTSIDE any transaction on purpose: in Postgres a constraint
     * violation aborts the surrounding transaction, so an insert-then-
     * select-on-conflict pattern inside a transaction would fail on the
     * select. As a single autocommitted statement, the violation is
     * caught here and the follow-up read succeeds normally.
     */
    private function insertFreshClaim(
        string $logicalOperationKey,
        string $providerKey,
        int $firmId,
        ?int $firmIntegrationId,
        string $operationType,
        int $operationVersion,
        string $ownerToken,
        int $leaseSeconds,
    ): ?ProviderOperationAttempt {
        $attempt = new ProviderOperationAttempt;
        $attempt->setConnection(self::DURABLE_CONNECTION);
        $attempt->forceFill([
            'logical_operation_key' => $logicalOperationKey,
            'provider_key' => $providerKey,
            'firm_id' => $firmId,
            'firm_integration_id' => $firmIntegrationId,
            'operation_type' => $operationType,
            'operation_version' => $operationVersion,
            'attempt_state' => ProviderOperationAttemptState::Claimed->value,
            'owner_token' => $ownerToken,
            'lease_expires_at' => now()->addSeconds($leaseSeconds),
            'send_count' => 0,
            'reclaim_count' => 0,
        ]);

        try {
            $attempt->save();
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $attempt;
    }

    /**
     * The gate decision table for a logical operation that already has a
     * row. Every branch either denies the send or proves it never
     * happened — there is no branch that resends after
     * `attempt_started`.
     */
    private function decideForExistingAttempt(
        ProviderOperationAttempt $existing,
        int $leaseSeconds,
    ): ProviderOperationClaim {
        $state = $existing->attempt_state;

        // Already settled end to end — a duplicate delivery of the same
        // logical operation. Idempotent no-op.
        if ($state === ProviderOperationAttemptState::LocalProcessingComplete) {
            return new ProviderOperationClaim(ProviderOperationClaimDecision::AlreadyComplete, $existing, null);
        }

        // Needs a human. Never sendable, never auto-resumable.
        if ($state === ProviderOperationAttemptState::ReconciliationRequired) {
            return new ProviderOperationClaim(ProviderOperationClaimDecision::ReconciliationRequired, $existing, null);
        }

        // The provider's outcome is unknown. Escalate rather than
        // guessing; the caller must not send.
        if ($state === ProviderOperationAttemptState::ProviderOutcomeUncertain) {
            return new ProviderOperationClaim(
                ProviderOperationClaimDecision::ReconciliationRequired,
                $existing,
                null,
            );
        }

        // The provider did the work; only local post-processing remains.
        // Resuming needs ownership, so take over the row's lease — but
        // the decision is "resume", never "send".
        if ($state === ProviderOperationAttemptState::ProviderSucceeded
            || $state === ProviderOperationAttemptState::LocalProcessingFailed) {
            $resumeToken = $this->takeOverLeaseForResume($existing, $state, $leaseSeconds);

            if ($resumeToken === null) {
                // Another worker is already resuming it.
                return new ProviderOperationClaim(ProviderOperationClaimDecision::InFlightElsewhere, $existing, null);
            }

            return new ProviderOperationClaim(
                ProviderOperationClaimDecision::ResumeLocalProcessing,
                $this->refresh($existing) ?? $existing,
                $resumeToken,
            );
        }

        // The dangerous case: a request was recorded as leaving the
        // process and no outcome was ever written.
        if ($state === ProviderOperationAttemptState::AttemptStarted) {
            if (! $existing->leaseHasExpired()) {
                // Its owner is still working. Do not send in parallel.
                return new ProviderOperationClaim(ProviderOperationClaimDecision::InFlightElsewhere, $existing, null);
            }

            // The owner vanished mid-flight. We cannot know whether the
            // provider did the work, so we fail closed: reclassify as
            // uncertain and demand reconciliation. This is the single
            // most important branch in this file — the original C3
            // defect was resending here.
            $uncertain = $this->markAbandonedAttemptUncertain($existing);

            return new ProviderOperationClaim(
                ProviderOperationClaimDecision::ReconciliationRequired,
                $uncertain,
                null,
            );
        }

        // `claimed` (with a live lease) — its owner is still working.
        if ($state === ProviderOperationAttemptState::Claimed && ! $existing->leaseHasExpired()) {
            return new ProviderOperationClaim(ProviderOperationClaimDecision::InFlightElsewhere, $existing, null);
        }

        // Everything left is provably un-sent: `claimed` with a lapsed
        // lease, `retry_allowed`, or `provider_rejected` (a definite
        // pre-billing refusal). Compete for the lease.
        $ownerToken = $this->reclaim($existing, $leaseSeconds);

        if ($ownerToken === null) {
            return new ProviderOperationClaim(ProviderOperationClaimDecision::InFlightElsewhere, $existing, null);
        }

        return new ProviderOperationClaim(
            ProviderOperationClaimDecision::Proceed,
            $this->refresh($existing) ?? $existing,
            $ownerToken,
        );
    }

    /**
     * Compare-and-set takeover of an operation that may legitimately be
     * sent. Returns the new owner token, or null when another worker won
     * the race.
     *
     * Only three source states qualify, and each qualifies for its own
     * reason:
     *
     *   - `claimed` with a lapsed lease — the state itself proves the
     *     request never left the process. Additionally guarded by
     *     `send_count = 0`.
     *   - `provider_rejected` — positive knowledge that the provider
     *     refused BEFORE doing billable work.
     *   - `retry_allowed` — either the sweeper's "expired before send"
     *     verdict, or an operator who established that the provider
     *     never received the request.
     *
     * The `send_count = 0` OR-branch is a deliberate belt-and-braces
     * guard rather than redundancy: the two named states are the ONLY
     * ones allowed to begin a new attempt generation, so if a future
     * state were ever added to the whitelist by mistake, a row that has
     * already been sent still could not be re-claimed for sending.
     *
     * Beginning a new generation resets `send_count` to 0 —
     * `total_send_count` is monotonic and preserves the full history.
     */
    private function reclaim(ProviderOperationAttempt $attempt, int $leaseSeconds): ?string
    {
        $ownerToken = $this->newOwnerToken();
        $now = now();

        $affected = $this->baseQuery($attempt)
            ->whereIn('attempt_state', [
                ProviderOperationAttemptState::Claimed->value,
                ProviderOperationAttemptState::RetryAllowed->value,
                ProviderOperationAttemptState::ProviderRejected->value,
            ])
            ->where(function (Builder $query) {
                $query->where('send_count', 0)->orWhereIn('attempt_state', [
                    ProviderOperationAttemptState::RetryAllowed->value,
                    ProviderOperationAttemptState::ProviderRejected->value,
                ]);
            })
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $now);
            })
            ->update([
                'attempt_state' => ProviderOperationAttemptState::Claimed->value,
                'state_reason' => 'reclaimed_from_'.$attempt->attempt_state->value,
                'owner_token' => $ownerToken,
                'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
                'send_count' => 0,
                'reclaim_count' => DB::raw('reclaim_count + 1'),
                'updated_at' => $now,
            ]);

        return $affected === 1 ? $ownerToken : null;
    }

    /**
     * Take over the lease on a row whose provider work is already done,
     * so local post-processing can be resumed under a valid owner token.
     * Never changes `attempt_state` and never touches `send_count` — a
     * resume is not an attempt.
     */
    private function takeOverLeaseForResume(
        ProviderOperationAttempt $attempt,
        ProviderOperationAttemptState $expectedState,
        int $leaseSeconds,
    ): ?string {
        $ownerToken = $this->newOwnerToken();
        $now = now();

        $affected = $this->baseQuery($attempt)
            ->where('attempt_state', $expectedState->value)
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $now);
            })
            ->update([
                'owner_token' => $ownerToken,
                'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
                'updated_at' => $now,
            ]);

        return $affected === 1 ? $ownerToken : null;
    }

    /**
     * Reclassify an abandoned in-flight attempt as uncertain. No owner
     * token is required — the owner is by definition gone — but the
     * compare-and-set still pins both the source state and the expired
     * lease, so a worker that comes back to life cannot be overwritten
     * mid-flight.
     */
    private function markAbandonedAttemptUncertain(ProviderOperationAttempt $attempt): ProviderOperationAttempt
    {
        $now = now();

        $this->baseQuery($attempt)
            ->where('attempt_state', ProviderOperationAttemptState::AttemptStarted->value)
            ->where('lease_expires_at', '<=', $now)
            ->update([
                'attempt_state' => ProviderOperationAttemptState::ProviderOutcomeUncertain->value,
                'state_reason' => 'lease_expired_after_attempt_started',
                'owner_token' => null,
                'updated_at' => $now,
            ]);

        // Whether or not this worker won that race, the row is now in a
        // non-sendable state; return what the database actually says.
        return $this->refresh($attempt) ?? $attempt;
    }

    /**
     * One bounded batch of the lease sweeper. Selects candidate ids
     * first (a plain read on this table only) and then applies a
     * compare-and-set to exactly those ids, so the sweep never holds a
     * lock while scanning and never transitions a row whose state
     * changed in between.
     */
    private function sweepBatch(
        ProviderOperationAttemptState $fromState,
        ProviderOperationAttemptState $toState,
        string $reason,
        int $limit,
        \DateTimeInterface $now,
    ): int {
        $ids = DB::connection(self::DURABLE_CONNECTION)
            ->table(self::TABLE)
            ->where('attempt_state', $fromState->value)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', $now)
            ->orderBy('lease_expires_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        return DB::connection(self::DURABLE_CONNECTION)
            ->table(self::TABLE)
            ->whereIn('id', $ids)
            ->where('attempt_state', $fromState->value)
            ->where('lease_expires_at', '<=', $now)
            ->update([
                'attempt_state' => $toState->value,
                'state_reason' => $reason,
                'owner_token' => null,
                'updated_at' => $now,
            ]);
    }

    /**
     * Every mutating statement starts here, so the scalar `firm_id`
     * filter can never be forgotten: with no foreign keys and no RLS
     * policy on this table, this predicate IS the tenant boundary.
     */
    private function baseQuery(ProviderOperationAttempt $attempt): Builder
    {
        return DB::connection(self::DURABLE_CONNECTION)
            ->table(self::TABLE)
            ->where('id', $attempt->id)
            ->where('firm_id', $attempt->firm_id);
    }

    /**
     * Turn a compare-and-set that matched nothing into a hard failure.
     * Silently continuing would let a worker that has lost its lease
     * believe it recorded an outcome it did not record.
     */
    private function assertApplied(
        ProviderOperationAttempt $attempt,
        int $affected,
        string $transition,
    ): ProviderOperationAttempt {
        if ($affected !== 1) {
            throw new ProviderOperationOwnershipLostException(
                $attempt->logical_operation_key,
                $transition,
            );
        }

        return $this->refresh($attempt) ?? $attempt;
    }

    /**
     * The compensating control for this table's missing foreign keys:
     * a logical operation key must never resolve to another firm's row.
     */
    private function assertBelongsToFirm(ProviderOperationAttempt $attempt, int $firmId): void
    {
        if ((int) $attempt->firm_id !== $firmId) {
            throw new ProviderOperationTenantMismatchException(
                $attempt->logical_operation_key,
                $firmId,
                (int) $attempt->firm_id,
            );
        }
    }

    /**
     * An unsaved, unpersisted row used only to give the caller something
     * to inspect on the "row disappeared underneath us" path. Never
     * saved, never returned with a Proceed decision.
     */
    private function newDetachedRowFor(
        string $logicalOperationKey,
        string $providerKey,
        int $firmId,
        ?int $firmIntegrationId,
        string $operationType,
    ): ProviderOperationAttempt {
        $attempt = new ProviderOperationAttempt;
        $attempt->setConnection(self::DURABLE_CONNECTION);
        $attempt->forceFill([
            'logical_operation_key' => $logicalOperationKey,
            'provider_key' => $providerKey,
            'firm_id' => $firmId,
            'firm_integration_id' => $firmIntegrationId,
            'operation_type' => $operationType,
            'attempt_state' => ProviderOperationAttemptState::RetryAllowed->value,
            'send_count' => 0,
            'reclaim_count' => 0,
        ]);

        return $attempt;
    }

    /**
     * Owner tokens are random, never derived from the logical operation
     * key — two workers racing the same key must never be able to
     * compute each other's token.
     */
    private function newOwnerToken(): string
    {
        return (string) Str::uuid7();
    }
}
