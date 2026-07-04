<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\PlanStatus;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true).' Plan',
            'status' => PlanStatus::Active,
            'price_cents' => $this->faker->numberBetween(9900, 49900),
            'billing_interval' => BillingInterval::Monthly,
            'support_access_level' => 'standard',
            'trial_days' => 14,
            'trial_requires_card' => false,
            'is_active' => true,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => PlanStatus::Draft]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => PlanStatus::Archived, 'is_active' => false]);
    }
}
