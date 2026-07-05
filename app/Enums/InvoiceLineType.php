<?php

namespace App\Enums;

/**
 * InvoiceLineType — "Create invoice lines from time entries and
 * approved charges" (PDF Scope). TimeEntry and FlatFee are required by
 * that sentence; ManualCharge covers "approved charges" that are not a
 * time entry; Adjustment is a recommendation (not explicitly named by
 * the PDF) for correcting an already-sent invoice without editing a
 * historical line in place.
 *
 * Phase 12 addition: ReimbursableExpense — the only line type created
 * by ReimbursableExpenseInvoiceLineService, for an already-approved,
 * already-reimbursable operating expense (app/Models/Expense.php,
 * Phase 12). This case is purely additive; every existing case and
 * value above is unchanged.
 */
enum InvoiceLineType: string
{
    case TimeEntry = 'time_entry';
    case FlatFee = 'flat_fee';
    case ManualCharge = 'manual_charge';
    case Adjustment = 'adjustment';
    case ReimbursableExpense = 'reimbursable_expense';
}
