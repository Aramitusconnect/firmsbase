<?php

namespace Database\Factories;

use App\Enums\TemplatePackStatus;
use App\Models\PracticeArea;
use App\Models\TemplatePack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TemplatePack>
 */
class TemplatePackFactory extends Factory
{
    protected $model = TemplatePack::class;

    public function definition(): array
    {
        return [
            'practice_area_id' => PracticeArea::factory(),
            'pack_code' => $this->faker->unique()->slug(2, false),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'status' => TemplatePackStatus::Published,
        ];
    }

    public function forPracticeArea(PracticeArea $practiceArea): static
    {
        return $this->state(fn () => ['practice_area_id' => $practiceArea->id]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => TemplatePackStatus::Draft]);
    }
}
