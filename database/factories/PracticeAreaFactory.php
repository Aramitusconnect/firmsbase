<?php

namespace Database\Factories;

use App\Models\PracticeArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PracticeArea>
 */
class PracticeAreaFactory extends Factory
{
    protected $model = PracticeArea::class;

    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->slug(2, false),
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'is_active' => true,
        ];
    }

    public function immigration(): static
    {
        return $this->state(fn () => ['code' => 'immigration', 'name' => 'Immigration']);
    }
}
