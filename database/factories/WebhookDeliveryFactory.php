<?php

namespace Database\Factories;

use App\Enums\WebhookDeliveryStatus;
use App\Models\Firm;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'webhook_subscription_id' => WebhookSubscription::factory(),
            'webhook_event_id' => WebhookEvent::factory(),
            'status' => WebhookDeliveryStatus::Pending,
            'attempt_count' => 0,
        ];
    }

    public function forSubscriptionAndEvent(WebhookSubscription $subscription, WebhookEvent $event): static
    {
        return $this->state(fn () => [
            'firm_id' => $subscription->firm_id,
            'webhook_subscription_id' => $subscription->id,
            'webhook_event_id' => $event->id,
        ]);
    }

    public function exhausted(int $attemptCount = 5): static
    {
        return $this->state(fn () => [
            'status' => WebhookDeliveryStatus::Exhausted,
            'attempt_count' => $attemptCount,
            'last_attempted_at' => now(),
        ]);
    }
}
