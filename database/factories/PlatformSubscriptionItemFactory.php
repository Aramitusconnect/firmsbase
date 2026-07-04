<?php

namespace Database\Factories;

use App\Models\PlatformSubscription;
use App\Models\PlatformSubscriptionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformSubscriptionItem>
 */
class PlatformSubscriptionItemFactory extends Factory
{
    protected $model = PlatformSubscriptionItem::class;

    public function definition(): array
    {
        return [
            'platform_subscription_id' => PlatformSubscription::factory(),
            'item_type' => 'plan_base',
            'seat_class' => null,
            'quantity' => 1,
            'unit_amount_cents' => 19900,
            'metadata' => [],
        ];
    }

    public function forSubscription(PlatformSubscription $subscription): static
    {
        return $this->state(fn () => ['platform_subscription_id' => $subscription->id]);
    }
}
