<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\ProviderHardLimitExceededException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\ProviderBillableCallReservation;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;

/**
 * ProviderUsageLimitEnforcementService — pipeline step 11
 * (checkpoint4-design-cost-control.md §2 step 11). Sums FINALIZED usage
 * (`integration_usage_records`, filtered `provider_key`/`capability`/
 * `outcome='success'`/period) PLUS currently-`reserved` reservations
 * for the same period — the reservation-INCLUSIVE sum is required
 * precisely because a burst of concurrent requests must not all pass a
 * plain "usage so far" check before any of them finalizes (the classic
 * TOCTOU gap a reservation pattern exists to close).
 *
 * Soft limit breach -> returns `true` (caller flags
 * `softLimitExceeded` on the pipeline result, never blocks). Hard limit
 * breach -> throws `ProviderHardLimitExceededException`, no reservation
 * is created for this call.
 */
final class ProviderUsageLimitEnforcementService
{
    /**
     * @return bool true when the soft limit (not the hard limit) would
     *              be exceeded by this call.
     */
    public function assertWithinLimits(
        Firm $firm,
        FirmIntegration $connection,
        ProviderKey $providerKey,
        ProviderBillingClassification $classification,
        ProviderOperationPolicy $policy,
        int $quantity,
    ): bool {
        if ($policy->softLimitQuantity === null && $policy->hardLimitQuantity === null) {
            return false;
        }

        $currentTotal = $this->currentPeriodTotal($firm, $connection, $providerKey, $classification, $policy);

        $attemptedTotal = $currentTotal + $quantity;

        if ($policy->hardLimitQuantity !== null && $attemptedTotal > $policy->hardLimitQuantity) {
            throw new ProviderHardLimitExceededException($policy->hardLimitQuantity, $attemptedTotal);
        }

        return $policy->softLimitQuantity !== null && $attemptedTotal > $policy->softLimitQuantity;
    }

    /**
     * Read-only preview of the current period's finalized-plus-reserved
     * total, with no enforcement — used by
     * `App\Integrations\Billing\ProviderLiveBalanceConfirmationService::prepare()`
     * to compute `includedAllowanceRemaining`/`isOverage` for display
     * WITHOUT throwing, since `prepare()` runs the pipeline's steps in
     * preview mode and must never block on a limit that only really gets
     * enforced when `confirm()` later runs the full pipeline.
     */
    public function currentPeriodTotal(
        Firm $firm,
        FirmIntegration $connection,
        ProviderKey $providerKey,
        ProviderBillingClassification $classification,
        ProviderOperationPolicy $policy,
    ): int {
        $windowStart = now()->subSeconds($policy->limitWindowSeconds);

        return (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $connection, $providerKey, $classification, $windowStart) {
            $finalizedTotal = (int) DB::table('integration_usage_records')
                ->where('firm_id', $firm->id)
                ->where('firm_integration_id', $connection->id)
                ->where('provider_key', $providerKey->value)
                ->where('capability', $classification->capability())
                ->where('outcome', 'success')
                ->where('occurred_at', '>=', $windowStart)
                ->sum('quantity');

            $reservedTotal = (int) ProviderBillableCallReservation::query()
                ->where('firm_id', $firm->id)
                ->where('firm_integration_id', $connection->id)
                ->where('provider_key', $providerKey->value)
                ->where('product', $classification->product)
                ->where('billing_operation', $classification->billingOperation)
                ->where('status', ProviderBillableCallReservation::STATUS_RESERVED)
                ->where('reserved_at', '>=', $windowStart)
                ->sum('quantity');

            return $finalizedTotal + $reservedTotal;
        });
    }
}
