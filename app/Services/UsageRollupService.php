<?php

namespace App\Services;

use App\Enums\UsageRollupMetric;
use App\Models\BillingAccount;
use App\Models\Firm;
use App\Models\UsageRollup;

/**
 * UsageRollupService — the only place usage_rollups rows are created.
 * Keyed to billing_account_id (project rule 11) with optional per-firm
 * attribution. checkBudgetLimit() supports organization-level budget
 * limits for AI/storage/seats by summing every firm-attributed row for
 * the account/metric/period (a null-firm_id row, if present, is treated
 * as the pre-aggregated account-level total and used instead of
 * re-summing, to avoid double counting).
 */
class UsageRollupService
{
    public function recordUsage(
        BillingAccount $billingAccount,
        ?Firm $firm,
        UsageRollupMetric $metric,
        int $quantity,
        \DateTimeInterface $periodStartsAt,
        \DateTimeInterface $periodEndsAt,
        ?string $unit = null,
    ): UsageRollup {
        return UsageRollup::create([
            'billing_account_id' => $billingAccount->id,
            'firm_id' => $firm?->id,
            'metric' => $metric,
            'period_starts_at' => $periodStartsAt,
            'period_ends_at' => $periodEndsAt,
            'quantity' => $quantity,
            'unit' => $unit,
        ]);
    }

    public function totalForMetric(
        BillingAccount $billingAccount,
        UsageRollupMetric $metric,
        \DateTimeInterface $periodStartsAt,
        \DateTimeInterface $periodEndsAt,
    ): int {
        $query = UsageRollup::query()
            ->where('billing_account_id', $billingAccount->id)
            ->where('metric', $metric->value)
            ->where('period_starts_at', '>=', $periodStartsAt)
            ->where('period_ends_at', '<=', $periodEndsAt);

        $accountLevelTotal = (clone $query)->whereNull('firm_id')->sum('quantity');

        if ($accountLevelTotal > 0) {
            return (int) $accountLevelTotal;
        }

        return (int) (clone $query)->whereNotNull('firm_id')->sum('quantity');
    }

    public function isWithinBudget(
        BillingAccount $billingAccount,
        UsageRollupMetric $metric,
        int $budgetLimit,
        \DateTimeInterface $periodStartsAt,
        \DateTimeInterface $periodEndsAt,
    ): bool {
        return $this->totalForMetric($billingAccount, $metric, $periodStartsAt, $periodEndsAt) <= $budgetLimit;
    }
}
