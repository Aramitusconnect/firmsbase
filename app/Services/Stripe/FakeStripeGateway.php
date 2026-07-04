<?php

namespace App\Services\Stripe;

use Illuminate\Support\Str;

/**
 * FakeStripeGateway — the ONLY StripeGateway implementation in this
 * phase. Never calls any real Stripe SDK/API (approved decision, no
 * composer changes). Configurable to simulate success or failure so
 * tests can exercise both paths of PlatformPaymentService without any
 * external dependency.
 */
class FakeStripeGateway implements StripeGateway
{
    public function __construct(
        private bool $shouldSucceed = true,
        private ?string $failureReason = null,
    ) {
    }

    public function createPaymentIntent(int $amountCents, string $currency, array $metadata = []): array
    {
        if (! $this->shouldSucceed) {
            return [
                'status' => 'failed',
                'id' => 'fake_pi_'.Str::random(16),
                'failure_reason' => $this->failureReason ?? 'simulated_decline',
            ];
        }

        return [
            'status' => 'succeeded',
            'id' => 'fake_pi_'.Str::random(16),
            'failure_reason' => null,
        ];
    }

    public function createRefund(string $paymentIntentRef, int $amountCents): array
    {
        if (! $this->shouldSucceed) {
            return ['status' => 'failed', 'id' => 'fake_re_'.Str::random(16)];
        }

        return ['status' => 'succeeded', 'id' => 'fake_re_'.Str::random(16)];
    }
}
