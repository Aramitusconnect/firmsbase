<?php

namespace Database\Factories;

use App\Models\ModuleCatalog;
use App\Models\Plan;
use App\Models\PlanModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanModule>
 */
class PlanModuleFactory extends Factory
{
    protected $model = PlanModule::class;

    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            // NOT ModuleCatalog::factory() directly — module_code is a
            // string FK to module_catalog.module_code, not the bigint id.
            'module_code' => fn () => ModuleCatalog::factory()->create()->module_code,
            'enabled' => true,
            'is_addon' => false,
            'status' => 'active',
        ];
    }

    public function forPlan(Plan $plan): static
    {
        return $this->state(fn () => ['plan_id' => $plan->id]);
    }

    public function forModuleCode(string $moduleCode): static
    {
        return $this->state(fn () => ['module_code' => $moduleCode]);
    }

    public function addon(): static
    {
        return $this->state(fn () => ['is_addon' => true]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
