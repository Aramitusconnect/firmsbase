<?php

namespace Database\Factories;

use App\Enums\ExpenseApprovalStatus;
use App\Models\Expense;
use App\Models\ExpenseApproval;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<ExpenseApproval>
 */
class ExpenseApprovalFactory extends Factory
{
    protected $model = ExpenseApproval::class;

    /**
     * expense_approvals has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950022_prepare_row_level_security_
     * and_force_rls_on_expense_approvals_table.php), so every INSERT
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

    /**
     * The approval and its nested expense are always tied to the SAME
     * firm — one authoritative firm is generated up front (rather than
     * letting firm_id and expense_id resolve as two independent
     * Firm::factory() calls), matching the root-cause fix already
     * applied to ExpenseFactory/MatterExpenseFactory. A bare approval
     * row whose expense belongs to an unrelated firm is exactly the
     * transitive cross-firm mismatch documented as a known,
     * deliberately-deferred gap in this table's FORCE migration (no
     * composite FK/trigger enforces it at the database layer) — the
     * factory must not manufacture that invalid shape by default just
     * because RLS itself cannot catch it. Safe once ExpenseFactory's
     * own context-hold create() override exists (it does, as of this
     * same batch) — see Expense::factory()->forFirm($firm)'s bare
     * reference below, resolved eagerly by Laravel's own
     * expandAttributes() during $this->make(), i.e. before this
     * factory's own create() override reaches its groupBy('firm_id')
     * step.
     */
    /**
     * Audit fix (eager-factory-side-effects audit): this used to call
     * Firm::factory()->create() as a plain PHP statement at the top of
     * definition() — a real, committed Firm every single time, even
     * when forExpense() below immediately overrides both firm_id and
     * expense_id with a caller-supplied expense. Fixed by making
     * firm_id Laravel's own lazy factory-relationship form; expense_id
     * remains a lazy, uncreated Factory instance derived from it (never
     * eagerly ->create()'d), matching Laravel's own lazy-relationship
     * convention.
     */
    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'expense_id' => fn (array $attributes) => Expense::factory()
                ->forFirm(Firm::query()->findOrFail($attributes['firm_id']))
                ->create()
                ->id,
            'status' => ExpenseApprovalStatus::Pending,
            'decided_by_firm_user_id' => null,
            'decided_at' => null,
            'reason' => null,
        ];
    }

    public function forExpense(Expense $expense): static
    {
        return $this->state(fn () => [
            'firm_id' => $expense->firm_id,
            'expense_id' => $expense->id,
        ]);
    }

    public function status(ExpenseApprovalStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
