<?php

namespace Database\Factories;

use App\Enums\CustomerHealthRiskLevel;
use App\Models\CustomerSuccessHealthScore;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerSuccessHealthScore>
 */
class CustomerSuccessHealthScoreFactory extends Factory
{
    protected $model = CustomerSuccessHealthScore::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'computed_at' => now(),
            'score' => $this->faker->numberBetween(0, 100),
            'risk_level' => CustomerHealthRiskLevel::Healthy->value,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function riskLevel(CustomerHealthRiskLevel $riskLevel): static
    {
        return $this->state(fn () => ['risk_level' => $riskLevel->value]);
    }
}
