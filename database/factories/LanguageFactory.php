<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Language>
 */
class LanguageFactory extends Factory
{
    protected $model = Language::class;

    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->languageCode(),
            'name' => $this->faker->unique()->word(),
            'is_active' => true,
        ];
    }

    public function english(): static
    {
        return $this->state(fn () => ['code' => 'en', 'name' => 'English']);
    }

    public function spanish(): static
    {
        return $this->state(fn () => ['code' => 'es', 'name' => 'Spanish']);
    }

    public function arabic(): static
    {
        return $this->state(fn () => ['code' => 'ar', 'name' => 'Arabic']);
    }
}
