<?php

namespace Database\Factories;

use App\Enums\AccountingExportLineStatus;
use App\Enums\AccountingExportSourceRecordType;
use App\Models\AccountingExportBatch;
use App\Models\AccountingExportLine;
use App\Models\Expense;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<AccountingExportLine>
 */
class AccountingExportLineFactory extends Factory
{
    protected $model = AccountingExportLine::class;

    /**
     * accounting_export_lines has permanent FORCE ROW LEVEL SECURITY
     * (see database/migrations/2026_08_27_950024_prepare_row_level_
     * security_and_force_rls_on_accounting_export_lines_table.php), so
     * every INSERT (test or app) must run under the row's own
     * app.current_firm_id context. See MatterExpenseFactory::create()'s
     * docblock for the full rationale. Note this override operates
     * generically on firm_id, which this model has as a real column
     * despite AccountingExportLine deliberately NOT using
     * BelongsToTenant (see the model's own docblock) — RLS enforcement
     * is a pure PostgreSQL-session-level mechanism independent of that
     * Eloquent trait.
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
     * Audit fix (eager-factory-side-effects audit, second pass): the
     * previous version of this fix (see the AccountingExportLine
     * regression tests using forExpense()) memoized firm/batch behind
     * private $lazyFirm/$lazyBatch properties that BOTH
     * accounting_export_batch_id/expense_id AND firm_id itself derived
     * from — but firm_id was one of the derived keys, not the source of
     * truth. That meant a caller overriding ONLY firm_id (e.g. the
     * common ->create(['firm_id' => $firm->id]) pattern used throughout
     * AccountingExportLinesForceRlsActivationTest, never routed through
     * forExpense()/forInvoice()/forPayment()) left the
     * accounting_export_batch_id/expense_id closures completely unaware
     * of the override: they still ran resolveLazyFirmAndBatch(),
     * eagerly creating a real, wasted, UNRELATED Firm + its own
     * AccountingExportBatch + Expense, and left the row referencing
     * that wrong firm's batch/expense instead of the caller's real one
     * — a leak AND a firm_id/accounting_export_batch_id ownership
     * mismatch the DB has no composite FK to catch. Fixed by making
     * firm_id Laravel's own lazy factory-relationship form (the single
     * source of truth, resolved first) and deriving
     * accounting_export_batch_id/expense_id from the already-resolved
     * $attributes['firm_id'] via lazy closures — mirrors
     * ExpenseFactory/MatterExpenseFactory/AccountingExportBatchFactory's
     * identical $attributes-derivation convention, so ANY override of
     * firm_id (bare or via forFirm()-style helpers) is automatically
     * observed by every key derived from it.
     */
    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'accounting_export_batch_id' => fn (array $attributes) => AccountingExportBatch::factory()
                ->forFirm(Firm::query()->findOrFail($attributes['firm_id'])),
            'source_record_type' => AccountingExportSourceRecordType::Expense,
            'invoice_id' => null,
            'payment_id' => null,
            'expense_id' => fn (array $attributes) => Expense::factory()
                ->forFirm(Firm::query()->findOrFail($attributes['firm_id'])),
            'chart_of_accounts_id' => null,
            'mapped_amount_cents' => $this->faker->numberBetween(500, 50000),
            'status' => AccountingExportLineStatus::Pending,
        ];
    }

    public function forExpense(AccountingExportBatch $batch, Expense $expense): static
    {
        return $this->state(fn () => [
            'accounting_export_batch_id' => $batch->id,
            'firm_id' => $batch->firm_id,
            'source_record_type' => AccountingExportSourceRecordType::Expense,
            'invoice_id' => null,
            'payment_id' => null,
            'expense_id' => $expense->id,
            'mapped_amount_cents' => $expense->amount_cents,
        ]);
    }

    public function forInvoice(AccountingExportBatch $batch, Invoice $invoice): static
    {
        return $this->state(fn () => [
            'accounting_export_batch_id' => $batch->id,
            'firm_id' => $batch->firm_id,
            'source_record_type' => AccountingExportSourceRecordType::Invoice,
            'invoice_id' => $invoice->id,
            'payment_id' => null,
            'expense_id' => null,
            'mapped_amount_cents' => $invoice->total_cents,
        ]);
    }

    public function forPayment(AccountingExportBatch $batch, Payment $payment): static
    {
        return $this->state(fn () => [
            'accounting_export_batch_id' => $batch->id,
            'firm_id' => $batch->firm_id,
            'source_record_type' => AccountingExportSourceRecordType::Payment,
            'invoice_id' => null,
            'payment_id' => $payment->id,
            'expense_id' => null,
            'mapped_amount_cents' => $payment->amount_cents,
        ]);
    }

    public function status(AccountingExportLineStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
