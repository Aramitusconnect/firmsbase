<?php

namespace Database\Factories;

use App\Enums\DemoEventStatus;
use App\Models\DemoEvent;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoEvent>
 */
class DemoEventFactory extends Factory
{
    protected $model = DemoEvent::class;

    public function definition(): array
    {
        return [
            'opportunity_id' => Opportunity::factory(),
            'scheduled_at' => now()->addDays(3),
            'status' => DemoEventStatus::Scheduled->value,
        ];
    }

    public function forOpportunity(Opportunity $opportunity): static
    {
        return $this->state(fn () => ['opportunity_id' => $opportunity->id]);
    }
}
