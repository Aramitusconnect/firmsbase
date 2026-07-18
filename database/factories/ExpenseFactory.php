<?php

namespace Database\Factories;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    /**
     * expenses has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950020_prepare_row_level_security_
     * and_force_rls_on_expenses_table.php), so every INSERT (test or
     * app) must run under the row's own app.current_firm_id context.
     * See MatterExpenseFactory::create()'s docblock for the full
     * rationale, including why setDatabaseTenantContextForFirmId() is
     * used instead of setFirmContext()/runWithFirmContext() and why the
     * setting is deliberately left active rather than cleared.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);

        $models = $results instanceof Model ? new Collection([$results]) : $results;

        $service = new TenantContextService();

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    /**
     * The expense and its nested category/created-by user are always
     * tied to the SAME firm — one authoritative firm is generated up
     * front (rather than letting firm_id, expense_category_id, and
     * created_by_firm_user_id resolve as three independent
     * Firm::factory() calls), matching the root-cause fix already
     * applied to MatterExpenseFactory/AiToolActionFactory. A bare
     * expense row whose category or creator belongs to an unrelated
     * firm is exactly the transitive cross-firm mismatch documented as
     * a known, deliberately-deferred gap in this table's FORCE
     * migration (no composite FK/trigger enforces it at the database
     * layer) — the factory must not manufacture that invalid shape by
     * default just because RLS itself cannot catch it.
     */
    public function definition(): array
    {
        $firm = Firm::factory()->create();

        return [
            'firm_id' => $firm->id,
            'matter_id' => null,
            'expense_category_id' => ExpenseCategory::factory()->forFirm($firm),
            'vendor_name' => $this->faker->company(),
            'amount_cents' => $this->faker->numberBetween(500, 50000),
            'currency' => 'usd',
            'expense_date' => now()->subDays($this->faker->numberBetween(0, 30)),
            'status' => ExpenseStatus::Draft,
            'reimbursable' => false,
            'description' => $this->faker->sentence(),
            'created_by_firm_user_id' => FirmUser::factory()->forFirm($firm),
        ];
    }

    /**
     * Ties the expense AND its nested category to the given firm.
     */
    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'expense_category_id' => ExpenseCategory::factory()->forFirm($firm),
            'created_by_firm_user_id' => FirmUser::factory()->forFirm($firm),
        ]);
    }

    public function reimbursable(bool $reimbursable = true): static
    {
        return $this->state(fn () => ['reimbursable' => $reimbursable]);
    }

    public function status(ExpenseStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
