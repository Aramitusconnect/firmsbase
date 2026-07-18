<?php

namespace App\Services;

use App\Enums\ChartOfAccountType;
use App\Models\ChartOfAccount;
use App\Models\Firm;

/**
 * ChartOfAccountsService — the only writer of chart_of_accounts. No
 * starter/default rows are seeded anywhere in Phase 12 (correction #4)
 * — every row is created explicitly through this service; firms build
 * their own chart of accounts from nothing.
 *
 * chart_of_accounts now has permanent FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_08_27_950018_prepare_row_level_security_and_
 * force_rls_on_chart_of_accounts_table.php), so every real DB write
 * below runs inside its own runWithFirmContext() call. The entitlement
 * check stays OUTSIDE every wrap, unchanged — see ExpenseService's own
 * docblock for the full decoy-wrap rationale.
 */
class ChartOfAccountsService
{
    public function __construct(private readonly AccountingEntitlementPolicyService $entitlementPolicy)
    {
    }

    public function create(Firm $firm, string $accountCode, string $accountName, ChartOfAccountType $accountType): ChartOfAccount
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);

        return (new TenantContextService())->runWithFirmContext($firm, fn () => ChartOfAccount::create([
            'firm_id' => $firm->id,
            'account_code' => $accountCode,
            'account_name' => $accountName,
            'account_type' => $accountType,
            'is_active' => true,
        ]));
    }

    public function deactivate(Firm $firm, ChartOfAccount $account): ChartOfAccount
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);

        return (new TenantContextService())->runWithFirmContext($firm, function () use ($account) {
            $account->update(['is_active' => false]);

            return $account->fresh();
        });
    }
}
