<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ProviderCommandType — FirmsVault Pay Gate A2. Provider-NEUTRAL
 * economic instruction types. Deliberately contains no Finix (or any
 * other provider) concept: Gate A2 is provider-independent by mandate
 * (v1.4 §15), and a provider-specific value here would leak vendor
 * shape into the core domain — an explicit Gate A2 stop condition
 * (v1.4 §57, "Finix-specific fields are required in core domain").
 */
enum ProviderCommandType: string
{
    /** Collect money for a frozen PaymentIntent. */
    case CapturePayment = 'capture_payment';

    /** Return previously captured money for a specific PaymentAttempt. */
    case RefundPayment = 'refund_payment';
}
