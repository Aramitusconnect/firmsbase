<?php

namespace Database\Factories;

use App\Enums\FirmUserRole;
use App\Enums\TaskWorkCategory;
use App\Models\Firm;
use App\Models\TaskCategoryRoleExpectation;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<TaskCategoryRoleExpectation>
 */
class TaskCategoryRoleExpectationFactory extends Factory
{
    protected $model = TaskCategoryRoleExpectation::class;

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
            'task_category' => TaskWorkCategory::DocumentFollowUp,
            'recommended_roles_json' => [FirmUserRole::Paralegal->value],
            'notes' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function forCategory(TaskWorkCategory $category, array $roles): static
    {
        return $this->state(fn () => [
            'task_category' => $category,
            'recommended_roles_json' => array_map(fn (FirmUserRole $r) => $r->value, $roles),
        ]);
    }
}
