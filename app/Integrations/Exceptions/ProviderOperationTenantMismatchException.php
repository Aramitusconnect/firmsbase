<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * ProviderOperationTenantMismatchException — thrown by
 * `App\Integrations\Billing\ProviderOperationAttemptService` when a
 * logical operation key that already exists in the durable gate is
 * presented with a DIFFERENT firm id than the row it resolves to
 * (Checkpoint 8.2 §A4).
 *
 * Logical operation keys are always derived from firm-scoped inputs
 * (connection id, sync run id, subscription id), so a collision across
 * two firms cannot happen in correct code. It would mean either a
 * key-construction defect or a deliberate attempt to reuse another
 * tenant's gate row — and because this table intentionally carries no
 * foreign keys, the database itself cannot catch it. This exception IS
 * the compensating control: the operation is refused outright rather
 * than being allowed to read, resume, or overwrite another firm's
 * provider evidence.
 */
final class ProviderOperationTenantMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $logicalOperationKey,
        public readonly int $expectedFirmId,
        public readonly int $actualFirmId,
    ) {
        parent::__construct(
            'Logical provider operation "'.$logicalOperationKey.'" is recorded against firm '
                .$actualFirmId.' but was presented for firm '.$expectedFirmId
                .'; refusing to touch another tenant\'s provider operation evidence.'
        );
    }
}
