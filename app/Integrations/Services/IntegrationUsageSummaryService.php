<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Data\IntegrationUsageSummary;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationUsageRecord;
use App\Services\TenantContextService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * IntegrationUsageSummaryService — Checkpoint 10 (frozen-design-post-
 * security-review.md §6, §12; agent-10h-architecture-security-review.md
 * §5). Read-only. NOT a writer of `integration_usage_records` — that
 * remains exclusively `IntegrationUsageRecorderService::recordOnce()`
 * (Checkpoint 9), whose write-path wiring into the sync/outbox/webhook
 * pipeline is explicitly OUT of Checkpoint 10 scope (frozen design §6):
 * this service only aggregates whatever rows already exist, which is
 * genuinely zero in every environment today. `IntegrationUsagePage`
 * (the standalone Filament page this service backs) must render an
 * honest empty state ("No usage has been recorded for this connection
 * yet") rather than "$0 used," which would imply a real zero rather
 * than an absence of measurement.
 *
 * Mirrors `HealthStateService::summariesForFirm()`'s exact convention:
 * an explicit `runWithFirmContext()` wrap even though ambient tenant
 * context is normally already active for any caller reached through the
 * firm panel — defensive, not redundant.
 */
final class IntegrationUsageSummaryService
{
    /**
     * @return Collection<int, IntegrationUsageSummary>
     */
    public function summariesForFirm(int $firmId): Collection
    {
        return (new TenantContextService())->runWithFirmContext($firmId, function () use ($firmId) {
            $rows = IntegrationUsageRecord::query()
                ->selectRaw(
                    'firm_integration_id, provider_key, capability, operation_type, direction, unit, '.
                    'SUM(quantity) as total_quantity, MIN(occurred_at) as first_occurred_at, MAX(occurred_at) as last_occurred_at'
                )
                ->where('firm_id', $firmId)
                ->groupBy('firm_integration_id', 'provider_key', 'capability', 'operation_type', 'direction', 'unit')
                ->orderBy('provider_key')
                ->orderBy('capability')
                ->get();

            $connectionLabels = FirmIntegration::query()
                ->whereIn('id', $rows->pluck('firm_integration_id')->unique())
                ->pluck('display_label', 'id');

            return $rows->map(function ($row) use ($connectionLabels) {
                $firmIntegrationId = (int) $row->firm_integration_id;

                return new IntegrationUsageSummary(
                    firmIntegrationId: $firmIntegrationId,
                    connectionLabel: $connectionLabels[$firmIntegrationId] ?? "Connection #{$firmIntegrationId}",
                    providerKey: $row->provider_key,
                    capability: $row->capability,
                    operationType: $row->operation_type,
                    direction: $row->direction,
                    totalQuantity: (int) $row->total_quantity,
                    unit: $row->unit,
                    firstOccurredAt: $row->first_occurred_at !== null ? Carbon::parse($row->first_occurred_at) : null,
                    lastOccurredAt: $row->last_occurred_at !== null ? Carbon::parse($row->last_occurred_at) : null,
                );
            })->values();
        });
    }
}
