<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Firm;
use App\Models\LegalHold;
use App\Models\PlatformAdmin;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * PlatformLegalHoldDirectoryService — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations, Governance, Support, and Configuration"),
 * Governance category, Legal Holds module. The cross-firm read path
 * behind LegalHoldResource. Mutation (place/release) is NOT routed
 * through this class — the Place/Release Filament Actions call
 * LegalHoldService::place()/release() directly (see LegalHoldService's
 * own docblock: both accept a loosely-typed $placedBy/$releasedBy
 * object, so no actor-type gap exists here unlike the Operations
 * domain), each gated by
 * PlatformStaffAccessPolicyService::canManageLegalHolds() at the
 * Filament Action layer.
 *
 * Architectural constraint (see PlatformFirmUserDirectoryService's own
 * docblock, the original template): `legal_holds` carries permanent
 * FORCE ROW LEVEL SECURITY, firm-scoped only — no policy lets any
 * session read across every firm's rows at once. The per-firm loop
 * under runWithFirmContext() is the same architecturally-sound pattern
 * every other cross-firm directory service in this mission uses.
 *
 * Known, deliberate performance trade-off: O(number of firms) queries
 * per call, capped per-firm via PER_FIRM_LIMIT.
 */
final class PlatformLegalHoldDirectoryService
{
    private const PER_FIRM_LIMIT = 200;

    private const COLUMNS = [
        'id', 'uuid', 'scope_type', 'client_id', 'matter_id', 'document_id',
        'reason', 'status', 'placed_by_type', 'placed_by_id', 'placed_at',
        'released_by_type', 'released_by_id', 'released_at', 'release_reason',
        'created_at', 'updated_at',
    ];

    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function assertCanAccess(PlatformAdmin $admin): void
    {
        $decision = $this->accessPolicy->canAccessGovernance($admin);

        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason ?? 'Not permitted to access governance data.');
        }
    }

    /**
     * @param  array{firm_uuid?: ?string, status?: ?string, scope_type?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function list(PlatformAdmin $admin, array $filters = []): Collection
    {
        $this->assertCanAccess($admin);

        $status = $filters['status'] ?? null;
        $scopeType = $filters['scope_type'] ?? null;

        $rows = collect();

        foreach ($this->firmsForFilter($filters['firm_uuid'] ?? null) as $firm) {
            $holds = $this->tenantContext->runWithFirmContext($firm, fn () => LegalHold::query()
                ->when($status !== null, fn ($q) => $q->where('status', $status))
                ->when($scopeType !== null, fn ($q) => $q->where('scope_type', $scopeType))
                ->orderByDesc('placed_at')
                ->orderByDesc('id')
                ->limit(self::PER_FIRM_LIMIT)
                ->get(self::COLUMNS));

            foreach ($holds as $hold) {
                $rows->push($this->toRow($firm, $hold));
            }
        }

        return $this->sortDeterministically($rows)->values();
    }

    public function find(PlatformAdmin $admin, Firm $firm, int $id): ?array
    {
        $this->assertCanAccess($admin);

        $hold = $this->tenantContext->runWithFirmContext($firm, fn () => LegalHold::query()
            ->where('id', $id)
            ->first(self::COLUMNS));

        return $hold === null ? null : $this->toRow($firm, $hold);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Firm $firm, LegalHold $hold): array
    {
        return [
            'id' => $hold->id,
            'uuid' => $hold->uuid,
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'scope_type' => $hold->scope_type?->value,
            'client_id' => $hold->client_id,
            'matter_id' => $hold->matter_id,
            'document_id' => $hold->document_id,
            'reason' => $hold->reason,
            'status' => $hold->status?->value,
            'placed_by_type' => $hold->placed_by_type,
            'placed_by_id' => $hold->placed_by_id,
            'placed_at' => $hold->placed_at,
            'released_by_type' => $hold->released_by_type,
            'released_by_id' => $hold->released_by_id,
            'released_at' => $hold->released_at,
            'release_reason' => $hold->release_reason,
        ];
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
            $aTime = $a['placed_at']?->timestamp ?? 0;
            $bTime = $b['placed_at']?->timestamp ?? 0;

            return $bTime <=> $aTime ?: $b['id'] <=> $a['id'];
        });

        return collect($items)->values();
    }
}
