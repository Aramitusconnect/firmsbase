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

        $service = new TenantContextService();

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    /**
     * The line, its nested batch, and its nested expense are always
     * tied to the SAME firm — one authoritative firm is generated up
     * front and $batch is resolved EAGERLY (->create() called
     * explicitly before return) so its own id/firm_id are concrete
     * values available for this array's other keys (firm_id must equal
     * $batch->firm_id, i.e. $firm->id). expense_id is left as a bare,
     * unresolved Expense::factory()->forFirm($firm) value — resolved
     * eagerly by Laravel's own expandAttributes() (via late static
     * binding into ExpenseFactory's own overridden create()) during
     * this factory's own $this->make() call, i.e. before this factory's
     * own create() override reaches its groupBy('firm_id') step. This
     * is safe only because both AccountingExportBatchFactory's and
     * ExpenseFactory's own context-hold create() overrides exist (both
     * do, as of this same batch, and land in the SAME commit as this
     * fix per this batch's hard ordering dependency — a bare
     * AccountingExportLineFactory::factory()->create() would otherwise
     * trigger a nested insert with no tenant context established
     * anywhere, which fails immediately once expenses/
     * accounting_export_batches are under FORCE RLS).
     */
    public function definition(): array
    {
        $firm = Firm::factory()->create();
        $batch = AccountingExportBatch::factory()->forFirm($firm)->create();

        return [
            'accounting_export_batch_id' => $batch->id,
            'firm_id' => $firm->id,
            'source_record_type' => AccountingExportSourceRecordType::Expense,
            'invoice_id' => null,
            'payment_id' => null,
            'expense_id' => Expense::factory()->forFirm($firm),
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
