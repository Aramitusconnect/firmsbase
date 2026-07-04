<?php

namespace Database\Factories;

use App\Enums\PaymentClassification;
use App\Enums\PlatformPaymentStatus;
use App\Models\BillingAccount;
use App\Models\PlatformPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformPayment>
 */
class PlatformPaymentFactory extends Factory
{
    protected $model = PlatformPayment::class;

    public function definition(): array
    {
        return [
            'billing_account_id' => BillingAccount::factory(),
            'platform_invoice_id' => null,
            'status' => PlatformPaymentStatus::Succeeded,
            'classification' => PaymentClassification::OperatingPayment,
            'amount_cents' => 19900,
            'gateway_payment_ref' => 'fake_pi_test',
            'attempted_at' => now(),
            'succeeded_at' => now(),
            'failed_at' => null,
        ];
    }

    public function forBillingAccount(BillingAccount $billingAccount): static
    {
        return $this->state(fn () => ['billing_account_id' => $billingAccount->id]);
    }
}
