<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterExpense;

/**
 * MatterExpenseService — the only writer of matter_expenses. Enforces
 * same-firm (matter and expense must belong to the same firm — required
 * test) and freezes expense.reimbursable into reimbursable_snapshot at
 * link time, so a later category/expense-level change cannot
 * retroactively alter an already-linked expense's invoice-eligibility
 * history.
 */
class MatterExpenseService
{
    public function __construct(
        private readonly AccountingEntitlementPolicyService $entitlementPolicy,
        private readonly TenantSafeAccountingPolicyService $tenantSafePolicy,
    ) {
    }

    public function link(Firm $firm, Matter $matter, Expense $expense): MatterExpense
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);
        $this->tenantSafePolicy->assertExpenseBelongsToFirm($expense, $firm);

        if ($matter->firm_id !== $firm->id) {
            throw new \RuntimeException('Matter does not belong to this firm.');
        }

        $this->tenantSafePolicy->assertMatterAndExpenseShareFirm($matter, $expense);

        if ($expense->matterExpense()->exists()) {
            throw new \RuntimeException('This expense is already linked to a matter.');
        }

        return MatterExpense::create([
            'firm_id' => $firm->id,
            'matter_id' => $matter->id,
            'expense_id' => $expense->id,
            'reimbursable_snapshot' => $expense->reimbursable,
        ]);
    }
}
