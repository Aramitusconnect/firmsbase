<?php

namespace App\Services\Stripe;

use App\Exceptions\PaymentProviderUnavailableException;

/**
 * UnavailablePaymentGateway — Payment-Channel Safety Hardening pass,
 * item 1. The StripeGateway implementation bound whenever
 * PaymentGatewaySimulationPolicyService::isSimulationEnabled() is
 * false — i.e. every environment except testing (always simulated)
 * and an explicitly opted-in local box (PAYMENT_GATEWAY_SIMULATION_ENABLED=true).
 * A real Stripe/LawPay connector has never been built in this codebase
 * (see StripeGateway's own docblock); until one exists AND is
 * container-bound in its place, this is what staging/production
 * resolve to.
 *
 * Both methods throw immediately — never return a fabricated
 * ['status' => 'succeeded', ...] shape. This is the fail-closed
 * boundary: no caller downstream of this class (PaymentRequestCheckoutService,
 * PlatformPaymentService, or anything built against StripeGateway
 * later) can ever receive provider evidence that no real money
 * actually moved.
 */
class UnavailablePaymentGateway implements StripeGateway
{
    public function createPaymentIntent(int $amountCents, string $currency, array $metadata = []): array
    {
        throw new PaymentProviderUnavailableException;
    }

    public function createRefund(string $paymentIntentRef, int $amountCents): array
    {
        throw new PaymentProviderUnavailableException;
    }
}
