<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\PlatformSubscriptionStatus;
use App\Models\BillingAccount;
use App\Models\Plan;
use App\Models\PlatformSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformSubscription>
 */
class PlatformSubscriptionFactory extends Factory
{
    protected $model = PlatformSubscription::class;

    public function definition(): array
    {
        return [
            'billing_account_id' => BillingAccount::factory(),
            'plan_id' => Plan::factory(),
            'status' => PlatformSubscriptionStatus::Active,
            'billing_interval' => BillingInterval::Monthly,
            'current_period_starts_at' => now()->startOfMonth(),
            'current_period_ends_at' => now()->endOfMonth(),
            'trial_ends_at' => null,
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
            'gateway_subscription_ref' => null,
        ];
    }

    public function forBillingAccount(BillingAccount $billingAccount): static
    {
        return $this->state(fn () => ['billing_account_id' => $billingAccount->id]);
    }

    public function trialing(): static
    {
        return $this->state(fn () => [
            'status' => PlatformSubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addDays(14),
        ]);
    }
}
