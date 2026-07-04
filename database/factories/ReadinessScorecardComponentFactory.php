<?php

namespace Database\Factories;

use App\Enums\ReadinessComponentStatus;
use App\Models\ReadinessScorecardComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadinessScorecardComponent>
 */
class ReadinessScorecardComponentFactory extends Factory
{
    protected $model = ReadinessScorecardComponent::class;

    public function definition(): array
    {
        return [
            'component_key' => $this->faker->unique()->slug(2, '_'),
            'label' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'status' => ReadinessComponentStatus::Active,
            'introduced_in_phase' => 'phase_4',
            'weight' => 1,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => ReadinessComponentStatus::Inactive]);
    }
}
