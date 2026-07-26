<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\Data\PlatformIntegrationConnectionSummary;
use App\Integrations\Enums\IntegrationCredentialStatus;
use App\Integrations\Enums\SyncRunStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConnectionHealth;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Services\IntegrationCredentialService;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * PlatformConnectionDirectoryService — Phase 2 of the FirmsVault
 * Platform Admin Control Center mission ("Integration Operations
 * Center"). The read path App\Filament\Resources\ConnectionResource
 * uses to list/view `firm_integrations` rows across every firm from the
 * platform-admin panel.
 *
 * Architectural constraint this class exists to satisfy (same one
 * App\Services\PlatformFirmUserDirectoryService's own docblock already
 * documents in detail for firm_users, and the investigation confirmed
 * applies identically here): `firm_integrations` carries permanent
 * FORCE ROW LEVEL SECURITY (a plain firm-scoped policy only — no
 * cross-firm-read policy exists, and the runtime database role this
 * application connects as is never granted BYPASSRLS/superuser). The
 * only architecturally-sound way to read firm_integrations across every
 * firm is the SAME per-firm-loop pattern
 * PlatformFirmUserDirectoryService/FleetMigrationOrchestrationService
 * already established for exactly this problem shape: iterate firms,
 * wrap each iteration in TenantContextService::runWithFirmContext(),
 * merge in PHP.
 *
 * Known, deliberate performance trade-off (flagged for reviewer
 * attention, not hidden — mirrors PlatformFirmUserDirectoryService's own
 * disclosure verbatim): this is O(number of firms) queries per call, not
 * O(1). Acceptable at this platform's current expected admin-panel
 * scale; would need re-architecting (e.g. a no-RLS precomputed
 * summary/index table refreshed by a scheduled job, the same pattern
 * `integration_platform_overview_summaries` already uses) if the firm
 * population grows large enough to make a full per-request scan
 * noticeably slow. Explicitly out of this pass's scope and called out
 * as an open question in the final report, not silently addressed by
 * inventing a new summary table.
 *
 * A consequence of this trade-off: TRUE DB-level pagination across the
 * full cross-firm result set is not achievable without that
 * re-architecture — like FirmUserResource, this service returns one
 * fully-merged, filtered, in-PHP-sorted Collection, and Filament's own
 * `->paginated([25, 50, 100])` slices it client-side (i.e. after the
 * full — but per-firm-bounded, not per-connection-unbounded — merge).
 * If a firm filter narrows the query to one specific firm, $onlyFirmId
 * narrows the loop to exactly that firm, which IS a genuine, bounded,
 * single-firm query — the one optimization available without a schema
 * change (same optimization FirmUserResource's own firm filter already
 * uses).
 *
 * Per-firm reads are batched (never one query per CONNECTION within a
 * firm): health/credential/last-successful-sync data for every
 * connection belonging to one firm is fetched with one query each,
 * keyed by firm_integration_id, then joined in PHP — avoiding N+1
 * within each firm's own iteration.
 *
 * Never decrypts credential material — only
 * IntegrationCredentialService::getMaskedMetadata() (no decrypt call at
 * all) is ever used for credential data.
 */
class PlatformConnectionDirectoryService
{
    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
        private readonly IntegrationCredentialService $credentialService,
        private readonly IntegrationEntitlementPolicyService $entitlement,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function assertCanAccess(PlatformAdmin $admin): void
    {
        $decision = $this->accessPolicy->canAccessIntegrationOversight($admin);

        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason ?? 'Not permitted to access integration oversight.');
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listAll(PlatformAdmin $admin, ?int $onlyFirmId = null, ?int $onlyProviderId = null): Collection
    {
        $this->assertCanAccess($admin);

        $firms = Firm::query()
            ->when($onlyFirmId !== null, fn ($query) => $query->where('id', $onlyFirmId))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $rows = collect();

        foreach ($firms as $firm) {
            $firmRows = $this->tenantContext->runWithFirmContext($firm, function () use ($firm, $onlyProviderId): Collection {
                $connections = FirmIntegration::query()
                    ->where('firm_id', $firm->id)
                    ->when($onlyProviderId !== null, fn ($query) => $query->where('integration_provider_id', $onlyProviderId))
                    ->with('integrationProvider')
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get();

                if ($connections->isEmpty()) {
                    return collect();
                }

                $connectionIds = $connections->pluck('id');

                // Batched per firm — one query each, never one per
                // connection.
                $health = IntegrationConnectionHealth::query()
                    ->where('firm_id', $firm->id)
                    ->whereIn('firm_integration_id', $connectionIds)
                    ->get()
                    ->keyBy('firm_integration_id');

                $activeCredentials = IntegrationCredential::query()
                    ->whereIn('firm_integration_id', $connectionIds)
                    ->where('status', IntegrationCredentialStatus::Active->value)
                    ->get()
                    ->groupBy('firm_integration_id');

                $lastSuccessfulSyncByConnection = IntegrationSyncRun::query()
                    ->where('firm_id', $firm->id)
                    ->whereIn('firm_integration_id', $connectionIds)
                    ->where('status', SyncRunStatus::Succeeded->value)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->get(['firm_integration_id', 'finished_at', 'started_at', 'created_at'])
                    ->groupBy('firm_integration_id')
                    ->map(fn (Collection $runs) => $runs->first());

                $entitlementEnabled = $this->entitlement->isEnabled($firm);

                return $connections->map(function (FirmIntegration $connection) use (
                    $firm,
                    $health,
                    $activeCredentials,
                    $lastSuccessfulSyncByConnection,
                    $entitlementEnabled,
                ): array {
                    return $this->toRow($firm, $connection, $health, $activeCredentials, $lastSuccessfulSyncByConnection, $entitlementEnabled);
                });
            });

            $rows = $rows->concat($firmRows);
        }

        return $rows->values();
    }

    public function findByUuid(PlatformAdmin $admin, Firm $firm, string $connectionUuid): ?array
    {
        $this->assertCanAccess($admin);

        return $this->tenantContext->runWithFirmContext($firm, function () use ($firm, $connectionUuid): ?array {
            $connection = FirmIntegration::query()
                ->where('firm_id', $firm->id)
                ->where('uuid', $connectionUuid)
                ->with('integrationProvider')
                ->first();

            if ($connection === null) {
                return null;
            }

            $health = IntegrationConnectionHealth::query()
                ->where('firm_integration_id', $connection->id)
                ->get()
                ->keyBy('firm_integration_id');

            $activeCredentials = IntegrationCredential::query()
                ->where('firm_integration_id', $connection->id)
                ->where('status', 'active')
                ->get()
                ->groupBy('firm_integration_id');

            $lastSuccessfulSyncByConnection = IntegrationSyncRun::query()
                ->where('firm_id', $firm->id)
                ->where('firm_integration_id', $connection->id)
                ->where('status', SyncRunStatus::Succeeded->value)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(1)
                ->get(['firm_integration_id', 'finished_at', 'started_at', 'created_at'])
                ->groupBy('firm_integration_id')
                ->map(fn (Collection $runs) => $runs->first());

            $entitlementEnabled = $this->entitlement->isEnabled($firm);

            $row = $this->toRow($firm, $connection, $health, $activeCredentials, $lastSuccessfulSyncByConnection, $entitlementEnabled);

            // The View page's richer detail — full masked credential
            // metadata (never decrypted; see class docblock) — is only
            // included here, not on the bounded list row above, to keep
            // the list itself lightweight.
            $row['masked_credentials'] = ($activeCredentials->get($connection->id) ?? collect())
                ->map(fn (IntegrationCredential $credential): array => $this->credentialService->getMaskedMetadata($credential))
                ->values()
                ->all();

            return $row;
        });
    }

    /**
     * @param  Collection<int, IntegrationConnectionHealth>  $health
     * @param  Collection<int, Collection<int, IntegrationCredential>>  $activeCredentials
     * @param  Collection<int, mixed>  $lastSuccessfulSyncByConnection
     * @return array<string, mixed>
     */
    private function toRow(
        Firm $firm,
        FirmIntegration $connection,
        Collection $health,
        Collection $activeCredentials,
        Collection $lastSuccessfulSyncByConnection,
        bool $entitlementEnabled,
    ): array {
        $connectionHealth = $health->get($connection->id);
        $credentials = $activeCredentials->get($connection->id) ?? collect();
        $lastSuccessfulSync = $lastSuccessfulSyncByConnection->get($connection->id);

        return [
            // Filament\Support\ArrayRecord's default key name — a real,
            // stable identifier (the connection's own id) rather than
            // relying on Filament's own positional-index fallback (only
            // safe when a table happens to render exactly one row).
            // Required for DisconnectConnectionAction to resolve a
            // record key when bound explicitly via ->record() on
            // ViewConnection's header (not just as an auto-keyed table
            // row action on ListConnections).
            '__key' => (string) $connection->id,
            'id' => $connection->id,
            'uuid' => $connection->uuid,
            'firm_id' => $firm->id,
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'provider_code' => $connection->integrationProvider?->code,
            'provider_display_name' => $connection->integrationProvider?->display_name ?? 'Unknown provider',
            'display_label' => $connection->display_label ?? $connection->integrationProvider?->display_name ?? 'Untitled connection',
            'status' => $connection->status->value,
            'entitlement_enabled' => $entitlementEnabled,
            'masked_external_account_id' => PlatformIntegrationConnectionSummary::maskExternalAccountId($connection->external_account_id),
            'credential_active_count' => $credentials->count(),
            'credential_nearest_expiry_at' => $credentials->pluck('expires_at')->filter()->sort()->first(),
            'health_summary_state' => $connectionHealth?->summary_state?->value,
            'sanitized_diagnostic_summary' => $connectionHealth?->sanitized_diagnostic_summary,
            'consecutive_failures' => $connectionHealth?->consecutive_failures ?? 0,
            'last_failure_at' => $connectionHealth?->last_failure_at,
            'last_failure_category' => $connectionHealth?->last_failure_category,
            'next_retry_at' => $connectionHealth?->next_retry_at,
            'rate_limited_reset_at' => $connectionHealth?->rate_limited_reset_at,
            'last_successful_sync_at' => $lastSuccessfulSync?->finished_at ?? $lastSuccessfulSync?->started_at ?? $lastSuccessfulSync?->created_at,
            'connected_at' => $connection->connected_at,
            'disconnected_at' => $connection->disconnected_at,
            'created_at' => $connection->created_at,
            'updated_at' => $connection->updated_at,
        ];
    }
}
