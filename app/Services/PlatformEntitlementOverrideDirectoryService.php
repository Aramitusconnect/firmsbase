<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\PlatformAdmin;
use Illuminate\Support\Collection;

/**
 * PlatformEntitlementOverrideDirectoryService — Phase 4 (FirmsVault
 * Platform Admin Control Center, "Configuration" category). The
 * cross-firm read path behind EntitlementOverrideResource ("Entitlement
 * Overrides", the honest relabeling of "Feature Flags" against the
 * real, per-firm `firm_entitlements`/`EntitlementSource` mechanism —
 * approved decision: no independent flags table exists or is
 * introduced, see DeploymentFeatureFlagAuditService's own docblock).
 *
 * `firm_entitlements` carries permanent FORCE ROW LEVEL SECURITY via
 * BelongsToTenant, same as every other firm-scoped table in this
 * mission. Deliberately mirrors PlatformFirmUserDirectoryService's
 * pattern (a plain per-firm loop wrapped in
 * TenantContextService::runWithFirmContext(), merged in PHP) rather
 * than PlatformIntegrationCrossFirmDirectoryService/
 * PlatformSupportAccessDirectoryService's pattern of routing through
 * PlatformFirmIntegrationBoundedAccessService::readWithinFirmAccess() —
 * that chokepoint's governed-SupportAgent-session semantics are
 * specific to the Integration/Support-access domain (a SupportAgent
 * needs an active session for a firm before drilling into ITS
 * integration/support data) and do not apply here: entitlement
 * configuration is a distinct Configuration-domain concern gated by
 * its own dedicated PlatformStaffAccessPolicyService::
 * canAccessEntitlementOverrides() role-level check, with no per-firm
 * session requirement layered on top (mirrors
 * PlatformFirmUserDirectoryService::assertCanAccess()'s equally simple,
 * role-only gate for the same reason: Firm Users oversight is also a
 * distinct domain from Integration oversight).
 *
 * Known, deliberate O(number of firms) performance trade-off — same
 * disclosure as every other cross-firm directory service in this
 * codebase (see PlatformFirmUserDirectoryService's own docblock for the
 * full reasoning). If a firm filter narrows the list to one specific
 * firm, the loop below covers exactly that firm.
 */
class PlatformEntitlementOverrideDirectoryService
{
    private const PER_FIRM_LIMIT = 200;

    private const ENTITLEMENT_COLUMNS = [
        'id', 'uuid', 'firm_id', 'module_code', 'enabled', 'source',
        'starts_at', 'ends_at', 'created_by', 'created_at', 'updated_at',
    ];

    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function assertCanAccess(PlatformAdmin $admin): void
    {
        $decision = $this->accessPolicy->canAccessEntitlementOverrides($admin);

        if (! $decision->allowed) {
            throw new \RuntimeException($decision->reason ?? 'Not permitted to access entitlement overrides.');
        }
    }

    /**
     * @param  array{firm_uuid?: ?string, module_code?: ?string, source?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function listEntitlements(PlatformAdmin $admin, array $filters = []): Collection
    {
        $this->assertCanAccess($admin);

        $moduleCode = $filters['module_code'] ?? null;
        $source = $filters['source'] ?? null;

        $rows = collect();

        foreach ($this->firmsForFilter($filters['firm_uuid'] ?? null) as $firm) {
            $entitlements = $this->tenantContext->runWithFirmContext($firm, function () use ($moduleCode, $source): Collection {
                return FirmEntitlement::query()
                    ->when($moduleCode !== null, fn ($q) => $q->where('module_code', $moduleCode))
                    ->when($source !== null, fn ($q) => $q->where('source', $source))
                    ->orderByDesc('updated_at')
                    ->orderBy('id')
                    ->limit(self::PER_FIRM_LIMIT)
                    ->get(self::ENTITLEMENT_COLUMNS);
            });

            foreach ($entitlements as $entitlement) {
                $rows->push($this->toRow($firm, $entitlement));
            }
        }

        return $this->sortDeterministically($rows);
    }

    public function findEntitlement(PlatformAdmin $admin, Firm $firm, int $id): ?array
    {
        $this->assertCanAccess($admin);

        return $this->tenantContext->runWithFirmContext($firm, function () use ($firm, $id): ?array {
            $entitlement = FirmEntitlement::query()->where('id', $id)->first(self::ENTITLEMENT_COLUMNS);

            return $entitlement === null ? null : $this->toRow($firm, $entitlement);
        });
    }

    /**
     * Used by SetEntitlementOverrideAction to resolve the real
     * FirmEntitlement model (or null, if creating a brand-new override
     * for a module the firm has no row for yet) without a second
     * unbounded query.
     */
    public function findEntitlementModel(PlatformAdmin $admin, Firm $firm, int $id): ?FirmEntitlement
    {
        $this->assertCanAccess($admin);

        return $this->tenantContext->runWithFirmContext(
            $firm,
            fn (): ?FirmEntitlement => FirmEntitlement::query()->where('id', $id)->first()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Firm $firm, FirmEntitlement $entitlement): array
    {
        return [
            'id' => $entitlement->id,
            'uuid' => $entitlement->uuid,
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'module_code' => $entitlement->module_code,
            'enabled' => $entitlement->enabled,
            'source' => $entitlement->source?->value,
            'precedence' => $entitlement->source?->precedence(),
            'starts_at' => $entitlement->starts_at,
            'ends_at' => $entitlement->ends_at,
            // Whether this row is currently inside its active window, in
            // operator-facing words. Derived from the model's own
            // canonical isWithinActiveWindow() inputs — this is a
            // DISPLAY label for one row, never a precedence decision
            // (that remains EntitlementService's alone).
            'window_state' => $this->windowState($entitlement),
            'created_at' => $entitlement->created_at,
            'updated_at' => $entitlement->updated_at,
        ];
    }

    private function windowState(FirmEntitlement $entitlement): string
    {
        $now = now();

        if ($entitlement->starts_at && $entitlement->starts_at->isAfter($now)) {
            return 'Scheduled — not yet in effect';
        }

        if ($entitlement->ends_at && $entitlement->ends_at->isBefore($now)) {
            return 'Expired';
        }

        return $entitlement->ends_at === null ? 'In effect — no end date' : 'In effect';
    }

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

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sortDeterministically(Collection $rows): Collection
    {
        $items = $rows->all();

        usort($items, function (array $a, array $b): int {
            $aTime = $a['updated_at']?->timestamp ?? 0;
            $bTime = $b['updated_at']?->timestamp ?? 0;

            return $bTime <=> $aTime ?: $b['id'] <=> $a['id'];
        });

        return collect($items)->values();
    }
}
