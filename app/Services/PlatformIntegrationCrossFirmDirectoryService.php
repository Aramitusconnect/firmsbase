<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\Enums\OutboxEventStatus;
use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Models\IntegrationInboundWebhookEvent;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\IntegrationSyncItem;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PlatformIntegrationCrossFirmDirectoryService — Phase 2 (FirmsVault
 * Platform Admin Control Center, "Integration Operations Center"). The
 * cross-firm read path behind SyncFailureResource / WebhookEventResource
 * / DeadLetterQueueResource / ConflictResource — four GLOBAL, cross-firm
 * oversight lists this phase adds on top of Checkpoint 11's existing
 * PER-FIRM-only IntegrationPlatformOversightReadService (whose every
 * per-firm read method takes a single Firm and structurally cannot
 * answer "show me every firm's sync failures at once").
 *
 * Architectural constraint (identical to
 * PlatformFirmUserDirectoryService's own documented constraint — read
 * that class first, it is the template this class mirrors): the four
 * underlying tables (integration_sync_items, integration_inbound_webhook_events,
 * integration_outbox_events, integration_conflicts) all carry permanent
 * FORCE ROW LEVEL SECURITY with only a firm-scoped policy — no policy
 * anywhere lets a single session read across every firm's rows at once,
 * and this application's runtime database role is never granted
 * BYPASSRLS. The only architecturally-sound way to build a cross-firm
 * list here (short of a brand-new no-RLS precomputed summary/index
 * table, which is out of this phase's authorized scope) is
 * PlatformFirmUserDirectoryService's own established, already-approved
 * pattern: loop every firm, activate its tenant context via
 * TenantContextService::runWithFirmContext(), merge in PHP.
 *
 * Every per-firm iteration below is routed through
 * PlatformFirmIntegrationBoundedAccessService::readWithinFirmAccess() —
 * the SAME chokepoint IntegrationPlatformOversightReadService uses for
 * every per-firm integration-domain read (see that class's own
 * docblock: "Nothing else in this checkpoint calls
 * SupportAccessRequestService/... directly"). A RuntimeException from
 * that chokepoint (role ceiling not met, or a SupportAgent with no
 * active governed session for that specific firm) is caught PER FIRM and
 * that firm is silently skipped, rather than aborting the whole
 * cross-firm list — a SupportAgent legitimately may hold an active
 * session for firm A but not firm B, and should still see firm A's rows.
 *
 * Known, deliberate performance trade-off (flagged for reviewer
 * attention, exactly like PlatformFirmUserDirectoryService's own
 * disclosure): this is O(number of firms) queries per call, each capped
 * per-firm (see the *_PER_FIRM_LIMIT constants below) so a single firm
 * with a large backlog cannot dominate or unbound the merged result. If
 * a firm filter narrows to one specific firm, the loop below covers
 * exactly that one firm — the one optimization available without a
 * schema change (mirrors PlatformFirmUserDirectoryService's own
 * `$onlyFirmId` narrowing).
 *
 * Redaction discipline mirrors IntegrationPlatformOversightReadService
 * exactly, enforced at the SQL column-allowlist level (never merely
 * omitted from a mapped array afterward):
 *   - `last_error` is never selected on IntegrationSyncItem or
 *     IntegrationOutboxEvent.
 *   - `payload_reference_json` / `payload_hash` are never selected on
 *     IntegrationInboundWebhookEvent.
 *   - `local_value` / `external_value` / `resolution_note` /
 *     `resolved_by_firm_user_id` / `resolution_approved_by_firm_user_id`
 *     are never selected on IntegrationConflict. Unlike
 *     conflictsForConnection()'s single-firm drill-down (which shows
 *     resolution_note while an active support-access session is held),
 *     this GLOBAL cross-firm list never shows it at all, regardless of
 *     session state — a cross-firm list is not the right surface for a
 *     firm-scoped sensitive note.
 *   - "Failure reason" for sync failures / dead-lettered events is never
 *     the raw `last_error` string — it is the SAME governed,
 *     already-sanitized `integration_connection_health.last_failure_category`
 *     column IntegrationPlatformOversightReadService::toConnectionSummary()
 *     already relies on for identical reasons, batched per firm (one
 *     extra bounded query per firm, never per row).
 */
final class PlatformIntegrationCrossFirmDirectoryService
{
    private const SYNC_FAILURES_PER_FIRM_LIMIT = 200;

    private const WEBHOOK_EVENTS_PER_FIRM_LIMIT = 200;

    private const DEAD_LETTER_PER_FIRM_LIMIT = 200;

    private const CONFLICTS_PER_FIRM_LIMIT = 100;

    private const SYNC_ITEM_COLUMNS = [
        'id', 'sync_run_id', 'resource_type', 'external_id', 'status',
        'attempt_count', 'requeue_count', 'requeued_at', 'next_attempt_at',
        'created_at', 'updated_at', 'terminal_at',
    ];

    private const WEBHOOK_EVENT_COLUMNS = [
        'id', 'uuid', 'firm_integration_id', 'provider_key', 'event_type', 'status',
        'received_at', 'processed_at', 'terminal_at', 'processing_attempts',
    ];

    private const OUTBOX_EVENT_COLUMNS = [
        'id', 'firm_integration_id', 'event_type', 'resource_type', 'resource_id',
        'dead_lettered_at', 'requeue_count', 'requeued_at', 'max_requeues',
    ];

    private const CONFLICT_COLUMNS = [
        'id', 'firm_integration_id', 'conflict_type', 'resource_type', 'local_type', 'local_id',
        'status', 'requires_manual_review', 'detected_at', 'resolved_at', 'expires_at',
    ];

    public function __construct(
        private readonly PlatformFirmIntegrationBoundedAccessService $boundedAccess,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    // ---------------------------------------------------------------
    // Sync Failures — IntegrationSyncItem rows in a failed-shaped state.
    // ---------------------------------------------------------------

    /**
     * @param  array{firm_uuid?: ?string, provider_code?: ?string, status?: ?string, from?: ?string, to?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function listSyncFailures(PlatformAdmin $admin, array $filters = []): Collection
    {
        $providerId = $this->resolveProviderId($filters['provider_code'] ?? null);
        $status = $filters['status'] ?? null;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $rows = collect();

        foreach ($this->firmsForFilter($filters['firm_uuid'] ?? null) as $firm) {
            try {
                [$items, $failureCategories] = $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($providerId, $status, $from, $to): array {
                    $items = IntegrationSyncItem::query()
                        ->whereIn('status', [SyncItemStatus::FailedRetryable->value, SyncItemStatus::FailedPermanent->value])
                        ->when($status !== null, fn ($q) => $q->where('status', $status))
                        ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
                        ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to))
                        ->when($providerId !== null, fn ($q) => $q->whereHas(
                            'syncRun',
                            fn ($qq) => $qq->whereHas('firmIntegration', fn ($qqq) => $qqq->where('integration_provider_id', $providerId))
                        ))
                        ->with(['syncRun.firmIntegration.integrationProvider'])
                        ->orderByDesc('updated_at')
                        ->orderBy('id')
                        ->limit(self::SYNC_FAILURES_PER_FIRM_LIMIT)
                        ->get(self::SYNC_ITEM_COLUMNS);

                    $connectionIds = $items->map(fn (IntegrationSyncItem $item): ?int => $item->syncRun?->firm_integration_id);

                    return [$items, $this->failureCategoriesFor($connectionIds)];
                });
            } catch (RuntimeException) {
                continue;
            }

            foreach ($items as $item) {
                $rows->push($this->syncFailureRow($firm, $item, $failureCategories->get($item->syncRun?->firm_integration_id)));
            }
        }

        return $this->sortDeterministically($rows, 'last_attempt_at');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSyncFailure(PlatformAdmin $admin, Firm $firm, int $id): ?array
    {
        return $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($firm, $id): ?array {
            $item = IntegrationSyncItem::query()
                ->where('id', $id)
                ->with(['syncRun.firmIntegration.integrationProvider'])
                ->first(self::SYNC_ITEM_COLUMNS);

            if ($item === null) {
                return null;
            }

            $category = $this->failureCategoriesFor(collect([$item->syncRun?->firm_integration_id]))->get($item->syncRun?->firm_integration_id);

            return $this->syncFailureRow($firm, $item, $category);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function syncFailureRow(Firm $firm, IntegrationSyncItem $item, ?string $failureCategory): array
    {
        $connection = $item->syncRun?->firmIntegration;
        $provider = $connection?->integrationProvider;

        return [
            'id' => $item->id,
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'provider_code' => $provider?->code,
            'provider_display_name' => $provider?->display_name,
            'connection_label' => $connection?->display_label,
            'entity_type' => $item->resource_type,
            'status' => $item->status?->value,
            // Governed, already-sanitized classification only — never
            // the raw `last_error` string (never selected above at all).
            'failure_category' => $failureCategory,
            'attempt_count' => $item->attempt_count,
            'requeue_count' => $item->requeue_count,
            'requeued_at' => $item->requeued_at,
            // Honest proxies (frozen design has no dedicated
            // "first_failed_at" column on integration_sync_items): the
            // row is created when it first enters the sync pipeline
            // (close to, but not literally, its first failure), and
            // `updated_at` changes on every attempt (including the
            // guarded UPDATE requeue() issues), making it the closest
            // real "last attempt" signal actually available.
            'first_seen_at' => $item->created_at,
            'last_attempt_at' => $item->updated_at,
            'next_attempt_at' => $item->next_attempt_at,
            'terminal_at' => $item->terminal_at,
        ];
    }

    // ---------------------------------------------------------------
    // Webhook Events — persisted IntegrationInboundWebhookEvent rows
    // only (never live delivery). List+View only, no mutating action.
    // ---------------------------------------------------------------

    /**
     * @param  array{firm_uuid?: ?string, provider_key?: ?string, event_type?: ?string, status?: ?string, from?: ?string, to?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function listWebhookEvents(PlatformAdmin $admin, array $filters = []): Collection
    {
        $providerKey = $filters['provider_key'] ?? null;
        $eventType = $filters['event_type'] ?? null;
        $status = $filters['status'] ?? null;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $rows = collect();

        foreach ($this->firmsForFilter($filters['firm_uuid'] ?? null) as $firm) {
            try {
                $events = $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($providerKey, $eventType, $status, $from, $to): Collection {
                    return IntegrationInboundWebhookEvent::query()
                        ->when($providerKey !== null, fn ($q) => $q->where('provider_key', $providerKey))
                        ->when($eventType !== null && $eventType !== '', fn ($q) => $q->where('event_type', 'like', '%'.$eventType.'%'))
                        ->when($status !== null, fn ($q) => $q->where('status', $status))
                        ->when($from !== null, fn ($q) => $q->where('received_at', '>=', $from))
                        ->when($to !== null, fn ($q) => $q->where('received_at', '<=', $to))
                        ->with('firmIntegration')
                        ->orderByDesc('received_at')
                        ->orderBy('id')
                        ->limit(self::WEBHOOK_EVENTS_PER_FIRM_LIMIT)
                        ->get(self::WEBHOOK_EVENT_COLUMNS);
                });
            } catch (RuntimeException) {
                continue;
            }

            foreach ($events as $event) {
                $rows->push($this->webhookEventRow($firm, $event));
            }
        }

        return $this->sortDeterministically($rows, 'received_at');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findWebhookEvent(PlatformAdmin $admin, Firm $firm, int $id): ?array
    {
        return $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($firm, $id): ?array {
            $event = IntegrationInboundWebhookEvent::query()
                ->where('id', $id)
                ->with('firmIntegration')
                ->first(self::WEBHOOK_EVENT_COLUMNS);

            return $event === null ? null : $this->webhookEventRow($firm, $event);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function webhookEventRow(Firm $firm, IntegrationInboundWebhookEvent $event): array
    {
        return [
            'id' => $event->id,
            'uuid' => $event->uuid,
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            // provider_key is a denormalized, non-secret routing
            // identifier already carried directly on this row (see the
            // model's own docblock) — no join needed, no N+1 risk.
            'provider_key' => $event->provider_key,
            'event_type' => $event->event_type,
            'status' => $event->status?->value,
            'connection_label' => $event->firmIntegration?->display_label,
            'received_at' => $event->received_at,
            'processed_at' => $event->processed_at,
            'processing_attempts' => $event->processing_attempts,
        ];
    }

    // ---------------------------------------------------------------
    // Dead-Letter Queue — dead-lettered IntegrationOutboxEvent rows.
    // ---------------------------------------------------------------

    /**
     * @param  array{firm_uuid?: ?string, provider_code?: ?string, from?: ?string, to?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function listDeadLetterQueue(PlatformAdmin $admin, array $filters = []): Collection
    {
        $providerId = $this->resolveProviderId($filters['provider_code'] ?? null);
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $rows = collect();

        foreach ($this->firmsForFilter($filters['firm_uuid'] ?? null) as $firm) {
            try {
                [$events, $failureCategories] = $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($providerId, $from, $to): array {
                    $events = IntegrationOutboxEvent::query()
                        ->where('status', OutboxEventStatus::DeadLettered->value)
                        ->when($providerId !== null, fn ($q) => $q->whereHas('firmIntegration', fn ($qq) => $qq->where('integration_provider_id', $providerId)))
                        ->when($from !== null, fn ($q) => $q->where('dead_lettered_at', '>=', $from))
                        ->when($to !== null, fn ($q) => $q->where('dead_lettered_at', '<=', $to))
                        ->with('firmIntegration.integrationProvider')
                        ->orderByDesc('dead_lettered_at')
                        ->orderBy('id')
                        ->limit(self::DEAD_LETTER_PER_FIRM_LIMIT)
                        ->get(self::OUTBOX_EVENT_COLUMNS);

                    return [$events, $this->failureCategoriesFor($events->pluck('firm_integration_id'))];
                });
            } catch (RuntimeException) {
                continue;
            }

            foreach ($events as $event) {
                $rows->push($this->deadLetterRow($firm, $event, $failureCategories->get($event->firm_integration_id)));
            }
        }

        return $this->sortDeterministically($rows, 'dead_lettered_at');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDeadLetterEvent(PlatformAdmin $admin, Firm $firm, int $id): ?array
    {
        return $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($firm, $id): ?array {
            $event = IntegrationOutboxEvent::query()
                ->where('id', $id)
                ->with('firmIntegration.integrationProvider')
                ->first(self::OUTBOX_EVENT_COLUMNS);

            if ($event === null) {
                return null;
            }

            $category = $this->failureCategoriesFor(collect([$event->firm_integration_id]))->get($event->firm_integration_id);

            return $this->deadLetterRow($firm, $event, $category);
        });
    }

    /**
     * ONE batched, bounded query per firm — never per row. Must only
     * ever be called from within an already-active tenant context (the
     * closures above), mirroring
     * PlatformAdministratorResource::lastLoginAtByAdminId()'s identical
     * batching discipline.
     *
     * @param  Collection<int, int|null>  $firmIntegrationIds
     * @return Collection<int, string|null>
     */
    private function failureCategoriesFor(Collection $firmIntegrationIds): Collection
    {
        $ids = $firmIntegrationIds->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::table('integration_connection_health')
            ->whereIn('firm_integration_id', $ids)
            ->pluck('last_failure_category', 'firm_integration_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function deadLetterRow(Firm $firm, IntegrationOutboxEvent $event, ?string $failureCategory): array
    {
        $connection = $event->firmIntegration;
        $provider = $connection?->integrationProvider;

        return [
            'id' => $event->id,
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'provider_code' => $provider?->code,
            'provider_display_name' => $provider?->display_name,
            'connection_label' => $connection?->display_label,
            'original_event_type' => $event->event_type,
            'resource_type' => $event->resource_type,
            // Governed, already-sanitized classification only — never
            // the raw `last_error` string (never selected above at all).
            'failure_category' => $failureCategory,
            'dead_lettered_at' => $event->dead_lettered_at,
            'requeue_count' => $event->requeue_count,
            'max_requeues' => $event->max_requeues,
            'requeued_at' => $event->requeued_at,
        ];
    }

    // ---------------------------------------------------------------
    // Conflicts — READ-ONLY. No resolve/transition path exists anywhere
    // in this class or its callers (see ConflictResource's own
    // docblock).
    // ---------------------------------------------------------------

    /**
     * @param  array{firm_uuid?: ?string, provider_code?: ?string, status?: ?string, from?: ?string, to?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function listConflicts(PlatformAdmin $admin, array $filters = []): Collection
    {
        $providerId = $this->resolveProviderId($filters['provider_code'] ?? null);
        $status = $filters['status'] ?? null;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $rows = collect();

        foreach ($this->firmsForFilter($filters['firm_uuid'] ?? null) as $firm) {
            try {
                $conflicts = $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($providerId, $status, $from, $to): Collection {
                    return IntegrationConflict::query()
                        ->when($providerId !== null, fn ($q) => $q->whereHas('firmIntegration', fn ($qq) => $qq->where('integration_provider_id', $providerId)))
                        ->when($status !== null, fn ($q) => $q->where('status', $status))
                        ->when($from !== null, fn ($q) => $q->where('detected_at', '>=', $from))
                        ->when($to !== null, fn ($q) => $q->where('detected_at', '<=', $to))
                        ->with('firmIntegration.integrationProvider')
                        ->orderByDesc('detected_at')
                        ->orderBy('id')
                        ->limit(self::CONFLICTS_PER_FIRM_LIMIT)
                        ->get(self::CONFLICT_COLUMNS);
                });
            } catch (RuntimeException) {
                continue;
            }

            foreach ($conflicts as $conflict) {
                $rows->push($this->conflictRow($firm, $conflict));
            }
        }

        return $this->sortDeterministically($rows, 'detected_at');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findConflict(PlatformAdmin $admin, Firm $firm, int $id): ?array
    {
        return $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($firm, $id): ?array {
            $conflict = IntegrationConflict::query()
                ->where('id', $id)
                ->with('firmIntegration.integrationProvider')
                ->first(self::CONFLICT_COLUMNS);

            return $conflict === null ? null : $this->conflictRow($firm, $conflict);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function conflictRow(Firm $firm, IntegrationConflict $conflict): array
    {
        $connection = $conflict->firmIntegration;
        $provider = $connection?->integrationProvider;

        return [
            'id' => $conflict->id,
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'provider_code' => $provider?->code,
            'provider_display_name' => $provider?->display_name,
            'conflict_type' => $conflict->conflict_type,
            'resource_type' => $conflict->resource_type,
            // Safely summarized pointer only — never local_value/
            // external_value (never selected above at all).
            'involved_entity' => trim(($conflict->local_type ?? '').($conflict->local_id !== null ? " #{$conflict->local_id}" : '')),
            'status' => $conflict->status?->value,
            'requires_manual_review' => (bool) $conflict->requires_manual_review,
            'detected_at' => $conflict->detected_at,
            'resolved_at' => $conflict->resolved_at,
            'expires_at' => $conflict->expires_at,
        ];
    }

    // ---------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------

    /**
     * @return Collection<int, Firm>
     */
    private function firmsForFilter(?string $firmUuid): Collection
    {
        return Firm::query()
            ->when($firmUuid !== null, fn ($q) => $q->where('uuid', $firmUuid))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    private function resolveProviderId(?string $providerCode): ?int
    {
        if ($providerCode === null || $providerCode === '') {
            return null;
        }

        return IntegrationProvider::query()->where('code', $providerCode)->value('id');
    }

    /**
     * Deterministic, id-tie-broken descending sort across the merged,
     * multi-firm result set — applied AFTER each per-firm query has
     * already been ordered/limited at the DB level, so the final order
     * is stable and repeatable end-to-end, matching every other
     * tie-breaker fix in this domain (see
     * IntegrationPlatformOversightReadService's own "Phase 2
     * query-hardening fix" comments).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sortDeterministically(Collection $rows, string $timestampKey): Collection
    {
        $items = $rows->all();

        usort($items, function (array $a, array $b) use ($timestampKey): int {
            $aTime = $a[$timestampKey]?->timestamp ?? 0;
            $bTime = $b[$timestampKey]?->timestamp ?? 0;

            return $bTime <=> $aTime ?: $b['id'] <=> $a['id'];
        });

        return collect($items)->values();
    }
}
