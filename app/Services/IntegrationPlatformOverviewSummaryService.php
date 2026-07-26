<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\Data\ConnectionHealthSummary;
use App\Integrations\Enums\ConflictStatus;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\HealthSummaryState;
use App\Integrations\Enums\OutboxEventStatus;
use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Enums\SyncRunStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Services\HealthStateService;
use App\Models\Firm;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * IntegrationPlatformOverviewSummaryService — Checkpoint 11 (frozen-
 * design-post-security-review.md §5). The ONE, sole writer of
 * `integration_platform_overview_summaries` — an upsert-only refresh,
 * never a partial/incremental update. Called exclusively by
 * App\Jobs\RefreshIntegrationPlatformOverviewSummaryJob (one job per
 * activated firm, scheduled via the
 * `integrations:platform-overview:refresh` console command).
 *
 * refreshForFirm() reads the firm's real, FORCE-RLS-protected tenant
 * tables inside a single TenantContextService::runWithFirmContext() call
 * (reusing HealthStateService::summariesForFirm() plus scoped queries,
 * per frozen design §5), then writes the resulting summary row OUTSIDE
 * any tenant context — the target table carries no RLS at all, so the
 * write needs none (see the create migration's own "WHY THIS TABLE HAS
 * NO RLS AND NO FORCE RLS" docblock).
 */
final class IntegrationPlatformOverviewSummaryService
{
    /**
     * Worst-to-best severity ordering used to pick the single, most-
     * severe HealthSummaryState across every connection this firm has a
     * recorded integration_connection_health row for. A firm with zero
     * such rows (e.g. no connections yet) yields a null
     * health_summary_state — never a fabricated "healthy" default.
     */
    private const HEALTH_SEVERITY_ORDER = [
        HealthSummaryState::Unavailable->value => 0,
        HealthSummaryState::ActionRequired->value => 1,
        HealthSummaryState::Degraded->value => 2,
        HealthSummaryState::Healthy->value => 3,
    ];

    public function __construct(
        private readonly HealthStateService $healthState,
        private readonly IntegrationEntitlementPolicyService $entitlement,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function refreshForFirm(Firm $firm): void
    {
        $summary = $this->tenantContext->runWithFirmContext($firm, fn (): array => $this->computeForFirm($firm));

        $this->writeSummaryRow($firm, $summary);
    }

    /**
     * @return array<string, mixed>
     */
    private function computeForFirm(Firm $firm): array
    {
        $connections = FirmIntegration::query()
            ->where('firm_id', $firm->id)
            ->get(['id', 'status']);

        $connectionCountActive = $connections->filter(
            fn (FirmIntegration $connection): bool => $connection->status === ConnectionStatus::Active
        )->count();

        $connectionCountDisconnected = $connections->filter(
            fn (FirmIntegration $connection): bool => $connection->status === ConnectionStatus::Disconnected
        )->count();

        $connectionCountOther = $connections->count() - $connectionCountActive - $connectionCountDisconnected;

        $healthSummaryState = $this->mostSevereHealthState(
            $this->healthState->summariesForFirm($firm->id)
        );

        $latestSyncRun = IntegrationSyncRun::query()
            ->where('firm_id', $firm->id)
            ->orderByDesc('created_at')
            ->first(['status', 'started_at', 'finished_at', 'created_at']);

        // Phase 2 UI-building pass addition: unlike $latestSyncRun above
        // (the most recent run regardless of outcome — "last sync
        // ATTEMPT at"), this is explicitly filtered to
        // SyncRunStatus::Succeeded only, so
        // `last_successful_sync_at` is an honest "last time a sync for
        // this firm actually succeeded" signal, never conflated with a
        // recent failed/partial attempt. Ordered/tie-broken the same
        // way as $latestSyncRun (created_at desc, id desc) for
        // deterministic selection when two runs share a created_at.
        $latestSucceededSyncRun = IntegrationSyncRun::query()
            ->where('firm_id', $firm->id)
            ->where('status', SyncRunStatus::Succeeded->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['finished_at', 'started_at', 'created_at']);

        $failedPermanentSyncItemCount = IntegrationSyncItem::query()
            ->where('firm_id', $firm->id)
            ->where('status', SyncItemStatus::FailedPermanent->value)
            ->count();

        $deadLetteredOutboxEventCount = IntegrationOutboxEvent::query()
            ->where('firm_id', $firm->id)
            ->where('status', OutboxEventStatus::DeadLettered->value)
            ->count();

        $openConflictCount = IntegrationConflict::query()
            ->where('firm_id', $firm->id)
            ->whereIn('status', array_map(fn (ConflictStatus $status): string => $status->value, ConflictStatus::openStates()))
            ->count();

        return [
            'connection_count_active' => $connectionCountActive,
            'connection_count_disconnected' => $connectionCountDisconnected,
            'connection_count_other' => max(0, $connectionCountOther),
            'health_summary_state' => $healthSummaryState,
            'last_sync_outcome' => $latestSyncRun?->status?->value,
            'last_sync_at' => $latestSyncRun?->finished_at ?? $latestSyncRun?->started_at ?? $latestSyncRun?->created_at,
            'last_successful_sync_at' => $latestSucceededSyncRun?->finished_at ?? $latestSucceededSyncRun?->started_at ?? $latestSucceededSyncRun?->created_at,
            'failed_permanent_sync_item_count' => $failedPermanentSyncItemCount,
            'dead_lettered_outbox_event_count' => $deadLetteredOutboxEventCount,
            'open_conflict_count' => $openConflictCount,
            'entitlement_enabled' => $this->entitlement->isEnabled($firm),
        ];
    }

    /**
     * @param  Collection<int, ConnectionHealthSummary>  $summaries
     */
    private function mostSevereHealthState(Collection $summaries): ?string
    {
        if ($summaries->isEmpty()) {
            return null;
        }

        return $summaries
            ->map(fn ($summary): string => $summary->summaryState->value)
            ->sortBy(fn (string $state): int => self::HEALTH_SEVERITY_ORDER[$state] ?? 99)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function writeSummaryRow(Firm $firm, array $summary): void
    {
        $now = now();

        DB::table('integration_platform_overview_summaries')->upsert(
            [array_merge($summary, [
                'firm_id' => $firm->id,
                'firm_uuid' => $firm->uuid,
                'computed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])],
            uniqueBy: ['firm_id'],
            update: [
                'firm_uuid',
                'connection_count_active',
                'connection_count_disconnected',
                'connection_count_other',
                'health_summary_state',
                'last_sync_outcome',
                'last_sync_at',
                'last_successful_sync_at',
                'failed_permanent_sync_item_count',
                'dead_lettered_outbox_event_count',
                'open_conflict_count',
                'entitlement_enabled',
                'computed_at',
                'updated_at',
            ],
        );
    }
}
