<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * ProviderOperationOwnershipLostException — thrown by
 * `App\Integrations\Billing\ProviderOperationAttemptService` when a
 * compare-and-set transition finds no row matching BOTH the expected
 * owner token and the expected current state (Checkpoint 8.2 §A4).
 *
 * That means this worker is no longer the owner of the logical
 * operation: its lease expired and another worker took over, or the row
 * was moved to a state this transition is not legal from. Failing closed
 * here is deliberate — the alternative is a stale worker overwriting the
 * live owner's recorded provider outcome, which is exactly how a
 * duplicate charge becomes invisible.
 *
 * This is never a provider error and must never be translated into a
 * retry that re-sends the request.
 */
final class ProviderOperationOwnershipLostException extends RuntimeException
{
    public function __construct(
        public readonly string $logicalOperationKey,
        public readonly string $attemptedTransition,
    ) {
        parent::__construct(
            'Ownership of provider operation "'.$logicalOperationKey.'" was lost before the "'
                .$attemptedTransition.'" transition could be applied; another worker now owns it, '
                .'or the transition is not legal from the row\'s current state.'
        );
    }
}
