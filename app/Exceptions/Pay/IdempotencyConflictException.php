<?php

declare(strict_types=1);

namespace App\Exceptions\Pay;

use RuntimeException;

/**
 * IdempotencyConflictException — FirmsVault Pay Gate A2 (v1.4 §13).
 * Raised when a durable idempotency key is reused with a DIFFERENT
 * canonical payload, i.e. the caller is asking for a different economic
 * instruction under an identity that already means something else.
 *
 * The contract this enforces:
 *   same key + same payload      -> the SAME logical command (no-op reuse)
 *   same key + different payload -> THIS exception, NO provider execution
 *
 * Never carries payload contents — only the two hashes — so an audit
 * record of the conflict can never leak an economic payload.
 */
class IdempotencyConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $existingPayloadHash,
        public readonly string $incomingPayloadHash,
    ) {
        parent::__construct(
            'Idempotency conflict for key ['.$idempotencyKey.']: an existing provider command carries '
            .'payload hash '.substr($existingPayloadHash, 0, 12).'… but this request carries '
            .substr($incomingPayloadHash, 0, 12).'…. Refusing to execute; the same key may never mean '
            .'two different economic instructions.'
        );
    }
}
