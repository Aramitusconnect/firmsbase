<?php

namespace Database\Factories;

use App\Enums\ReleaseNoteStatus;
use App\Models\ReleaseNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReleaseNote>
 */
class ReleaseNoteFactory extends Factory
{
    protected $model = ReleaseNote::class;

    public function definition(): array
    {
        return [
            'version' => 'v'.$this->faker->numberBetween(1, 9).'.'.$this->faker->numberBetween(0, 9),
            'title' => $this->faker->sentence(4),
            'body' => $this->faker->paragraph(),
            'status' => ReleaseNoteStatus::Draft->value,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => ReleaseNoteStatus::Published->value,
            'published_at' => now(),
        ]);
    }
}
