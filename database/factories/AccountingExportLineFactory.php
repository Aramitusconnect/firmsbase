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
     * Audit fix (eager-factory-side-effects audit): definition() used to
     * call Firm::factory()->create() and
     * AccountingExportBatch::factory()->forFirm($firm)->create() as
     * plain PHP statements at the top of this method — a real,
     * committed Firm AND AccountingExportBatch every single time, even
     * when forExpense()/forInvoice()/forPayment() below immediately
     * override accounting_export_batch_id/firm_id/expense_id with a
     * caller-supplied batch. Laravel cannot skip a side effect that
     * already happened while building the array. Fixed by memoizing the
     * firm/batch pair behind lazy closures (mirrors
     * IntegrationOAuthStateFactory's memoized-lazy-value convention):
     * nothing is created unless at least one of the derived keys
     * survives, unoverridden, to the final row, and when it does, all
     * derived keys share the SAME firm/batch pair rather than each
     * resolving its own independent one.
     */
    private ?Firm $lazyFirm = null;

    private ?AccountingExportBatch $lazyBatch = null;

    public function definition(): array
    {
        $this->lazyFirm = null;
        $this->lazyBatch = null;

        return [
            'accounting_export_batch_id' => function () {
                $this->resolveLazyFirmAndBatch();

                return $this->lazyBatch->id;
            },
            'firm_id' => function () {
                $this->resolveLazyFirmAndBatch();

                return $this->lazyFirm->id;
            },
            'source_record_type' => AccountingExportSourceRecordType::Expense,
            'invoice_id' => null,
            'payment_id' => null,
            'expense_id' => function () {
                $this->resolveLazyFirmAndBatch();

                return Expense::factory()->forFirm($this->lazyFirm)->create()->id;
            },
            'chart_of_accounts_id' => null,
            'mapped_amount_cents' => $this->faker->numberBetween(500, 50000),
            'status' => AccountingExportLineStatus::Pending,
        ];
    }

    private function resolveLazyFirmAndBatch(): void
    {
        $this->lazyFirm ??= Firm::factory()->create();
        $this->lazyBatch ??= AccountingExportBatch::factory()->forFirm($this->lazyFirm)->create();
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
