<?php

namespace Database\Factories;

use App\Enums\PlanLimitMetric;
use App\Models\Plan;
use App\Models\PlanLimit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanLimit>
 */
class PlanLimitFactory extends Factory
{
    protected $model = PlanLimit::class;

    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'metric' => PlanLimitMetric::SeatsAttorney,
            'limit_value' => 5,
        ];
    }

    public function forPlan(Plan $plan): static
    {
        return $this->state(fn () => ['plan_id' => $plan->id]);
    }

    public function metric(PlanLimitMetric $metric): static
    {
        return $this->state(fn () => ['metric' => $metric]);
    }

    public function unlimited(): static
    {
        return $this->state(fn () => ['limit_value' => null]);
    }
}
