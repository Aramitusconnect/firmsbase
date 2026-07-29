<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\Data\IntegrationUsageSummary;
use App\Integrations\Data\PlatformIntegrationConnectionSummary;
use App\Integrations\Enums\OutboxEventStatus;
use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\IntegrationUsageSummaryService;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\TimelineEvent;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * IntegrationPlatformOversightReadService — Checkpoint 11 (frozen-
 * design-post-security-review.md §7, §10, §12). The read-only aggregator
 * behind every Checkpoint 11 Filament page. Every per-firm method below
 * routes through PlatformFirmIntegrationBoundedAccessService::
 * readWithinFirmAccess() — the single chokepoint that enforces
 * role/support-access gating AND establishes tenant context — this
 * class never queries a FORCE-RLS tenant table on its own.
 *
 * Data-exposure discipline (frozen design §10) is enforced HERE, at the
 * read boundary, not left to the UI layer to remember:
 *   - `FirmIntegration.webhook_routing_token` is never selected/read by
 *     any method in this class.
 *   - `IntegrationOutboxEvent.last_error`/`IntegrationSyncItem.last_error`
 *     are never selected/read by any method in this class — only the
 *     governed `sanitized_diagnostic_summary`/`last_failure_category`
 *     columns on `integration_connection_health` are used for failure
 *     context.
 *   - `IntegrationConflict.resolution_note` is only included when
 *     PlatformFirmIntegrationBoundedAccessService::hasActiveSupportAccessSessionFor()
 *     is true for the exact firm — independent of, and never widened
 *     by, the coarser role-ceiling check.
 *   - `external_account_id` is always masked (see
 *     PlatformIntegrationConnectionSummary::maskExternalAccountId()).
 *   - `IntegrationConflict.local_value`/`external_value` are never
 *     selected/read anywhere in this class.
 *
 * Security review Finding 3 (CHECKPOINT_11_SECURITY_IMPLEMENTATION_REJECTED):
 * `PlatformStaffAccessPolicyService::canAccessPlatformBilling()`/
 * `canAccessSecurityLogs()` used to be checked ONLY inside
 * `PlatformFirmIntegrationDetailPage`'s Filament closures, never inside
 * this read service itself — the one inconsistency against every other
 * sensitive-field gate above (e.g. `resolution_note`'s active-session
 * check), which all live in this class. `usageForFirm()` now asserts
 * `canAccessPlatformBilling()` and `retentionConfigSummary()`/
 * `sanitizedAuditHistoryForFirm()` now assert `canAccessSecurityLogs()`
 * internally, throwing the same RuntimeException-with-decision-reason
 * shape `PlatformFirmIntegrationBoundedAccessService::
 * assertCanAccessOversight()` already uses for this checkpoint's other
 * authorization denials. The Filament page's own pre-existing checks are
 * kept as deliberate, documented belt-and-suspenders (see that class) —
 * they render a friendly in-page denial message; letting this service's
 * exception propagate there instead would surface as an unhandled error
 * rather than a graceful denial.
 */
final class IntegrationPlatformOversightReadService
{
    private const AUDIT_HISTORY_LIMIT = 100;

    private const SYNC_HISTORY_LIMIT = 25;

    private const FAILED_ITEMS_LIMIT = 200;

    private const CONFLICTS_LIMIT = 100;

    /**
     * Explicit column allowlist for every `FirmIntegration` read in this
     * class — `webhook_routing_token` is never selected at the SQL
     * level at all (frozen design §10 item 1), not merely omitted from
     * PlatformIntegrationConnectionSummary::fromModel()'s output. Also
     * deliberately excludes `error_reason` — this checkpoint does not
     * surface it (unlike Checkpoint 10's own firm-facing DTO), relying
     * only on `integration_connection_health`'s governed
     * `sanitized_diagnostic_summary`/`last_failure_category` columns for
     * failure context instead.
     *
     * @var array<int, string>
     */
    private const CONNECTION_COLUMNS = [
        'id', 'uuid', 'firm_id', 'integration_provider_id', 'external_account_id',
        'display_label', 'status', 'connected_at', 'disconnected_at',
    ];

    public function __construct(
        private readonly PlatformFirmIntegrationBoundedAccessService $boundedAccess,
        private readonly HealthStateService $healthState,
        private readonly IntegrationUsageSummaryService $usageSummary,
        private readonly PlatformStaffAccessPolicyService $staffAccess,
    ) {}

