<?php

namespace Database\Factories;

use App\Models\MatterType;
use App\Models\PracticeArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterType>
 */
class MatterTypeFactory extends Factory
{
    protected $model = MatterType::class;

    public function definition(): array
    {
        return [
            'practice_area_id' => PracticeArea::factory(),
            'code' => $this->faker->unique()->slug(2, false),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'is_active' => true,
        ];
    }

    public function forPracticeArea(PracticeArea $practiceArea): static
    {
        return $this->state(fn () => ['practice_area_id' => $practiceArea->id]);
    }
}
