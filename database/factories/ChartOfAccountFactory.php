<?php

namespace Database\Factories;

use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Models\ChartOfAccount;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<ChartOfAccount>
 */
class ChartOfAccountFactory extends Factory
{
    protected $model = ChartOfAccount::class;

    /**
     * chart_of_accounts has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950018_prepare_row_level_security_
     * and_force_rls_on_chart_of_accounts_table.php), so every INSERT
     * (test or app) must run under the row's own app.current_firm_id
     * context. See MatterExpenseFactory::create()'s docblock for the
     * full rationale.
     */
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
            'account_code' => $this->faker->unique()->numerify('####'),
            'account_name' => $this->faker->words(2, true),
            'account_type' => ChartOfAccountType::Expense,
            'is_active' => true,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function type(ChartOfAccountType $type): static
    {
        return $this->state(fn () => ['account_type' => $type]);
    }

    public function purpose(ChartOfAccountPurpose $purpose): static
    {
        return $this->state(fn () => ['purpose' => $purpose]);
    }
}
