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
 *
 * expense_categories now has permanent FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_08_27_950019_prepare_row_level_security_and_
 * force_rls_on_expense_categories_table.php), so every real DB write
 * below runs inside its own runWithFirmContext() call. The entitlement
 * check and every pure in-memory assert*() call stay OUTSIDE every
 * wrap, unchanged — see ExpenseService's own docblock for the full
 * decoy-wrap rationale.
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

        return (new TenantContextService())->runWithFirmContext($firm, fn () => ExpenseCategory::create([
            'firm_id' => $firm->id,
            'chart_of_accounts_id' => $chartOfAccount?->id,
            'name' => $name,
            'is_active' => true,
        ]));
    }

    public function mapToChartOfAccount(Firm $firm, ExpenseCategory $category, ChartOfAccount $chartOfAccount): ExpenseCategory
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);
        $this->tenantSafePolicy->assertExpenseCategoryBelongsToFirm($category, $firm);
        $this->tenantSafePolicy->assertChartOfAccountBelongsToFirm($chartOfAccount, $firm);

        return (new TenantContextService())->runWithFirmContext($firm, function () use ($category, $chartOfAccount) {
            $category->update(['chart_of_accounts_id' => $chartOfAccount->id]);

            return $category->fresh();
        });
    }

    public function deactivate(Firm $firm, ExpenseCategory $category): ExpenseCategory
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);
        $this->tenantSafePolicy->assertExpenseCategoryBelongsToFirm($category, $firm);

        return (new TenantContextService())->runWithFirmContext($firm, function () use ($category) {
            $category->update(['is_active' => false]);

            return $category->fresh();
        });
    }
}
