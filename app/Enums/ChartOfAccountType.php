<?php

namespace App\Enums;

/**
 * ChartOfAccountType — standard accounting account classes. Phase 12
 * actively maps expense_categories and accounting_export_lines against
 * Expense-type (and, for invoice/payment export lines, Revenue/Asset-
 * type) accounts; Liability/Equity exist so chart_of_accounts is a real
 * chart-of-accounts foundation (per the approved goal wording), not an
 * expense-only lookup table, even though Phase 12 itself only reads
 * Expense/Revenue/Asset rows.
 */
enum ChartOfAccountType: string
{
    case Expense = 'expense';
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
}
