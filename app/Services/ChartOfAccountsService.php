<?php

namespace App\Services;

use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Exceptions\AccountingSetupIncompleteException;
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
    public function __construct(private readonly AccountingEntitlementPolicyService $entitlementPolicy) {}

    public function create(Firm $firm, string $accountCode, string $accountName, ChartOfAccountType $accountType, ?ChartOfAccountPurpose $purpose = null): ChartOfAccount
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);

        return (new TenantContextService)->runWithFirmContext($firm, fn () => ChartOfAccount::create([
            'firm_id' => $firm->id,
            'account_code' => $accountCode,
            'account_name' => $accountName,
            'account_type' => $accountType,
            'purpose' => $purpose,
            'is_active' => true,
        ]));
    }

    public function deactivate(Firm $firm, ChartOfAccount $account): ChartOfAccount
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($account) {
            $account->update(['is_active' => false]);

            return $account->fresh();
        });
    }

    /**
     * Read-only resolution of "the" active account of a given type for
     * a firm — the same one-active-account-per-type convention
     * AccountingExportLineBuilderService::resolveActiveAccountByType()
     * already established privately for QuickBooks export line
     * mapping. Exposed here as the single canonical, public
     * implementation so the new journal-wiring layer (Phase D) doesn't
     * duplicate that query. Returns null (never throws) when the firm
     * has not yet set up an active account of this type — callers
     * decide whether that means "skip posting" or "hard error."
     */
    public function resolveActiveAccountByType(Firm $firm, ChartOfAccountType $type): ?ChartOfAccount
    {
        return (new TenantContextService)->runWithFirmContext($firm, fn () => ChartOfAccount::query()
            ->where('firm_id', $firm->id)
            ->where('account_type', $type)
            ->where('is_active', true)
            ->orderBy('id')
            ->first());
    }

    /**
     * Accounting Integrity Hardening Pass, item 2 — the unambiguous
     * replacement for resolveActiveAccountByType() everywhere a posting
     * service needs a SPECIFIC canonical account (e.g. "the" operating
     * cash account), not merely "some account of this type." Never
     * "arbitrarily selects the first account of a type" — the partial
     * unique index on (firm_id, purpose) added alongside this column
     * guarantees at most one active row can ever claim a given purpose
     * for a firm, so this query can return at most one row by
     * construction, not merely by convention.
     */
    public function resolveByPurpose(Firm $firm, ChartOfAccountPurpose $purpose): ?ChartOfAccount
    {
        return (new TenantContextService)->runWithFirmContext($firm, fn () => ChartOfAccount::query()
            ->where('firm_id', $firm->id)
            ->where('purpose', $purpose)
            ->where('is_active', true)
            ->first());
    }

    /**
     * The throwing counterpart to resolveByPurpose(), and the ONLY
     * account-resolution method a money-changing posting call site
     * (OperatingJournalRecorderService) may use — see
     * AccountingSetupIncompleteException's own docblock for the full
     * atomic-rollback policy this enables. Never used by a read-only
     * reporting method (AccountingEarnedFeeService,
     * AccountingPeriodCloseService), which must stay gracefully
     * nullable — those compute a figure, they never gate a business
     * transaction.
     */
    public function requireByPurpose(Firm $firm, ChartOfAccountPurpose $purpose): ChartOfAccount
    {
        $account = $this->resolveByPurpose($firm, $purpose);

        if ($account === null) {
            throw new AccountingSetupIncompleteException(
                $purpose,
                "This firm's Chart of Accounts is missing an active account for the required purpose [{$purpose->value}]. Configure one before this action can complete."
            );
        }

        return $account;
    }
}
