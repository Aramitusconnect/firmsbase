<?php

namespace App\Services\Stripe;

/**
 * StripeGateway — the fakeable abstraction boundary for platform
 * payment collection. NO implementation of this interface may make a
 * real Stripe SDK/API call in this phase (approved decision) — only
 * FakeStripeGateway exists. Stripe confirms money movement only;
 * FirmsBase (PlatformBillingClassificationService, PlatformPaymentService)
 * decides classification, ledger impact, invoice impact, and blocking
 * rules BEFORE this interface is ever called (project rule 9).
 */
interface StripeGateway
{
    /**
     * Simulates creating and confirming a PaymentIntent for the given
     * amount. Returns a shape mirroring the minimum fields a real
     * Stripe PaymentIntent response would have:
     * ['status' => 'succeeded'|'failed', 'id' => string, 'failure_reason' => ?string].
     */
    public function createPaymentIntent(int $amountCents, string $currency, array $metadata = []): array;

    /**
     * Simulates issuing a refund against a previously created payment
     * intent reference.
     * Returns ['status' => 'succeeded'|'failed', 'id' => string].
     */
    public function createRefund(string $paymentIntentRef, int $amountCents): array;
}
