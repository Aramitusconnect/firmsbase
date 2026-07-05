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
 */
class ChartOfAccountsService
{
    public function __construct(private readonly AccountingEntitlementPolicyService $entitlementPolicy)
    {
    }

    public function create(Firm $firm, string $accountCode, string $accountName, ChartOfAccountType $accountType): ChartOfAccount
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);

        return ChartOfAccount::create([
            'firm_id' => $firm->id,
            'account_code' => $accountCode,
            'account_name' => $accountName,
            'account_type' => $accountType,
            'is_active' => true,
        ]);
    }

    public function deactivate(Firm $firm, ChartOfAccount $account): ChartOfAccount
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);

        $account->update(['is_active' => false]);

        return $account->fresh();
    }
}
