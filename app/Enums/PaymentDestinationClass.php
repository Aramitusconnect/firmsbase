<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * PaymentDestinationClass — FirmsVault Pay Gate A2. Which side of the
 * firm's financial world an allocated portion of a PaymentIntent is
 * destined for.
 *
 * This is deliberately SEPARATE from App\Enums\PaymentClassification
 * (which classifies an already-created App\Models\Payment and is
 * written only by PaymentClassificationService). This enum answers a
 * different question, one step earlier: "where is this slice of the
 * instruction headed", before any Payment exists at all.
 *
 * POC #1 invariant (Master Execution Prompt v1.4 §19,
 * trust_execution_mode = DISABLED): a Trust-destined allocation is a
 * legitimate, representable part of a complete instruction, but it can
 * never become an executable provider command. See
 * App\Services\Pay\PaymentIntentService::executionEligibility().
 */
enum PaymentDestinationClass: string
{
    case Operating = 'operating';
    case Trust = 'trust';

    /**
     * Whether value in this class may be executed through a payment
     * provider during POC #1. Trust execution is disabled — this is
     * the enum-level half of that block; the authoritative refusal is
     * enforced in PaymentIntentService and re-proved by
     * FV-A2-030/031/032.
     */
    public function isProviderExecutableInPoc(): bool
    {
        return $this === self::Operating;
    }
}
