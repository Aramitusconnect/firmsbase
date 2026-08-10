<?php

namespace Database\Factories;

use App\Enums\FirmUserRole;
use App\Enums\MatterBudgetExpenseCategory;
use App\Models\Firm;
use App\Models\MatterBudgetTemplate;
use App\Models\MatterType;
use App\Models\PracticeArea;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<MatterBudgetTemplate>
 */
class MatterBudgetTemplateFactory extends Factory
{
    protected $model = MatterBudgetTemplate::class;

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
            'name' => $this->faker->sentence(3),
            'description' => null,
            'practice_area_id' => null,
            'matter_type_id' => null,
            'expected_duration_days' => 30,
            'expected_hours_json' => [
                FirmUserRole::Attorney->value => 8,
                FirmUserRole::Paralegal->value => 15,
            ],
            'expected_expenses_json' => [
                MatterBudgetExpenseCategory::FilingCourtCosts->value => 20000,
            ],
            'expected_revenue_cents' => 500000,
            'target_gross_margin_percent' => 40,
            'warning_threshold_percent' => 75,
            'high_threshold_percent' => 90,
            'active' => true,
            'version' => 1,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function forMatterType(MatterType $matterType): static
    {
        return $this->state(fn () => [
            'matter_type_id' => $matterType->id,
            'practice_area_id' => $matterType->practice_area_id,
        ]);
    }

    public function forPracticeArea(PracticeArea $practiceArea): static
    {
        return $this->state(fn () => ['practice_area_id' => $practiceArea->id]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
