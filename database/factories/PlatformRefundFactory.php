<?php

namespace Database\Factories;

use App\Enums\PlatformRefundStatus;
use App\Models\PlatformPayment;
use App\Models\PlatformRefund;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformRefund>
 */
class PlatformRefundFactory extends Factory
{
    protected $model = PlatformRefund::class;

    public function definition(): array
    {
        return [
            'platform_payment_id' => PlatformPayment::factory(),
            'status' => PlatformRefundStatus::Completed,
            'amount_cents' => 5000,
            'reason' => 'Customer requested partial refund.',
            'gateway_refund_ref' => 'fake_re_test',
            'requested_at' => now(),
            'processed_at' => now(),
        ];
    }

    public function forPayment(PlatformPayment $payment): static
    {
        return $this->state(fn () => ['platform_payment_id' => $payment->id]);
    }
}