    /**
     * The always-visible, aggregate/sanitized cross-firm overview — no
     * support-access grant required (frozen design §2 item 3), only the
     * coarse role-level gate. Reads the no-RLS
     * `integration_platform_overview_summaries` snapshot table directly
     * — never a live cross-firm query against any FORCE-RLS tenant
     * table.
     *
     * Phase 2 query-hardening fix: an explicit `orderBy('id')`
     * tie-breaker follows `orderBy('firm_uuid')` so two rows that could
     * ever compare equal on firm_uuid (never true today — firm_uuid is
     * unique per row — but the ordering is made structurally
     * deterministic regardless of that incidental fact, matching every
     * other tie-breaker fix in this class) always produce a stable,
     * repeatable order across identical calls.
     *
     * KNOWN, DEFERRED GAP (Phase 2 investigation finding, not fixed
     * here): this method still reads the entire table into a Collection
     * with no LIMIT — PlatformIntegrationOverviewPage's `->records()`
     * closure is backed by a raw Collection (not an Eloquent query), so
     * genuine DB-level pagination requires a page-level rework (having
     * the closure accept the injected `page`/`recordsPerPage` Filament
     * parameters and returning a real paginator) in addition to a
     * service-level change here. That page-level rework is left for the
     * dedicated Phase 2 UI-building pass — this fix intentionally
     * addresses ONLY the ordering non-determinism, not the missing
     * bound, to avoid a half-fixed pagination contract landing without
     * its matching UI change.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function overviewSummaries(PlatformAdmin $admin): Collection
    {
        $this->boundedAccess->assertCanAccessOversight($admin);

        return DB::table('integration_platform_overview_summaries')
            ->orderBy('firm_uuid')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->values();
    }

    /**
     * Platform-wide, ALWAYS-VISIBLE (same coarse gate as
     * overviewSummaries() — no support-access grant required) usage-record
     * summary. Added during Checkpoint 6's cross-provider ops review:
     * `ProviderRequestExecutor::send()` now calls
     * `IntegrationUsageRecorderService::recordOnce()` for every provider
     * call, which made `PlatformIntegrationUsagePage`'s "no usage-metering
     * system is wired up" disclosure stale — real rows exist today.
     *
     * Loops over every firm with recorded integration activity (the same
     * firm set `overviewSummaries()` itself already surfaces from the
     * no-RLS snapshot table), calling the existing, already-tenant-
     * context-wrapped `IntegrationUsageSummaryService::summariesForFirm()`
     * once per firm — never a new cross-firm query against the FORCE-RLS
     * `integration_usage_records` table, mirroring
     * `PlatformFirmUserDirectoryService::countAll()`'s own established
     * per-firm-loop idiom for exactly this class of problem.
     *
     * `IntegrationUsageSummary` carries no billing/cost figure and no raw
     * provider payload (see `usageForFirm()`'s own docblock above) — this
     * summary is strictly sanitized quantity/unit/timestamp aggregates.
     *
     * @return array{total_records: int, firms_with_usage: int, by_provider: array<string, int>, earliest_occurred_at: ?string, latest_occurred_at: ?string}
     */
    public function usageRecordSummaryAcrossFirms(PlatformAdmin $admin): array
    {
        $this->boundedAccess->assertCanAccessOversight($admin);

        $firmIds = DB::table('integration_platform_overview_summaries')->pluck('firm_id');

        $totalRecords = 0;
        $firmsWithUsage = 0;
        $byProvider = [];
        $earliest = null;
        $latest = null;

        foreach ($firmIds as $firmId) {
            $summaries = $this->usageSummary->summariesForFirm((int) $firmId);

            if ($summaries->isEmpty()) {
                continue;
            }

            $firmsWithUsage++;

            foreach ($summaries as $summary) {
                $totalRecords += $summary->totalQuantity;
                $byProvider[$summary->providerKey] = ($byProvider[$summary->providerKey] ?? 0) + $summary->totalQuantity;

                if ($summary->firstOccurredAt !== null && ($earliest === null || $summary->firstOccurredAt->lt($earliest))) {
                    $earliest = $summary->firstOccurredAt;
                }

                if ($summary->lastOccurredAt !== null && ($latest === null || $summary->lastOccurredAt->gt($latest))) {
                    $latest = $summary->lastOccurredAt;
                }
            }
        }

        return [
            'total_records' => $totalRecords,
            'firms_with_usage' => $firmsWithUsage,
            'by_provider' => $byProvider,
            'earliest_occurred_at' => $earliest?->toDateTimeString(),
            'latest_occurred_at' => $latest?->toDateTimeString(),
        ];
    }

