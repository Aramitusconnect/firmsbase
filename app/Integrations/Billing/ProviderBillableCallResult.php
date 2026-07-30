<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use App\Integrations\Enums\ProviderOperationAttemptState;
use App\Integrations\Models\ProviderBillableCallReservation;
use App\Integrations\Models\ProviderOperationAttempt;

/**
 * ProviderBillableCallResult — `ProviderBillableCallPipeline::execute()`'s
 * return type on success (checkpoint4-design-cost-control.md §2's
 * `execute()` signature). `$response` carries whatever the caller's own
 * `$providerCall` closure returned (a `ProviderHttpResponse`, or
 * whatever shape `OutboundProviderHttpClient::execute()` was told to
 * return) — this pipeline never unwraps or reshapes it further.
 *
 * `$reservation` is null only for a served-from-cache response (pipeline
 * step 8 short-circuit) — no reservation is ever created for a call that
 * never reached the provider.
 *
 * `$response` is null for BOTH short-circuits that never reached the
 * provider — the step 8 cache hit and the step 12b
 * served-from-existing-reservation gate (double-billing remediation).
 * Callers distinguish "null because nothing was called" from "null
 * because the provider returned null" via
 * `$outcome->servedWithoutProviderCall()`; for the reservation gate
 * specifically, `$reservation->status` carries the EXISTING recorded
 * outcome (`finalized_billable`, `finalized_uncertain`, `expired`) — no
 * fresh outcome is ever fabricated to stand in for a prior attempt's.
 *
 * `$softLimitExceeded` mirrors step 11's own
 * "proceeds, but flags" behavior — a soft-limit breach never blocks the
 * call, it only surfaces here for the caller's own audit/UI purposes.
 *
 * A failed call (any `SanitizedProviderHttpException`, including an
 * `uncertain`/non-billable outcome) is never represented by this class —
 * `execute()` finalizes and audits the reservation first, then rethrows
 * the original exception unchanged, exactly mirroring
 * `OutboundProviderHttpClient::execute()`'s own "propagates through both
 * layers intact" contract (checkpoint4-design-cost-control.md §2.1).
 */
final class ProviderBillableCallResult
{
    /**
     * `$operationAttempt` / `$operationOwnerToken` are Checkpoint 8.2 §A5
     * additions, both optional so no existing construction site changes
     * meaning. They carry the DURABLE gate row for this logical operation
     * (see `App\Integrations\Billing\ProviderOperationAttemptService`) and,
     * when this caller owns the operation, the token every subsequent
     * transition on it must present.
     *
     * The token is present in exactly two situations: a call this worker
     * performed, and a `mustResumeLocalProcessing()` short-circuit where
     * this worker took over the abandoned local work. It is null whenever
     * this worker owns nothing — a cache hit, or an already-settled
     * operation.
     */
    public function __construct(
        public readonly mixed $response,
        public readonly ?ProviderBillableCallReservation $reservation,
        public readonly ProviderNormalizedOutcome $outcome,
        public readonly bool $softLimitExceeded,
        public readonly ?ProviderOperationAttempt $operationAttempt = null,
        public readonly ?string $operationOwnerToken = null,
    ) {}

    /**
     * True when the pipeline refused to call the provider because the
     * durable gate proves the provider ALREADY did this logical
     * operation's work and only this side's local post-processing is
     * outstanding (Checkpoint 8.2 §A5).
     *
     * The caller must resume from `$operationAttempt`'s durable evidence
     * — never by calling the provider again — and must report the
     * outcome via `ProviderOperationAttemptService`, presenting
     * `$operationOwnerToken`.
     */
    public function mustResumeLocalProcessing(): bool
    {
        return $this->operationAttempt !== null
            && $this->operationOwnerToken !== null
            && $this->response === null
            && $this->operationAttempt->providerWorkIsDone()
            && $this->operationAttempt->attempt_state !== ProviderOperationAttemptState::LocalProcessingComplete;
    }

    /**
     * True when this logical operation is already settled end to end, so
     * the caller's only correct behavior is to do nothing at all.
     */
    public function isAlreadySettled(): bool
    {
        return $this->operationAttempt?->attempt_state === ProviderOperationAttemptState::LocalProcessingComplete;
    }
}
