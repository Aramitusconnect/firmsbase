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
 *
 * chart_of_accounts, expenses, and accounting_export_lines all now have
 * permanent FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_08_27_950018/950020/950024_*.php). Every
 * constituent read/write path below (eligibleExpenses(),
 * resolveActiveAccountByType(), buildLine() — plus the already-wrapped
 * eligibleInvoices()/eligibleOperatingPayments(), unchanged) gets its
 * OWN independent runWithFirmContext() wrap, called once per call site
 * exactly as today. buildForBatch() itself gains NO wrap of its own —
 * it remains a plain orchestrating loop; this deliberately introduces
 * NO new cross-line/cross-method transaction/atomicity guarantee that
 * doesn't already exist today (each of these units of work was already
 * independent; this only extends the same "one independent transaction
 * per unit of work" shape to the units that were missing it).
 * assertExpensesEnabled() stays OUTSIDE any wrap, unchanged — see
 * ExpenseService's own docblock for the full decoy-wrap rationale.
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
        return (new TenantContextService())->runWithFirmContext($batch->firm_id, fn () => Expense::query()
            ->where('firm_id', $batch->firm_id)
            ->where('status', ExpenseStatus::Approved->value)
            ->whereBetween('expense_date', [$batch->date_range_start, $batch->date_range_end])
            ->with('category.chartOfAccount')
            ->get());
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
        return (new TenantContextService())->runWithFirmContext($batch->firm_id, fn () => Payment::query()
            ->where('firm_id', $batch->firm_id)
            ->where('payment_classification', PaymentClassification::OperatingPayment->value)
            ->where('status', PaymentStatus::Succeeded->value)
            ->whereBetween('created_at', [$this->windowStart($batch), $this->windowEnd($batch)])
            ->get());
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
        return (new TenantContextService())->runWithFirmContext($batch->firm_id, fn () => AccountingExportLine::create([
            'accounting_export_batch_id' => $batch->id,
            'firm_id' => $batch->firm_id,
            'source_record_type' => $type,
            'invoice_id' => $type === AccountingExportSourceRecordType::Invoice ? $record->id : null,
            'payment_id' => $type === AccountingExportSourceRecordType::Payment ? $record->id : null,
            'expense_id' => $type === AccountingExportSourceRecordType::Expense ? $record->id : null,
            'chart_of_accounts_id' => $chartOfAccount?->id,
            'mapped_amount_cents' => $amountCents,
            'status' => AccountingExportLineStatus::Pending,
        ]));
    }

    private function resolveActiveAccountByType(AccountingExportBatch $batch, ChartOfAccountType $type): ?ChartOfAccount
    {
        return (new TenantContextService())->runWithFirmContext($batch->firm_id, fn () => ChartOfAccount::query()
            ->where('firm_id', $batch->firm_id)
            ->where('account_type', $type->value)
            ->where('is_active', true)
            ->first());
    }
}
