<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\ExpenseCategory;
use App\Models\Firm;
use InvalidArgumentException;

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
    ) {}

    public function create(Firm $firm, string $name, ?ChartOfAccount $chartOfAccount = null): ExpenseCategory
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);

        if ($chartOfAccount) {
            $this->tenantSafePolicy->assertChartOfAccountBelongsToFirm($chartOfAccount, $firm);
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $name, $chartOfAccount) {
            $this->assertNameIsUniqueWithinFirm($firm, $name);

            return ExpenseCategory::create([
                'firm_id' => $firm->id,
                'chart_of_accounts_id' => $chartOfAccount?->id,
                'name' => $name,
                'is_active' => true,
            ]);
        });
    }

    /**
     * FirmsVault staging follow-up addition ("Application Completion —
     * Catalogs + Firm-Owned Reference Data"). Renames an existing
     * category — the only field a Firm Management "Expense Categories"
     * page needs to edit beyond chart-of-accounts mapping (already
     * covered by mapToChartOfAccount()) and active status (already
     * covered by deactivate()/reactivate()).
     */
    public function update(Firm $firm, ExpenseCategory $category, string $name): ExpenseCategory
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);
        $this->tenantSafePolicy->assertExpenseCategoryBelongsToFirm($category, $firm);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $category, $name) {
            if (strcasecmp($name, $category->name) !== 0) {
                $this->assertNameIsUniqueWithinFirm($firm, $name, excludingId: $category->id);
            }

            $category->update(['name' => $name]);

            return $category->fresh();
        });
    }

    public function mapToChartOfAccount(Firm $firm, ExpenseCategory $category, ChartOfAccount $chartOfAccount): ExpenseCategory
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);
        $this->tenantSafePolicy->assertExpenseCategoryBelongsToFirm($category, $firm);
        $this->tenantSafePolicy->assertChartOfAccountBelongsToFirm($chartOfAccount, $firm);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($category, $chartOfAccount) {
            $category->update(['chart_of_accounts_id' => $chartOfAccount->id]);

            return $category->fresh();
        });
    }

    public function deactivate(Firm $firm, ExpenseCategory $category): ExpenseCategory
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);
        $this->tenantSafePolicy->assertExpenseCategoryBelongsToFirm($category, $firm);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($category) {
            $category->update(['is_active' => false]);

            return $category->fresh();
        });
    }

    /**
     * FirmsVault staging follow-up addition. The inverse of
     * deactivate() — never a hard delete anywhere in this service, so a
     * mistaken deactivation must be reversible without recreating the
     * row (which would silently orphan every Expense that already
     * references the original category id).
     */
    public function reactivate(Firm $firm, ExpenseCategory $category): ExpenseCategory
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);
        $this->tenantSafePolicy->assertExpenseCategoryBelongsToFirm($category, $firm);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($category) {
            $category->update(['is_active' => true]);

            return $category->fresh();
        });
    }

    private function assertNameIsUniqueWithinFirm(Firm $firm, string $name, ?int $excludingId = null): void
    {
        $query = ExpenseCategory::query()
            ->where('firm_id', $firm->id)
            ->whereRaw('lower(name) = ?', [strtolower($name)]);

        if ($excludingId !== null) {
            $query->whereKeyNot($excludingId);
        }

        if ($query->exists()) {
            throw new InvalidArgumentException("An expense category named \"{$name}\" already exists for this firm.");
        }
    }
}
