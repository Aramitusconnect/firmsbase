<?php

namespace Database\Factories;

use App\Models\ConsultationOutcome;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsultationOutcome>
 */
class ConsultationOutcomeFactory extends Factory
{
    protected $model = ConsultationOutcome::class;

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
