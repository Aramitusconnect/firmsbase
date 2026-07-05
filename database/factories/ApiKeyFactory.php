<?php

namespace Database\Factories;

use App\Enums\ApiKeyStatus;
use App\Models\ApiKey;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'key_type' => 'firm',
            'name' => $this->faker->words(2, true).' key',
            'hashed_secret' => Hash::make('fbk_'.$this->faker->uuid()),
            'last_four' => $this->faker->numerify('####'),
            'status' => ApiKeyStatus::Active->value,
        ];
    }

    public function platform(): static
    {
        return $this->state(fn () => ['firm_id' => null, 'key_type' => 'platform']);
    }

    public function firmType(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id, 'key_type' => 'firm']);
    }
}
