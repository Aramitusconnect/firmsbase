<?php

namespace Database\Factories;

use App\Enums\AutomationActionExecutionStatus;
use App\Enums\AutomationActionRiskLevel;
use App\Enums\AutomationActionType;
use App\Models\AutomationActionExecution;
use App\Models\AutomationExecution;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Factory<AutomationActionExecution>
 */
class AutomationActionExecutionFactory extends Factory
{
    protected $model = AutomationActionExecution::class;

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
            'automation_execution_id' => AutomationExecution::factory(),
            'action_index' => 0,
            'action_type' => AutomationActionType::CreateTask,
            'action_config_json' => ['title' => 'Automated task'],
            'idempotency_key' => (string) Str::uuid(),
            'risk_level' => AutomationActionRiskLevel::AutoAllowed,
            'status' => AutomationActionExecutionStatus::Pending,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
