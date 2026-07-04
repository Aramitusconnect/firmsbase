<?php

namespace Database\Factories;

use App\Models\BillingAccount;
use App\Models\PlatformBillingEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformBillingEvent>
 */
class PlatformBillingEventFactory extends Factory
{
    protected $model = PlatformBillingEvent::class;

    public function definition(): array
    {
        return [
            'billing_account_id' => BillingAccount::factory(),
            'event_type' => 'payment_succeeded',
            'metadata' => [],
        ];
    }

    public function forBillingAccount(BillingAccount $billingAccount): static
    {
        return $this->state(fn () => ['billing_account_id' => $billingAccount->id]);
    }

    public function eventType(string $eventType): static
    {
        return $this->state(fn () => ['event_type' => $eventType]);
    }
}
