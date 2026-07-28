<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Data\ProviderUsageAnomaly;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ProviderUsageAnomalyDetectionService — checkpoint4-design-cost-control.md
 * §7. NOT one of the 17 numbered pipeline steps — a deliberate reading
 * of the spec's own structure (the enumerated pre-call pipeline is
 * exhaustive and does not list anomaly detection as a gate). Designed
 * as an ASYNCHRONOUS, SCHEDULED, OUT-OF-BAND service, not a synchronous
 * per-call block — a rolling-baseline comparison needs a real time
 * window of prior data to be meaningful, and a volume spike is not
 * inherently wrong (design's own example: a firm's legitimate year-end
 * trust reconciliation could spike Balance calls 5x for one week) — the
 * correct response is a human-reviewed alert, never an automatic block.
 *
 * Computed directly against the EXISTING, UNMODIFIED
 * `integration_usage_records` table (already keyed by
 * `provider_key`/`capability`/`occurred_at`) — no new usage table
 * needed. `evaluate()` self-wraps in `$firm`'s own tenant context, the
 * same established discipline every other per-firm service in this
 * codebase already uses, since `integration_usage_records` is Direct
 * `BelongsToTenant` + FORCE RLS.
 */
class ProviderUsageAnomalyDetectionService
{
    public function evaluate(Firm $firm, string $providerKey, string $product, ?Carbon $windowEnd = null): ?ProviderUsageAnomaly
    {
        $windowEnd ??= now();

        // Pass $firm->id, never the $firm object itself -- callers of
        // this method (e.g. DetectProviderUsageAnomaliesJob) commonly
        // enumerate firms via a partial ->select('id') query for memory
        // efficiency, and TenantContextService::runWithFirmContext()'s
        // internal handling of a Firm INSTANCE (as opposed to a plain
        // id) needs columns such a partial model never loaded. Every
        // other platform-wide sweep in this codebase (e.g.
        // RenewProviderWebhookSubscriptionsCommand) already establishes
        // "always pass the raw id, never a partial model" as the correct
        // discipline here.
        return (new TenantContextService)->runWithFirmContext($firm->id, function () use ($firm, $providerKey, $product, $windowEnd) {
            $currentWindowCount = $this->countInWindow($firm, $providerKey, $product, $windowEnd->clone()->subDay(), $windowEnd);
            $baselineTotal = $this->countInWindow($firm, $providerKey, $product, $windowEnd->clone()->subDays(30), $windowEnd->clone()->subDay());
            $baselineDailyAverage = $baselineTotal / 29;

            $multiplier = (float) config('integrations.provider_billing.anomaly_multiplier', 3);
            $coldStartCeiling = (int) config('integrations.provider_billing.anomaly_cold_start_ceiling', 200);

            if ($baselineDailyAverage > 0 && $currentWindowCount > $baselineDailyAverage * $multiplier) {
                return new ProviderUsageAnomaly($firm, $providerKey, $product, $currentWindowCount, $baselineDailyAverage);
            }

            // Deliberately NOT a strict === 0.0 comparison: $baselineTotal
            // is an int (from countInWindow()'s (int) cast), so
            // $baselineTotal / 29 for a genuinely brand-new firm/product
            // (0 / 29) evaluates to PHP's int(0), not float(0.0) -- a
            // strict === against the float literal 0.0 would never match
            // int(0), silently disabling the cold-start ceiling branch
            // for exactly the firms it exists to protect. <= 0.0 is
            // exact here (the numerator is a COUNT, never negative) and
            // type-coerces correctly for both int(0) and float(0.0).
            if ($baselineDailyAverage <= 0.0 && $currentWindowCount > $coldStartCeiling) {
                return new ProviderUsageAnomaly($firm, $providerKey, $product, $currentWindowCount, 0.0, coldStart: true);
            }

            return null;
        });
    }

    private function countInWindow(Firm $firm, string $providerKey, string $product, Carbon $from, Carbon $to): int
    {
        return (int) DB::table('integration_usage_records')
            ->where('firm_id', $firm->id)
            ->where('provider_key', $providerKey)
            ->where('capability', 'like', "{$product}:%")
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to)
            ->sum('quantity');
    }
}
