<?php

namespace App\Services;

use App\Enums\AccountingExportLineStatus;
use App\Enums\AccountingExportSourceRecordType;
use App\Enums\ChartOfAccountType;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentClassification;
use App\Enums\PaymentStatus;
use App\Models\AccountingExportBatch;
use App\Models\AccountingExportLine;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Collection;

/**
 * AccountingExportLineBuilderService — selects eligible Invoice/Payment/
 * Expense operating records within the batch's date range and writes
 * one accounting_export_lines row per record, mapped through the
 * firm's chart_of_accounts.
 *
 * Payment export is allow-list based: only OperatingPayment records
 * with Succeeded status are selected. All other payment classifications
 * are ignored before a line can be built.
 *
 * chart_of_accounts_id may resolve to null. The line is still created
 * as Pending and will fail during local simulation with a logged error.
 */
class AccountingExportLineBuilderService
{
    public function __construct(private readonly AccountingEntitlementPolicyService $entitlementPolicy)
    {
    }

    /**
     * @return Collection<int, AccountingExportLine>
     */
    public function buildForBatch(AccountingExportBatch $batch): Collection
    {
        $this->entitlementPolicy->assertExpensesEnabled($batch->firm);

        $lines = new Collection();

        foreach ($this->eligibleExpenses($batch) as $expense) {
            $lines->push($this->buildLine(
                $batch,
                AccountingExportSourceRecordType::Expense,
                $expense,
                $expense->amount_cents,
                $expense->category?->chartOfAccount,
            ));
        }

        foreach ($this->eligibleInvoices($batch) as $invoice) {
            $lines->push($this->buildLine(
                $batch,
                AccountingExportSourceRecordType::Invoice,
                $invoice,
                $invoice->total_cents,
                $this->resolveActiveAccountByType($batch, ChartOfAccountType::Revenue),
            ));
        }

        foreach ($this->eligibleOperatingPayments($batch) as $payment) {
            $lines->push($this->buildLine(
                $batch,
                AccountingExportSourceRecordType::Payment,
                $payment,
                $payment->amount_cents,
                $this->resolveActiveAccountByType($batch, ChartOfAccountType::Asset),
            ));
        }

        return $lines;
    }

    private function eligibleExpenses(AccountingExportBatch $batch): Collection
    {
        return Expense::query()
            ->where('firm_id', $batch->firm_id)
            ->where('status', ExpenseStatus::Approved->value)
            ->whereBetween('expense_date', [$batch->date_range_start, $batch->date_range_end])
            ->get();
    }

    private function eligibleInvoices(AccountingExportBatch $batch): Collection
    {
        return (new TenantContextService())->runWithFirmContext($batch->firm_id, fn () => Invoice::query()
            ->where('firm_id', $batch->firm_id)
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])
            ->whereBetween('issued_at', [$this->windowStart($batch), $this->windowEnd($batch)])
            ->get());
    }

    /**
     * Payment export is allow-list based. Only OperatingPayment and
     * Succeeded rows are eligible.
     */
    private function eligibleOperatingPayments(AccountingExportBatch $batch): Collection
    {
        return Payment::query()
            ->where('firm_id', $batch->firm_id)
            ->where('payment_classification', PaymentClassification::OperatingPayment->value)
            ->where('status', PaymentStatus::Succeeded->value)
            ->whereBetween('created_at', [$this->windowStart($batch), $this->windowEnd($batch)])
            ->get();
    }

    private function windowStart(AccountingExportBatch $batch): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::parse($batch->date_range_start)->startOfDay();
    }

    private function windowEnd(AccountingExportBatch $batch): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::parse($batch->date_range_end)->endOfDay();
    }

    private function buildLine(
        AccountingExportBatch $batch,
        AccountingExportSourceRecordType $type,
        Expense|Invoice|Payment $record,
        int $amountCents,
        ?ChartOfAccount $chartOfAccount,
    ): AccountingExportLine {
        return AccountingExportLine::create([
            'accounting_export_batch_id' => $batch->id,
            'firm_id' => $batch->firm_id,
            'source_record_type' => $type,
            'invoice_id' => $type === AccountingExportSourceRecordType::Invoice ? $record->id : null,
            'payment_id' => $type === AccountingExportSourceRecordType::Payment ? $record->id : null,
            'expense_id' => $type === AccountingExportSourceRecordType::Expense ? $record->id : null,
            'chart_of_accounts_id' => $chartOfAccount?->id,
            'mapped_amount_cents' => $amountCents,
            'status' => AccountingExportLineStatus::Pending,
        ]);
    }

    private function resolveActiveAccountByType(AccountingExportBatch $batch, ChartOfAccountType $type): ?ChartOfAccount
    {
        return ChartOfAccount::query()
            ->where('firm_id', $batch->firm_id)
            ->where('account_type', $type->value)
            ->where('is_active', true)
            ->first();
    }
}
