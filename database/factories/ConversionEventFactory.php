<?php

namespace Database\Factories;

use App\Enums\ConversionEventType;
use App\Models\ConversionEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversionEvent>
 */
class ConversionEventFactory extends Factory
{
    protected $model = ConversionEvent::class;

    public function definition(): array
    {
        return [
            'event_type' => ConversionEventType::LeadToOpportunity->value,
            'occurred_at' => now(),
        ];
    }

    public function type(ConversionEventType $type): static
    {
        return $this->state(fn () => ['event_type' => $type->value]);
    }
}
