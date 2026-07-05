<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Firm;
use App\ValueObjects\ExpenseInvoiceEligibilityDecision;

/**
 * ReimbursableExpenseInvoiceEligibilityService — pure decision service,
 * never mutates anything. ReimbursableExpenseInvoiceLineService must
 * call this and check ->allowed before creating an InvoiceLine.
 * Eligibility requires: expenses entitlement enabled, the firm-setting
 * reimbursable_expenses_on_invoices_enabled toggle on, the expense
 * belonging to this firm, reimbursable=true, status=Approved, and not
 * already linked to an invoice line.
 */
class ReimbursableExpenseInvoiceEligibilityService
{
    public function __construct(private readonly AccountingEntitlementPolicyService $entitlementPolicy)
    {
    }

    public function evaluate(Firm $firm, Expense $expense): ExpenseInvoiceEligibilityDecision
    {
        if (! $this->entitlementPolicy->isExpensesEnabledForFirm($firm)) {
            return ExpenseInvoiceEligibilityDecision::deny('Expenses module is disabled for this firm.');
        }

        if (! $this->entitlementPolicy->reimbursableExpensesOnInvoicesEnabled($firm)) {
            return ExpenseInvoiceEligibilityDecision::deny('Reimbursable expenses on invoices is disabled for this firm (firm setting).');
        }

        if ($expense->firm_id !== $firm->id) {
            return ExpenseInvoiceEligibilityDecision::deny('Expense does not belong to this firm.');
        }

        if (! $expense->reimbursable) {
            return ExpenseInvoiceEligibilityDecision::deny('Expense is not marked reimbursable.');
        }

        if (! $expense->isApproved()) {
            return ExpenseInvoiceEligibilityDecision::deny('Expense has not been approved.');
        }

        if ($expense->invoiceLine()->exists()) {
            return ExpenseInvoiceEligibilityDecision::deny('Expense has already been added to an invoice.');
        }

        return ExpenseInvoiceEligibilityDecision::allow();
    }
}
