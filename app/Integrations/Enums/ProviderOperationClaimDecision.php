<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * ProviderOperationClaimDecision — what
 * `App\Integrations\Billing\ProviderOperationAttemptService::claim()`
 * decided about ONE logical provider operation (Checkpoint 8.2 §A4/§A5).
 *
 * Exactly one case (`Proceed`) permits an outbound provider request. Any
 * other case means "do not send" — the caller must branch on this
 * decision, never on the raw attempt state, so the at-most-once rule
 * lives in one place instead of being re-derived per call site.
 */
enum ProviderOperationClaimDecision: string
{
    /**
     * This worker durably owns the operation and the request has
     * provably never left the process. The ONLY case that may send.
     */
    case Proceed = 'proceed';

    /**
     * Another worker holds an unexpired lease on this same logical
     * operation. Not an error and not a failure — the caller must back
     * off and let the owner finish. Never send in parallel.
     */
    case InFlightElsewhere = 'in_flight_elsewhere';

    /**
     * The provider already did the work; only this side's local
     * post-processing is outstanding. The caller resumes from the
     * durable evidence on the attempt row and MUST NOT call the
     * provider again.
     */
    case ResumeLocalProcessing = 'resume_local_processing';

    /**
     * The provider did the work AND local post-processing already
     * finished. A duplicate delivery of the same logical operation —
     * return the recorded outcome as an idempotent no-op.
     */
    case AlreadyComplete = 'already_complete';

    /**
     * The operation's true outcome is unknown, or a previous local
     * failure cannot be safely resumed. Requires an explicit, audited
     * operator resolution. Never auto-retried, never sent.
     */
    case ReconciliationRequired = 'reconciliation_required';

    /** True only for the one case that authorizes an outbound request. */
    public function maySendProviderRequest(): bool
    {
        return $this === self::Proceed;
    }

    /**
     * True when the caller should continue working from durable
     * evidence rather than either sending or giving up.
     */
    public function shouldResumeLocalProcessing(): bool
    {
        return $this === self::ResumeLocalProcessing;
    }
}
