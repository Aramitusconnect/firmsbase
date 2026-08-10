<?php

namespace App\Enums;

/**
 * MatterBudgetExpenseCategory — Predictive Matter Budget Alerts, item
 * 3/6. The closed vocabulary matter_budget_templates.expected_expenses_json
 * and matter_budgets.expected_expenses_json may use as keys — the
 * exact four named expense categories the master spec lists.
 * Deliberately NOT the same vocabulary as ExpenseCategory (a
 * Firm-defined, open, chart-of-accounts-linked catalog reused by the
 * canonical Expense domain) — a budget template expresses an
 * EXPECTATION at a coarser, closed granularity than a Firm's own
 * bespoke expense categories, and MatterBudgetAnalysisService maps
 * actual Expense rows into these four buckets (see that service's own
 * docblock for the mapping rule) rather than the budget depending on
 * exactly which ExpenseCategory rows a Firm happens to have configured.
 */
enum MatterBudgetExpenseCategory: string
{
    case FilingCourtCosts = 'filing_court_costs';
    case VendorExpertCosts = 'vendor_expert_costs';
    case ReimbursableCosts = 'reimbursable_costs';
    case OtherExpenses = 'other_expenses';
}
