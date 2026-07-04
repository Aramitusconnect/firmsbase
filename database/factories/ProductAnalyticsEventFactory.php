<?php

namespace Database\Factories;

use App\Enums\ProductAnalyticsEventType;
use App\Models\ProductAnalyticsEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductAnalyticsEvent>
 */
class ProductAnalyticsEventFactory extends Factory
{
    protected $model = ProductAnalyticsEvent::class;

    public function definition(): array
    {
        return [
            'event_type' => ProductAnalyticsEventType::FirmCreated->value,
            'occurred_at' => now(),
        ];
    }

    public function type(ProductAnalyticsEventType $type): static
    {
        return $this->state(fn () => ['event_type' => $type->value]);
    }
}
