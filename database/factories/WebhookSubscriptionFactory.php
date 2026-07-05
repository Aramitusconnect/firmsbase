<?php

namespace Database\Factories;

use App\Enums\WebhookEventType;
use App\Enums\WebhookSubscriptionStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookSubscription>
 */
class WebhookSubscriptionFactory extends Factory
{
    protected $model = WebhookSubscription::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'event_types' => [WebhookEventType::MatterCreated->value],
            'destination_url' => 'https://example.com/webhooks/firmsbase',
            'status' => WebhookSubscriptionStatus::Active,
            'retry_policy_json' => [
                'max_attempts' => 5,
                'base_delay_seconds' => 30,
                'multiplier' => 2,
            ],
            'created_by_firm_user_id' => FirmUser::factory(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['status' => WebhookSubscriptionStatus::Disabled]);
    }

    /**
     * @param list<string> $eventTypes
     */
    public function withEventTypes(array $eventTypes): static
    {
        return $this->state(fn () => ['event_types' => $eventTypes]);
    }
}
