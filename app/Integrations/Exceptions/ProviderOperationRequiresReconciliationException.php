<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * ProviderOperationRequiresReconciliationException — thrown by
 * `App\Integrations\Billing\ProviderBillableCallPipeline` when the
 * durable gate says this logical operation's true outcome is unknown, or
 * that a previous failure cannot be safely resumed, so no automated path
 * may touch it again (Checkpoint 8.2 §A5).
 *
 * Why this is an exception rather than a quiet "served from existing
 * evidence" return: the operation did NOT succeed, and there is no
 * outcome to serve. Returning normally would let a caller record a
 * success that never happened. Throwing surfaces the operation in
 * `failed_jobs` and the audit trail, where an operator can act on it.
 *
 * Callers must NOT convert this into a retry that re-sends the request —
 * the gate will refuse it again, by design. The only exits are an
 * operator resolution
 * (`ProviderOperationAttemptService::resolveReconciliation()`) or a
 * genuinely NEW logical operation (a fresh sync run, a later renewal
 * cycle), which by construction carries a different
 * `logical_operation_key`.
 */
final class ProviderOperationRequiresReconciliationException extends RuntimeException
{
    public function __construct(
        public readonly string $logicalOperationKey,
        public readonly string $attemptState,
        public readonly ?string $reconciliationReason = null,
    ) {
        parent::__construct(
            'Provider operation "'.$logicalOperationKey.'" is in state "'.$attemptState
                .'" and requires reconciliation before it can be attempted again'
                .($reconciliationReason !== null ? ' (reason: '.$reconciliationReason.')' : '').'.'
        );
    }
}
