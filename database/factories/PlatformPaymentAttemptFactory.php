<?php

namespace Database\Factories;

use App\Enums\PlatformPaymentAttemptStatus;
use App\Models\BillingAccount;
use App\Models\PlatformPaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformPaymentAttempt>
 */
class PlatformPaymentAttemptFactory extends Factory
{
    protected $model = PlatformPaymentAttempt::class;

    public function definition(): array
    {
        return [
            'billing_account_id' => BillingAccount::factory(),
            'platform_invoice_id' => null,
            'status' => PlatformPaymentAttemptStatus::Succeeded,
            'attempt_number' => 1,
            'gateway_response_code' => 'fake_pi_test',
            'failure_reason' => null,
            'attempted_at' => now(),
        ];
    }

    public function forBillingAccount(BillingAccount $billingAccount): static
    {
        return $this->state(fn () => ['billing_account_id' => $billingAccount->id]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => PlatformPaymentAttemptStatus::Failed,
            'failure_reason' => 'simulated_decline',
        ]);
    }
}
