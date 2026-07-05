<?php

namespace Database\Factories;

use App\Enums\WebhookEventType;
use App\Models\Firm;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    protected $model = WebhookEvent::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'event_type' => WebhookEventType::MatterCreated,
            'subject_type' => null,
            'subject_id' => null,
            'payload_json' => ['matter_uuid' => (string) \Illuminate\Support\Str::uuid7()],
            'occurred_at' => now(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function ofType(WebhookEventType $type): static
    {
        return $this->state(fn () => ['event_type' => $type]);
    }
}
