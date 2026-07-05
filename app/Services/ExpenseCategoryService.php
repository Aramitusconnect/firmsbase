<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\ExpenseCategory;
use App\Models\Firm;

/**
 * ExpenseCategoryService — the only writer of expense_categories.
 * firm_id is always the caller's firm (correction #3: no platform-
 * global categories in Phase 12). Every write is gated on the expenses
 * entitlement first.
 */
class ExpenseCategoryService
{
    public function __construct(
        private readonly AccountingEntitlementPolicyService $entitlementPolicy,
        private readonly TenantSafeAccountingPolicyService $tenantSafePolicy,
    ) {
    }

    public function create(Firm $firm, string $name, ?ChartOfAccount $chartOfAccount = null): ExpenseCategory
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);

        if ($chartOfAccount) {
            $this->tenantSafePolicy->assertChartOfAccountBelongsToFirm($chartOfAccount, $firm);
        }

        return ExpenseCategory::create([
            'firm_id' => $firm->id,
            'chart_of_accounts_id' => $chartOfAccount?->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    public function mapToChartOfAccount(Firm $firm, ExpenseCategory $category, ChartOfAccount $chartOfAccount): ExpenseCategory
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);
        $this->tenantSafePolicy->assertExpenseCategoryBelongsToFirm($category, $firm);
        $this->tenantSafePolicy->assertChartOfAccountBelongsToFirm($chartOfAccount, $firm);

        $category->update(['chart_of_accounts_id' => $chartOfAccount->id]);

        return $category->fresh();
    }

    public function deactivate(Firm $firm, ExpenseCategory $category): ExpenseCategory
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);
        $this->tenantSafePolicy->assertExpenseCategoryBelongsToFirm($category, $firm);

        $category->update(['is_active' => false]);

        return $category->fresh();
    }
}