    /**
     * Phase 2 UI-building pass. The fix for overviewSummaries()'s own
     * "KNOWN, DEFERRED GAP" docblock above — but added as a NEW,
     * separate, additive method rather than a change to
     * overviewSummaries() itself, because that method's unbounded,
     * full-table read is still correctly relied on by
     * PlatformExecutiveDashboardService::integrationsSection() (a
     * genuine cross-firm SUM/attention-count over every firm's row,
     * which cannot be correctly computed from one page of results) and
     * by this class's own existing determinism/correctness test
     * coverage. Bounding THAT method would silently make the executive
     * dashboard's totals wrong — this method exists for
     * PlatformIntegrationOverviewPage's actual row-by-row LISTING UI
     * instead, where a user paging through firm rows does not need the
     * whole table materialized in PHP the way a cross-firm SUM does.
     *
     * Genuine DB-level LIMIT/OFFSET (Laravel's query-builder
     * ->paginate()) against `integration_platform_overview_summaries` —
     * a real table this admin panel can query directly with an ordinary
     * WHERE clause (no FORCE RLS, no per-firm loop required; see that
     * table's own "WHY THIS TABLE HAS NO RLS" docblock), unlike
     * FirmIntegration/FirmUser's own FORCE-RLS'd, per-firm-loop-required
     * shape. Filters/search are applied at the SQL level, BEFORE
     * pagination, so a filtered/searched result set is genuinely bounded
     * end to end, never "paginate first, then discover most of the page
     * doesn't match the filter."
     *
     * A true per-provider filter remains not implementable here (see
     * PlatformIntegrationOverviewPage's own docblock) — this table
     * carries no provider column at all.
     *
     * `firm_name` is resolved via ONE additional bounded query, scoped
     * only to the firm_uuids present on the current page (never one
     * query per row) — mirrors PlatformAdministratorResource::
     * lastLoginAtByAdminId()'s established "bounded to the current
     * page, one query, no N+1" discipline.
     *
     * @param  array<string, mixed>  $filters  Raw Filament filter state
     *                                         (e.g. `['firm_uuid' => ['value' => '...']]`), read the same
     *                                         `['value']`-nested shape PlatformIntegrationOverviewPage's
     *                                         records() closure already reads for its other filters.
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginatedOverviewSummaries(
        PlatformAdmin $admin,
        array $filters = [],
        ?string $search = null,
        int $page = 1,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $this->boundedAccess->assertCanAccessOversight($admin);

        $query = DB::table('integration_platform_overview_summaries');

        $firmUuid = $filters['firm_uuid']['value'] ?? null;
        if (filled($firmUuid)) {
            $query->where('firm_uuid', $firmUuid);
        }

        $lastSyncOutcome = $filters['last_sync_outcome']['value'] ?? null;
        if (filled($lastSyncOutcome)) {
            $query->where('last_sync_outcome', $lastSyncOutcome);
        }

        $healthSummaryState = $filters['health_summary_state']['value'] ?? null;
        if (filled($healthSummaryState)) {
            $query->where('health_summary_state', $healthSummaryState);
        }

        $entitlementEnabled = $filters['entitlement_enabled']['value'] ?? null;
        if ($entitlementEnabled !== null && $entitlementEnabled !== '') {
            $query->where('entitlement_enabled', (bool) (int) $entitlementEnabled);
        }

        $failureState = $filters['failure_state']['value'] ?? null;
        if (filled($failureState)) {
            $query->where(function ($subQuery) use ($failureState): void {
                if ($failureState === 'has_failures') {
                    $subQuery->where('failed_permanent_sync_item_count', '>', 0)
                        ->orWhere('dead_lettered_outbox_event_count', '>', 0)
                        ->orWhere('open_conflict_count', '>', 0);
                } else {
                    $subQuery->where('failed_permanent_sync_item_count', 0)
                        ->where('dead_lettered_outbox_event_count', 0)
                        ->where('open_conflict_count', 0);
                }
            });
        }

        if (filled($search)) {
            // Bounded: at most 500 name-matched firms feed the
            // ->orWhereIn() below — a firm-name search that somehow
            // matched more than that is truncated rather than
            // unbounded, mirroring this class's own established
            // LIMIT-everywhere discipline (AUDIT_HISTORY_LIMIT etc.).
            $matchingFirmUuids = Firm::query()
                ->where('name', 'like', '%'.$search.'%')
                ->orderBy('id')
                ->limit(500)
                ->pluck('uuid');

            $query->where(function ($subQuery) use ($search, $matchingFirmUuids): void {
                $subQuery->where('firm_uuid', 'like', '%'.$search.'%');

                if ($matchingFirmUuids->isNotEmpty()) {
                    $subQuery->orWhereIn('firm_uuid', $matchingFirmUuids);
                }
            });
        }

        $paginator = $query
            ->orderBy('firm_uuid')
            ->orderBy('id')
            ->paginate(perPage: $perPage, page: $page);

        $rows = collect($paginator->items())->map(fn (object $row): array => (array) $row);

        $firmNames = Firm::query()
            ->whereIn('uuid', $rows->pluck('firm_uuid')->filter()->unique()->values())
            ->pluck('name', 'uuid');

        $rows = $rows->map(function (array $row) use ($firmNames): array {
            $row['firm_name'] = $firmNames[$row['firm_uuid']] ?? null;

            return $row;
        })->values();

        return $paginator->setCollection($rows);
    }

    /**
     * Phase 2 query-hardening fix: `orderBy('created_at')` is followed
     * by an explicit `orderBy('id')` tie-breaker, and the query is now
     * genuinely bounded/paginated at the DB level (a real `LIMIT`/
     * `OFFSET` via Eloquent's `paginate()`) rather than materializing
     * every connection a firm has ever had and slicing in PHP
     * afterward — PlatformFirmIntegrationsPage's `->records()` closure
     * passes through the Filament-injected `page`/`recordsPerPage`
     * values so the table's own pagination genuinely drives this query.
     *
     * @return LengthAwarePaginator<int, PlatformIntegrationConnectionSummary>
     */
    public function connectionsForFirm(PlatformAdmin $admin, Firm $firm, int $page = 1, int $perPage = 25): LengthAwarePaginator
    {
        return $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($firm, $page, $perPage): LengthAwarePaginator {
            $paginator = FirmIntegration::query()
                ->where('firm_id', $firm->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->paginate(perPage: $perPage, columns: self::CONNECTION_COLUMNS, page: $page);

            return $paginator->setCollection(
                $paginator->getCollection()
                    ->map(fn (FirmIntegration $connection): PlatformIntegrationConnectionSummary => $this->toConnectionSummary($connection))
                    ->values()
            );
        });
    }

