<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * ProviderOperationAttemptState — lifecycle state of a
 * `provider_operation_attempts` row (Checkpoint 8.2 §A4/§A5). Plain
 * string column, no DB-level enum type — mirrors
 * SyncRunStatus/ConnectionStatus's established convention exactly.
 *
 * THIS ENUM IS THE AT-MOST-ONCE PROVIDER-CALL GATE. It is deliberately
 * NOT a second billing ledger: `provider_billable_call_reservations`
 * and `integration_usage_records` remain the authoritative billing
 * rows on the ordinary application connection (Checkpoint 8.2 §A9).
 * This enum answers exactly one question — "may this logical operation
 * send a provider request right now?" — and preserves the evidence
 * needed to answer it correctly after a crash or rollback.
 *
 * WHY A SEPARATE STATE MACHINE FROM ProviderBillableCallReservation:
 * Checkpoint 8.1 tried to make the reservation row itself durable by
 * moving that FK-bearing table onto an independent connection. That
 * deadlocked: `App\Jobs\PullSyncJob` holds `lockForUpdate()` on the
 * `firm_integrations` row across the provider call, so a cross-session
 * INSERT whose composite FK references that row must take FOR KEY
 * SHARE on it and waits forever (proven live via pg_stat_activity /
 * pg_locks). Hence this table carries NO foreign keys at all — see the
 * migration's own docblock.
 *
 * TRANSITIONS (the only legal ones):
 *
 *   (new row)         -> Claimed
 *   Claimed           -> AttemptStarted        (durably, BEFORE the send)
 *   Claimed           -> RetryAllowed          (lease expired, provably never sent)
 *   AttemptStarted    -> ProviderSucceeded
 *   AttemptStarted    -> ProviderRejected
 *   AttemptStarted    -> ProviderOutcomeUncertain
 *   ProviderSucceeded -> LocalProcessingComplete
 *   ProviderSucceeded -> LocalProcessingFailed  (provider evidence PRESERVED)
 *   ProviderRejected  -> RetryAllowed           (provably no billable work happened)
 *   LocalProcessingFailed -> LocalProcessingComplete (resumed from durable state)
 *   LocalProcessingFailed -> ReconciliationRequired  (cannot safely resume)
 *   ProviderOutcomeUncertain -> ReconciliationRequired
 *   ReconciliationRequired -> (only via an explicit, audited operator resolution)
 *
 * States from which a retry may NEVER automatically re-send
 * (Checkpoint 8.2 invariant 4): ProviderSucceeded,
 * ProviderOutcomeUncertain, LocalProcessingFailed,
 * LocalProcessingComplete, ReconciliationRequired.
 */
enum ProviderOperationAttemptState: string
{
    /**
     * A worker durably owns this logical operation and holds an
     * unexpired lease, but the request has NOT left the process. This
     * is the only state that may progress to a real send.
     */
    case Claimed = 'claimed';

    /**
     * The outbound request is about to leave, or has left, this
     * process. Recorded durably BEFORE the send so a crash in the
     * network window is never mistaken for "never sent."
     */
    case AttemptStarted = 'attempt_started';

    /** The provider definitely accepted and completed the work. */
    case ProviderSucceeded = 'provider_succeeded';

    /**
     * The provider definitely refused BEFORE doing billable work
     * (auth failure, validation failure, rate limit, 5xx rejection).
     * Positive knowledge that no charge occurred, so a fresh attempt
     * is safe.
     */
    case ProviderRejected = 'provider_rejected';

    /**
     * Timeout / connection reset / unknown error — we genuinely cannot
     * tell whether the provider did the work. NEVER auto-retried.
     */
    case ProviderOutcomeUncertain = 'provider_outcome_uncertain';

    /**
     * The provider succeeded but this side's own post-processing threw
     * (malformed response, local database failure, crash). The
     * provider-success evidence survives; a retry resumes local work
     * WITHOUT calling the provider again.
     */
    case LocalProcessingFailed = 'local_processing_failed';

    /** Provider succeeded AND local post-processing finished. Terminal, idempotent exit. */
    case LocalProcessingComplete = 'local_processing_complete';

    /**
     * Explicitly re-attemptable: either a definite pre-send failure, or
     * a lease that expired while still provably un-sent. Every
     * transition into this state records its own machine reason in the
     * row's `state_reason` column, and every takeover of an abandoned
     * lease bumps `reclaim_count` — so a retry is never invisible.
     */
    case RetryAllowed = 'retry_allowed';

    /**
     * Requires a human/operator decision. Reached from an uncertain
     * provider outcome, or from a local failure that cannot safely
     * resume. Never auto-retried, never silently resolved.
     */
    case ReconciliationRequired = 'reconciliation_required';

    /**
     * True when a retry is forbidden from automatically issuing another
     * provider request for this logical operation — the single
     * predicate Checkpoint 8.2 invariant 4 is expressed as.
     */
    public function forbidsAutomaticResend(): bool
    {
        return in_array($this, [
            self::ProviderSucceeded,
            self::ProviderOutcomeUncertain,
            self::LocalProcessingFailed,
            self::LocalProcessingComplete,
            self::ReconciliationRequired,
        ], true);
    }

    /**
     * True when the provider is known to have completed the work, so
     * local post-processing may safely resume from durable evidence.
     */
    public function providerWorkIsDone(): bool
    {
        return in_array($this, [
            self::ProviderSucceeded,
            self::LocalProcessingFailed,
            self::LocalProcessingComplete,
        ], true);
    }

    /** Terminal for automated purposes — no further worker action. */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::LocalProcessingComplete,
            self::ReconciliationRequired,
        ], true);
    }
}
