<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConnectionHealth;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\TenantContextService;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * PlatformPlaidItemDirectoryService — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §3). Backs
 * `PlaidItemOversightResource`. Structurally identical to
 * `App\Services\PlatformConnectionDirectoryService`'s own O(number of
 * firms) per-firm-loop pattern (`firm_integrations` carries permanent
 * FORCE ROW LEVEL SECURITY with no cross-firm-read policy — the same
 * architectural constraint that service's own docblock documents in
 * full), narrowed to `providerKey() === ProviderKey::Plaid` only.
 *
 * REDACTION DISCIPLINE (checkpoint4-combined-design.md §9.4, stated
 * once, binding everywhere in this class): never queries
 * `financial_evidence_*` fact/snapshot tables directly, and no returned
 * row carries a dollar amount, account number, merchant name, or
 * balance figure belonging to an individual transaction — only
 * `firm_integrations`/masked health-summary-shaped data, exactly
 * `PlatformConnectionDirectoryService`'s own already-proven
 * masked-metadata-only discipline.
 */
final class PlatformPlaidItemDirectoryService
{
    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function assertCanAccess(PlatformAdmin $admin): void
    {
        $decision = $this->accessPolicy->canAccessIntegrationOversight($admin);

        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason ?? 'Not permitted to access Plaid item oversight.');
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listAll(PlatformAdmin $admin, ?int $onlyFirmId = null): Collection
    {
        $this->assertCanAccess($admin);

        $firms = Firm::query()
            ->when($onlyFirmId !== null, fn ($query) => $query->where('id', $onlyFirmId))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $rows = collect();

        foreach ($firms as $firm) {
            $firmRows = $this->tenantContext->runWithFirmContext($firm, function () use ($firm): Collection {
                $items = FirmIntegration::query()
                    ->where('firm_id', $firm->id)
                    ->whereHas('integrationProvider', fn ($q) => $q->where('code', ProviderKey::Plaid->value))
                    ->with('integrationProvider')
                    ->orderBy('created_at')
                    ->get();

                if ($items->isEmpty()) {
                    return collect();
                }

                $health = IntegrationConnectionHealth::query()
                    ->where('firm_id', $firm->id)
                    ->whereIn('firm_integration_id', $items->pluck('id'))
                    ->get()
                    ->keyBy('firm_integration_id');

                return $items->map(function (FirmIntegration $item) use ($firm, $health): array {
                    /** @var IntegrationConnectionHealth|null $h */
                    $h = $health->get($item->id);

                    return [
                        'firm_uuid' => $firm->uuid,
                        'firm_name' => $firm->name,
                        'item_uuid' => $item->uuid,
                        'display_label' => $item->display_label ?? 'Untitled connection',
                        'status' => is_object($item->status) ? $item->status->value : $item->status,
                        'requested_capabilities_json' => $item->requested_capabilities_json,
                        'connected_at' => $item->connected_at,
                        'last_health_status' => $item->last_health_status !== null
                            ? (is_object($item->last_health_status) ? $item->last_health_status->value : $item->last_health_status)
                            : null,
                        'last_health_check_at' => $item->last_health_check_at,
                        'health_summary_state' => $h?->summary_state ?? null,
                    ];
                });
            });

            $rows = $rows->concat($firmRows);
        }

        return $rows->values();
    }
}
