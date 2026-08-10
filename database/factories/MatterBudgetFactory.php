<?php

namespace Database\Factories;

use App\Enums\FirmUserRole;
use App\Enums\MatterBudgetExpenseCategory;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<MatterBudget>
 */
class MatterBudgetFactory extends Factory
{
    protected $model = MatterBudget::class;

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
            'version' => 1,
            'source_template_id' => null,
            'source_template_version' => null,
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
            'change_reason' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function forMatter(Matter $matter): static
    {
        return $this->state(fn () => ['firm_id' => $matter->firm_id, 'matter_id' => $matter->id]);
    }

    public function version(int $version): static
    {
        return $this->state(fn () => ['version' => $version]);
    }
}
