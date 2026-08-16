<?php

declare(strict_types=1);

namespace App\Exceptions\Pay;

use RuntimeException;

/**
 * TrustExecutionDisabledException — FirmsVault Pay Gate A2 (v1.4 §19).
 * POC #1 runs with trust_execution_mode = DISABLED. There is no
 * provider execution route to trust/IOLTA, and this exception is what
 * a caller gets for attempting to build one.
 *
 * This does NOT replace the repository's existing, stronger trust
 * block: PaymentClassificationService still refuses every
 * TrustIoltaPayment classification unconditionally, and that refusal is
 * untouched by this gate. This is an additional, earlier refusal on the
 * new provider path, so trust value cannot even reach command creation.
 */
class TrustExecutionDisabledException extends RuntimeException
{
    public static function forIntent(int $paymentIntentId, int $trustCents): self
    {
        return new self(
            'PaymentIntent ['.$paymentIntentId.'] allocates '.$trustCents.' cents to the trust '
            .'destination class. Trust execution is DISABLED for POC #1: trust-classified money can '
            .'never create an executable provider command, and no provider destination for trust/IOLTA '
            .'exists in this system.'
        );
    }
}
