<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<ExpenseReceipt>
 */
class ExpenseReceiptFactory extends Factory
{
    protected $model = ExpenseReceipt::class;

    /**
     * expense_receipts has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950021_prepare_row_level_security_
     * and_force_rls_on_expense_receipts_table.php), so every INSERT
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
     * The receipt and its nested expense are always tied to the SAME
     * firm — one authoritative firm is generated up front, matching the
     * root-cause fix already applied to ExpenseFactory/MatterExpenseFactory.
     * A bare receipt row whose expense belongs to an unrelated firm is
     * exactly the transitive cross-firm mismatch documented as a
     * known, deliberately-deferred gap in this table's FORCE migration
     * — the factory must not manufacture that invalid shape by default
     * just because RLS itself cannot catch it. Safe once ExpenseFactory's
     * own context-hold create() override exists (it does, as of this
     * same batch).
     */
    /**
     * Audit fix (eager-factory-side-effects audit): this used to call
     * Firm::factory()->create() as a plain PHP statement at the top of
     * definition() — a real, committed Firm every single time, even
     * when forExpense() below immediately overrides both firm_id and
     * expense_id with a caller-supplied expense. Fixed by making
     * firm_id Laravel's own lazy factory-relationship form; expense_id
     * remains a lazy, uncreated Factory instance derived from it.
     */
    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'expense_id' => fn (array $attributes) => Expense::factory()
                ->forFirm(Firm::query()->findOrFail($attributes['firm_id'])),
            'storage_disk' => 'local',
            'storage_path' => 'expense-receipts/'.$this->faker->uuid().'.pdf',
            'original_filename' => $this->faker->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(1024, 5_000_000),
            'file_hash' => hash('sha256', $this->faker->uuid()),
            'encryption_key_id' => null,
            'uploaded_by_firm_user_id' => null,
        ];
    }

    public function forExpense(Expense $expense): static
    {
        return $this->state(fn () => [
            'firm_id' => $expense->firm_id,
            'expense_id' => $expense->id,
        ]);
    }
}
