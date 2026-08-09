<?php

namespace App\Exceptions;

/**
 * PaymentProviderUnavailableException — Payment-Channel Safety
 * Hardening pass, item 1. Thrown by UnavailablePaymentGateway (the
 * StripeGateway binding used whenever
 * PaymentGatewaySimulationPolicyService::isSimulationEnabled() is
 * false — every environment except testing and an explicitly opted-in
 * local box). Thrown BEFORE any provider evidence exists — no
 * PaymentIntent id, no status, nothing a caller could mistake for a
 * real (or even simulated) confirmation. Callers must never catch this
 * and substitute a fabricated success; the only correct response is to
 * tell the payer that online payment is not currently available.
 */
class PaymentProviderUnavailableException extends \RuntimeException
{
    public function __construct(string $message = 'No live payment provider is configured for this environment.')
    {
        parent::__construct($message);
    }
}
