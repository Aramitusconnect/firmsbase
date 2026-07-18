<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterExpense;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<MatterExpense>
 */
class MatterExpenseFactory extends Factory
{
    protected $model = MatterExpense::class;

    /**
     * matter_expenses has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950012_prepare_row_level_security_
     * and_force_rls_on_matter_expenses_table.php), so every INSERT
     * (test or app) must run under the row's own app.current_firm_id
     * context. See PartyFactory::create()'s docblock for the full
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
     * The matter_expenses row and its nested matter/expense are always
     * tied to the SAME firm — one authoritative firm is generated up
     * front (rather than letting firm_id, matter_id, and expense_id
     * resolve as three independent Firm::factory()/Matter::factory()/
     * Expense::factory() calls), matching the root-cause fix already
     * applied to MatterFactory/InvoiceFactory/PaymentFactory. A bare
     * matter_expenses row whose matter or expense belongs to an
     * unrelated firm is exactly the transitive cross-firm mismatch
     * documented as a known, deliberately-deferred gap in this table's
     * FORCE migration (no composite FK/trigger enforces it at the
     * database layer) — the factory must not manufacture that invalid
     * shape by default just because RLS itself cannot catch it.
     */
    public function definition(): array
    {
        $firm = Firm::factory()->create();

        return [
            'firm_id' => $firm->id,
            'matter_id' => Matter::factory()->forFirm($firm),
            'expense_id' => Expense::factory()->forFirm($firm),
            'reimbursable_snapshot' => false,
        ];
    }

    public function forExpenseAndMatter(Expense $expense, Matter $matter): static
    {
        return $this->state(fn () => [
            'firm_id' => $expense->firm_id,
            'matter_id' => $matter->id,
            'expense_id' => $expense->id,
            'reimbursable_snapshot' => $expense->reimbursable,
        ]);
    }
}
