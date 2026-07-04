<?php

namespace Database\Factories;

use App\Enums\CommissionPlanStatus;
use App\Models\CommissionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionPlan>
 */
class CommissionPlanFactory extends Factory
{
    protected $model = CommissionPlan::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true).' commission plan',
            'rate_type' => 'percentage',
            'rate_value' => 10.00,
            'holding_period_days' => 30,
            'status' => CommissionPlanStatus::Active->value,
        ];
    }

    public function flat(float $amount): static
    {
        return $this->state(fn () => ['rate_type' => 'flat', 'rate_value' => $amount]);
    }
}
