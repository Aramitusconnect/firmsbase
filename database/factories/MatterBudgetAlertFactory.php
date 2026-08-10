<?php

namespace Database\Factories;

use App\Enums\MatterBudgetAlertSeverity;
use App\Enums\MatterBudgetAlertType;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAlert;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<MatterBudgetAlert>
 */
class MatterBudgetAlertFactory extends Factory
{
    protected $model = MatterBudgetAlert::class;

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
            'alert_type' => MatterBudgetAlertType::RoleHoursThreshold,
            'metric_key' => 'attorney',
            'severity' => MatterBudgetAlertSeverity::Warning,
            'threshold_percent_crossed' => 75,
            'metric_snapshot_json' => ['consumed_percent' => 76],
            'domain_event_id' => null,
            'resolved_at' => null,
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

    public function resolved(): static
    {
        return $this->state(fn () => ['resolved_at' => now()]);
    }
}
