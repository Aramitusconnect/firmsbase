<?php

namespace App\Services;

use App\Exceptions\TenantIsolationException;
use App\Models\AccountingExportBatch;
use App\Models\AccountingExportLine;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\ExpenseApproval;
use App\Models\ExpenseCategory;
use App\Models\ExpenseReceipt;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterExpense;

/**
 * TenantSafeAccountingPolicyService — the shared cross-firm guard for
 * every Phase 12 table, mirroring TenantSafeImportExportPolicyService /
 * TenantSafeSignatureAndPdfPolicyService's exact pattern (defense in
 * depth, independent of and in addition to BelongsToTenant's global
 * scope where that trait is applied).
 */
class TenantSafeAccountingPolicyService
{
    public function assertExpenseBelongsToFirm(Expense $expense, Firm $firm): void
    {
        if ($expense->firm_id !== $firm->id) {
            throw new TenantIsolationException("Expense [id={$expense->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertExpenseCategoryBelongsToFirm(ExpenseCategory $category, Firm $firm): void
    {
        if ($category->firm_id !== $firm->id) {
            throw new TenantIsolationException("ExpenseCategory [id={$category->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertChartOfAccountBelongsToFirm(ChartOfAccount $account, Firm $firm): void
    {
        if ($account->firm_id !== $firm->id) {
            throw new TenantIsolationException("ChartOfAccount [id={$account->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertExpenseReceiptBelongsToFirm(ExpenseReceipt $receipt, Firm $firm): void
    {
        if ($receipt->firm_id !== $firm->id) {
            throw new TenantIsolationException("ExpenseReceipt [id={$receipt->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertExpenseApprovalBelongsToFirm(ExpenseApproval $approval, Firm $firm): void
    {
        if ($approval->firm_id !== $firm->id) {
            throw new TenantIsolationException("ExpenseApproval [id={$approval->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertMatterExpenseBelongsToFirm(MatterExpense $matterExpense, Firm $firm): void
    {
        if ($matterExpense->firm_id !== $firm->id) {
            throw new TenantIsolationException("MatterExpense [id={$matterExpense->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertAccountingExportBatchBelongsToFirm(AccountingExportBatch $batch, Firm $firm): void
    {
        if ($batch->firm_id !== $firm->id) {
            throw new TenantIsolationException("AccountingExportBatch [id={$batch->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertAccountingExportLineBelongsToFirm(AccountingExportLine $line, Firm $firm): void
    {
        if ($line->firm_id !== $firm->id) {
            throw new TenantIsolationException("AccountingExportLine [id={$line->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    /**
     * The explicit "matter and expense must be the same firm" check
     * (correction #3's tenant-safety requirement + required test
     * "matter expense link is same-firm only").
     */
    public function assertMatterAndExpenseShareFirm(Matter $matter, Expense $expense): void
    {
        if ($matter->firm_id !== $expense->firm_id) {
            throw new TenantIsolationException(
                "Matter [id={$matter->id}] and Expense [id={$expense->id}] do not belong to the same firm."
            );
        }
    }
}
