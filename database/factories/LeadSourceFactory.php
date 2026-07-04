<?php

namespace Database\Factories;

use App\Models\Firm;
use App\Models\LeadSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadSource>
 */
class LeadSourceFactory extends Factory
{
    protected $model = LeadSource::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'code' => $this->faker->unique()->slug(2, false),
            'name' => $this->faker->words(2, true),
            'is_active' => true,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
