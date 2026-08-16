<?php

declare(strict_types=1);

namespace App\Exceptions\Pay;

use RuntimeException;

/**
 * RefundCapacityExceededException — FirmsVault Pay Gate A2 (v1.4 §26).
 * The requested refund would break the invariant
 *
 *     successful refunds + active reservations <= captured amount
 *
 * Raised from inside the FOR UPDATE-protected reservation transaction,
 * so it is a real, serialized refusal rather than an optimistic guess.
 */
class RefundCapacityExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $paymentAttemptId,
        public readonly int $capturedCents,
        public readonly int $alreadyHeldCents,
        public readonly int $requestedCents,
    ) {
        parent::__construct(
            'Refund refused for payment attempt ['.$paymentAttemptId.']: captured '.$capturedCents
            .' cents, '.$alreadyHeldCents.' cents already held by successful or active refunds, '
            .'so '.$requestedCents.' cents cannot be reserved (only '
            .max(0, $capturedCents - $alreadyHeldCents).' cents remain).'
        );
    }
}
