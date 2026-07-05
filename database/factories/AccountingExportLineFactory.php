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
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingExportLine>
 */
class AccountingExportLineFactory extends Factory
{
    protected $model = AccountingExportLine::class;

    public function definition(): array
    {
        return [
            'accounting_export_batch_id' => AccountingExportBatch::factory(),
            'firm_id' => Firm::factory(),
            'source_record_type' => AccountingExportSourceRecordType::Expense,
            'invoice_id' => null,
            'payment_id' => null,
            'expense_id' => Expense::factory(),
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
