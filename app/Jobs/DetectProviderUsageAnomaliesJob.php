<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\ProviderUsageAnomalyDetectionService;
use App\Models\Firm;
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * DetectProviderUsageAnomaliesJob — the scheduled (daily) driver for
 * `App\Integrations\Services\ProviderUsageAnomalyDetectionService::evaluate()`
 * (checkpoint4-design-cost-control.md §7). Iterates every ACTIVE Plaid
 * connection, calling `evaluate()` per firm/product. On a detected
 * anomaly, records `provider_billing.anomaly_detected` via the EXISTING
 * `TimelineEventRecorder` (firm-scoped audit trail, reused exactly as
 * every other governance-event caller in this codebase already does —
 * no new audit mechanism invented).
 *
 * The design's own secondary "cross-firm-significant anomaly" dual-
 * channel step — an ADDITIONAL call through the existing
 * `App\Services\PlatformAdminAuditEventRecorder` (mirroring
 * `EntitlementOverrideService::setOverrideAsPlatformAdmin()`'s own
 * firm-scoped-plus-platform-scoped pattern) — is NOT implemented here:
 * `PlatformAdminAuditEventRecorder::record()` requires a real
 * `PlatformAdmin` actor (confirmed by reading its signature), and a
 * system-triggered scheduled job has no natural PlatformAdmin to
 * attribute the write to; the design also gives no concrete threshold
 * for what counts as "cross-firm-significant." Flagged here rather than
 * guessed at — a later checkpoint/implementer with a concrete threshold
 * and a system-actor convention for `security_events` should close this
 * gap deliberately, not have it silently invented by this pass.
 *
 * `product` iteration uses the same closed vocabulary
 * `provider_rate_card_entries.product` documents
 * (`App\Integrations\Billing\ProviderBillingClassifier`'s own product
 * list) — every product a Plaid connection could plausibly be billed
 * for, evaluated independently per connection's firm.
 */
final class DetectProviderUsageAnomaliesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const PLAID_PROVIDER_KEY = 'plaid';

    private const PRODUCTS = [
        'item', 'transactions', 'balance', 'auth', 'identity', 'identity_match',
        'liabilities', 'investments', 'income', 'statements', 'enrich',
        'identity_verification', 'monitor',
    ];

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('detect-provider-usage-anomalies'))->releaseAfter(300)->expireAfter(3600),
        ];
    }

    public function handle(ProviderUsageAnomalyDetectionService $detector, TimelineEventRecorder $events): void
    {
        Firm::query()->select('id')->orderBy('id')->chunkById(200, function ($firms) use ($detector, $events) {
            foreach ($firms as $firm) {
                // $firm->id, not $firm -- see ExpireStaleProviderReservationsJob's
                // identical fix/comment for the full reasoning (a
                // ->select('id')-only partial model breaks
                // runWithFirmContext()'s internal handling of a Firm
                // instance).
                (new TenantContextService)->runWithFirmContext($firm->id, function () use ($firm, $detector, $events) {
                    $connections = FirmIntegration::query()
                        ->where('firm_id', $firm->id)
                        ->where('status', ConnectionStatus::Active)
                        ->whereHas('integrationProvider', fn ($q) => $q->where('code', self::PLAID_PROVIDER_KEY))
                        ->get();

                    if ($connections->isEmpty()) {
                        return;
                    }

                    foreach (self::PRODUCTS as $product) {
                        $anomaly = $detector->evaluate($firm, self::PLAID_PROVIDER_KEY, $product);

                        if ($anomaly === null) {
                            continue;
                        }

                        $events->record($firm, 'provider_billing.anomaly_detected', null, null, [
                            'reference' => (string) Str::uuid7(),
                            'provider_key' => $anomaly->providerKey,
                            'product' => $anomaly->product,
                            'current_window_count' => $anomaly->currentWindowCount,
                            'baseline_daily_average' => $anomaly->baselineDailyAverage,
                            'cold_start' => $anomaly->coldStart,
                        ], independentOfAmbientTransaction: true);
                    }
                });
            }
        });
    }
}
