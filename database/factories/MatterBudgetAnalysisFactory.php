<?php

namespace Database\Factories;

use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAnalysis;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<MatterBudgetAnalysis>
 */
class MatterBudgetAnalysisFactory extends Factory
{
    protected $model = MatterBudgetAnalysis::class;

    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);
        $models = $results instanceof Model ? new Collection([$results]) : $results;
        $service = new TenantContextService;

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => Matter::factory(),
            'matter_budget_id' => MatterBudget::factory(),
            'hours_by_role_json' => [],
            'expenses_by_category_json' => [],
            'total_labor_cost_cents' => 0,
            'total_expenses_cents' => 0,
            'revenue_expected_cents' => null,
            'revenue_invoiced_cents' => null,
            'revenue_collected_cents' => null,
            'revenue_outstanding_cents' => null,
            'estimated_margin_cents' => null,
            'estimated_margin_percent' => null,
            'current_margin_cents' => null,
            'current_margin_percent' => null,
            'work_completion_percent' => 0,
            'work_completion_breakdown_json' => [],
            'time_elapsed_percent' => null,
            'projected_hours_by_role_json' => [],
            'projected_overrun_hours_by_role_json' => [],
            'projected_final_cost_cents' => null,
            'projected_margin_cents' => null,
            'projected_margin_percent' => null,
            'computed_at' => now(),
        ];
    }

    public function forMatter(Matter $matter, MatterBudget $budget): static
    {
        return $this->state(fn () => [
            'firm_id' => $matter->firm_id,
            'matter_id' => $matter->id,
            'matter_budget_id' => $budget->id,
        ]);
    }
}
