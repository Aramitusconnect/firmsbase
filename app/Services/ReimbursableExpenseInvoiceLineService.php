<?php

namespace App\Services;

use App\Enums\InvoiceLineType;
use App\Models\Expense;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\InvoiceLine;

/**
 * ReimbursableExpenseInvoiceLineService — the ONLY writer that converts
 * an approved, reimbursable expense into an invoice line (correction
 * #2). Requires ReimbursableExpenseInvoiceEligibilityService::evaluate()
 * to return allowed=true first. Enforces: the invoice, matter (if
 * either side is matter-linked), expense, and firm must all match, and
 * an expense must not be added to an invoice twice — backed both here
 * (explicit check) and at the database level (the unique constraint on
 * invoice_lines.expense_id added by the one approved
 * Schema::table('invoice_lines') migration).
 */
class ReimbursableExpenseInvoiceLineService
{
    public function __construct(
        private readonly ReimbursableExpenseInvoiceEligibilityService $eligibilityService,
        private readonly TenantSafeAccountingPolicyService $tenantSafePolicy,
    ) {
    }

    public function createLine(Firm $firm, Invoice $invoice, Expense $expense, int $sortOrder = 0): InvoiceLine
    {
        if ($invoice->firm_id !== $firm->id) {
            throw new \RuntimeException('Invoice does not belong to this firm.');
        }

        $this->tenantSafePolicy->assertExpenseBelongsToFirm($expense, $firm);

        if ($invoice->matter_id !== null && $expense->matter_id !== null && $invoice->matter_id !== $expense->matter_id) {
            throw new \RuntimeException('Invoice and expense are linked to different matters.');
        }

        $decision = $this->eligibilityService->evaluate($firm, $expense);

        if (! $decision->allowed) {
            throw new \RuntimeException($decision->reason);
        }

        // Defense-in-depth re-check immediately before insert, in
        // addition to the DB-level unique constraint on
        // invoice_lines.expense_id — closes the race between the
        // eligibility check above and this write.
        if ($expense->invoiceLine()->exists()) {
            throw new \RuntimeException('Expense has already been added to an invoice.');
        }

        return InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'expense_id' => $expense->id,
            'line_type' => InvoiceLineType::ReimbursableExpense,
            'description' => sprintf('Reimbursable expense: %s', $expense->vendor_name),
            'quantity' => 1,
            'rate_cents' => $expense->amount_cents,
            'amount_cents' => $expense->amount_cents,
            'sort_order' => $sortOrder,
        ]);
    }
}