    public function connectionDetail(PlatformAdmin $admin, Firm $firm, string $connectionUuid): ?PlatformIntegrationConnectionSummary
    {
        return $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($firm, $connectionUuid): ?PlatformIntegrationConnectionSummary {
            $connection = FirmIntegration::query()
                ->where('firm_id', $firm->id)
                ->where('uuid', $connectionUuid)
                ->first(self::CONNECTION_COLUMNS);

            return $connection === null ? null : $this->toConnectionSummary($connection);
        });
    }

    /**
     * The required "usage" per-firm sub-view (frozen design §7; agent-
     * 11h-architecture-security-review.md §14: `usageForFirm(int
     * $firmId)`). Read-only reuse of Checkpoint 10's own
     * `IntegrationUsageSummaryService::summariesForFirm()` aggregate-
     * query shape (`SUM(quantity)`/`MIN`/`MAX(occurred_at)` only) — no
     * new usage-aggregation mechanism is invented here. Routes through
     * the same `readWithinFirmAccess()` chokepoint as every other
     * per-firm method in this class; `IntegrationUsageSummaryService`
     * additionally wraps its own nested `runWithFirmContext()` call
     * (the same established, safe-to-nest pattern
     * `HealthStateService::summariesForFirm()` already uses elsewhere
     * in this checkpoint).
     *
     * `IntegrationUsageRecord` has no billing/cost column at all
     * (confirmed against the model), and `IntegrationUsageSummary`
     * carries none either — only sanitized quantity/unit/timestamp
     * aggregates are ever returned here, never a raw provider payload
     * or a cost figure.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function usageForFirm(PlatformAdmin $admin, Firm $firm): Collection
    {
        return $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($admin, $firm): Collection {
            $this->assertCanAccessPlatformBilling($admin);

            return $this->usageSummary->summariesForFirm($firm->id)
                ->map(fn (IntegrationUsageSummary $summary): array => [
                    'firm_integration_id' => $summary->firmIntegrationId,
                    'connection_label' => $summary->connectionLabel,
                    'provider_key' => $summary->providerKey,
                    'capability' => $summary->capability,
                    'operation_type' => $summary->operationType,
                    'direction' => $summary->direction?->value,
                    'total_quantity' => $summary->totalQuantity,
                    'unit' => $summary->unit,
                    'first_occurred_at' => $summary->firstOccurredAt,
                    'last_occurred_at' => $summary->lastOccurredAt,
                ])
                ->values();
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function syncHistoryForConnection(PlatformAdmin $admin, Firm $firm, int $connectionId): Collection
    {
        return $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($firm, $connectionId): Collection {
            return IntegrationSyncRun::query()
                ->where('firm_id', $firm->id)
                ->where('firm_integration_id', $connectionId)
                ->orderByDesc('created_at')
                // Phase 2 query-hardening fix: explicit tie-breaker so
                // two runs sharing the same created_at always sort in a
                // stable, repeatable order.
                ->orderBy('id')
                ->limit(self::SYNC_HISTORY_LIMIT)
                ->get([
                    'id', 'resource_type', 'sync_direction', 'run_type', 'trigger_source', 'status',
                    'items_total', 'items_succeeded', 'items_failed', 'items_skipped', 'started_at', 'finished_at',
                ])
                ->map(fn (IntegrationSyncRun $run): array => [
                    'id' => $run->id,
                    'resource_type' => $run->resource_type,
                    'sync_direction' => $run->sync_direction?->value,
                    'run_type' => $run->run_type?->value,
                    'trigger_source' => $run->trigger_source?->value,
                    'status' => $run->status?->value,
                    'items_total' => $run->items_total,
                    'items_succeeded' => $run->items_succeeded,
                    'items_failed' => $run->items_failed,
                    'items_skipped' => $run->items_skipped,
                    'started_at' => $run->started_at,
                    'finished_at' => $run->finished_at,
                ])
                ->values();
        });
    }

    /**
     * Combined dead-lettered outbox events + failed-permanent sync
     * items for one connection — mirrors
     * App\Filament\Firm\Resources\FirmIntegrationResource\RelationManagers\FailedItemsRelationManager's
     * array-row shape, EXCEPT `last_error` is deliberately OMITTED
     * entirely (frozen design §10 item 2 — stricter than Checkpoint 10,
     * which rendered it for the connection's own firm).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function failedItemsForConnection(PlatformAdmin $admin, Firm $firm, int $connectionId): Collection
    {
        return $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($firm, $connectionId): Collection {
            $outboxEvents = IntegrationOutboxEvent::query()
                ->where('firm_id', $firm->id)
                ->where('firm_integration_id', $connectionId)
                ->where('status', OutboxEventStatus::DeadLettered->value)
                ->orderByDesc('dead_lettered_at')
                // Phase 2 query-hardening fix: explicit tie-breaker,
                // applied BEFORE the in-PHP merge-sort below, so two
                // events sharing the same dead_lettered_at always sort
                // in a stable, repeatable order.
                ->orderBy('id')
                ->limit(self::FAILED_ITEMS_LIMIT)
                // Explicit column allowlist — `last_error` is never
                // selected at the SQL level at all (frozen design §10
                // item 2), not merely omitted from the mapped output
                // below.
                ->get(['id', 'event_type', 'resource_type', 'resource_id', 'dead_lettered_at', 'requeue_count', 'max_requeues'])
                ->map(fn (IntegrationOutboxEvent $event): array => [
                    'id' => "outbox:{$event->id}",
                    'type' => 'outbox_event',
                    'model_id' => $event->id,
                    'label' => $event->event_type,
                    'detail' => trim(($event->resource_type ?? '').($event->resource_id !== null ? " #{$event->resource_id}" : '')),
                    'failed_at' => $event->dead_lettered_at,
                    'requeue_count' => $event->requeue_count,
                    'max_requeues' => $event->max_requeues,
                ]);

            $syncItems = IntegrationSyncItem::query()
                ->join('integration_sync_runs', 'integration_sync_runs.id', '=', 'integration_sync_items.sync_run_id')
                ->where('integration_sync_items.firm_id', $firm->id)
                ->where('integration_sync_runs.firm_integration_id', $connectionId)
                ->where('integration_sync_items.status', SyncItemStatus::FailedPermanent->value)
                ->orderByDesc('integration_sync_items.terminal_at')
                // Phase 2 query-hardening fix: explicit tie-breaker,
                // applied BEFORE the in-PHP merge-sort below, so two
                // items sharing the same terminal_at always sort in a
                // stable, repeatable order.
                ->orderBy('integration_sync_items.id')
                ->limit(self::FAILED_ITEMS_LIMIT)
                // Explicit column allowlist — `last_error` is never
                // selected at the SQL level at all (frozen design §10
                // item 2), not merely omitted from the mapped output
                // below.
                ->select([
                    'integration_sync_items.id',
                    'integration_sync_items.resource_type',
                    'integration_sync_items.external_id',
                    'integration_sync_items.terminal_at',
                    'integration_sync_items.requeue_count',
                ])
                ->get()
                ->map(fn (IntegrationSyncItem $item): array => [
                    'id' => "sync_item:{$item->id}",
                    'type' => 'sync_item',
                    'model_id' => $item->id,
                    'label' => $item->resource_type,
                    'detail' => $item->external_id,
                    'failed_at' => $item->terminal_at,
                    'requeue_count' => $item->requeue_count,
                    'max_requeues' => null,
                ]);

            return $outboxEvents->concat($syncItems)->sortByDesc('failed_at')->values();
        });
    }

    /**
     * `resolution_note` is only present when the acting admin currently
     * holds an active SupportAccessSession for this exact firm (frozen
     * design §10 item 3) — independent of, and never widened by, the
     * coarser role-ceiling check `readWithinFirmAccess()` itself applies.
     * `local_value`/`external_value` are never selected at all (frozen
     * design §10 item 5).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function conflictsForConnection(PlatformAdmin $admin, Firm $firm, int $connectionId): Collection
    {
        return $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($admin, $firm, $connectionId): Collection {
            $canSeeResolutionNote = $this->boundedAccess->hasActiveSupportAccessSessionFor($admin, $firm);

            return IntegrationConflict::query()
                ->where('firm_id', $firm->id)
                ->where('firm_integration_id', $connectionId)
                ->orderByDesc('detected_at')
                // Phase 2 query-hardening fix: explicit tie-breaker so
                // two conflicts sharing the same detected_at always sort
                // in a stable, repeatable order.
                ->orderBy('id')
                ->limit(self::CONFLICTS_LIMIT)
                ->get([
                    'id', 'conflict_type', 'resource_type', 'status', 'requires_manual_review',
                    'resolution_note', 'detected_at', 'resolved_at', 'expires_at',
                ])
                ->map(fn (IntegrationConflict $conflict) => [
                    'id' => $conflict->id,
                    'conflict_type' => $conflict->conflict_type,
                    'resource_type' => $conflict->resource_type,
                    'status' => $conflict->status?->value,
                    'requires_manual_review' => (bool) $conflict->requires_manual_review,
                    'resolution_note' => $canSeeResolutionNote ? $conflict->resolution_note : null,
                    'detected_at' => $conflict->detected_at,
                    'resolved_at' => $conflict->resolved_at,
                    'expires_at' => $conflict->expires_at,
                ])
                ->values();
        });
    }

    /**
     * Sanitized combined audit history for a firm: this firm's own
     * integration-related `timeline_events` rows (frozen design §4:
     * "Reads of timeline_events are unaffected — a SuperAdmin may freely
     * read a firm's existing rows") plus this firm's own governance
     * `security_events` rows written by this checkpoint's own
     * support-access/oversight-action audit trail. Deliberately curated
     * (event_type/occurred_at/actor only) rather than dumping raw
     * metadata JSON — "sanitized," not "unrestricted."
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function sanitizedAuditHistoryForFirm(PlatformAdmin $admin, Firm $firm): Collection
    {
        return $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($admin, $firm): Collection {
            $this->assertCanAccessSecurityLogs($admin);

            // Phase 2 query-hardening fix: both sources below now select
            // `id` and add an explicit `orderBy('id')` tie-breaker
            // (applied BEFORE the in-PHP merge-sort further down) so two
            // rows sharing the same occurred_at/created_at always sort
            // in a stable, repeatable order — `id` is also now included
            // in each mapped row so the tie-break is genuinely visible
            // in the output, not merely used internally.
            $timelineRows = TimelineEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'like', 'integration%')
                ->orderByDesc('occurred_at')
                ->orderBy('id')
                ->limit(self::AUDIT_HISTORY_LIMIT)
                ->get(['id', 'event_type', 'actor_type', 'occurred_at'])
                ->map(fn (TimelineEvent $event): array => [
                    'source' => 'timeline',
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'actor_type' => $event->actor_type,
                    'occurred_at' => $event->occurred_at,
                ]);

            $securityRows = SecurityEvent::query()
                ->where('firm_id', $firm->id)
                ->whereIn('category', ['support_access', 'platform_integration_oversight'])
                ->orderByDesc('created_at')
                ->orderBy('id')
                ->limit(self::AUDIT_HISTORY_LIMIT)
                ->get(['id', 'event_type', 'actor_type', 'created_at'])
                ->map(fn (SecurityEvent $event): array => [
                    'source' => 'security',
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'actor_type' => $event->actor_type,
                    'occurred_at' => $event->created_at,
                ]);

            return $timelineRows
                ->concat($securityRows)
                ->sortByDesc('occurred_at')
                ->take(self::AUDIT_HISTORY_LIMIT)
                ->values();
        });
    }

    /**
     * Global, non-firm-specific retention configuration values (frozen
     * design §7's "retention status" view item) — plain config() reads,
     * never a new production surface, and never a claim of legal-hold
     * safety (frozen design §14: LEGAL_HOLD_COVERAGE_UNRESOLVED remains
     * unresolved).
     *
     * @return array<string, mixed>
     */
    public function retentionConfigSummary(PlatformAdmin $admin): array
    {
        $this->boundedAccess->assertCanAccessOversight($admin);
        $this->assertCanAccessSecurityLogs($admin);

        return [
            'outbox_completed_retention_days' => config('integrations.outbox.completed_retention_days'),
            'outbox_dead_lettered_retention_days' => config('integrations.outbox.dead_lettered_retention_days'),
            'outbox_cancelled_retention_days' => config('integrations.outbox.cancelled_retention_days'),
            'sync_runs_retention_days' => config('integrations.sync_runs.retention_days'),
            'sync_items_retention_days' => config('integrations.sync_items.retention_days'),
            'conflicts_retention_days' => config('integrations.conflicts.retention_days'),
            'oauth_states_consumed_retention_hours' => config('integrations.oauth_states.consumed_retention_hours'),
            'usage_records_retention_days' => config('integrations.usage_records.retention_days'),
        ];
    }

    /**
     * Security review Finding 3 — the billing-view ceiling, enforced
     * here (not merely in the Filament layer). Mirrors
     * PlatformFirmIntegrationBoundedAccessService::assertCanAccessOversight()'s
     * own throw-with-decision-reason shape exactly.
     */
    private function assertCanAccessPlatformBilling(PlatformAdmin $admin): void
    {
        $decision = $this->staffAccess->canAccessPlatformBilling($admin);

        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason ?? 'Not permitted to access platform billing data.');
        }
    }

    /**
     * Security review Finding 3 — the security-log/retention/audit-view
     * ceiling, enforced here (not merely in the Filament layer). Mirrors
     * PlatformFirmIntegrationBoundedAccessService::assertCanAccessOversight()'s
     * own throw-with-decision-reason shape exactly.
     */
    private function assertCanAccessSecurityLogs(PlatformAdmin $admin): void
    {
        $decision = $this->staffAccess->canAccessSecurityLogs($admin);

        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason ?? 'Not permitted to access security logs.');
        }
    }

    private function toConnectionSummary(FirmIntegration $connection): PlatformIntegrationConnectionSummary
    {
        $health = $this->healthState->summaryFor($connection);

        $lastFailureCategory = DB::table('integration_connection_health')
            ->where('firm_integration_id', $connection->id)
            ->value('last_failure_category');

        $webhookRoutingConfigured = DB::table('integration_webhook_routing_index')
            ->where('firm_integration_id', $connection->id)
            ->exists();

        return PlatformIntegrationConnectionSummary::fromModel(
            $connection,
            $health,
            $lastFailureCategory,
            $webhookRoutingConfigured,
        );
    }
}
