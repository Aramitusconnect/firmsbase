<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DeletionApproval;
use App\Models\DeletionRequest;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * PlatformDeletionRequestDirectoryService — Phase 4 (FirmsVault Platform
 * Admin Control Center, "Operations, Governance, Support, and
 * Configuration"), Governance category, Deletion Requests module. The
 * cross-firm read path behind DeletionRequestResource — the single most
 * backend-complete module across all 11 (see the Phase 4 architecture
 * map §B.5): a genuine two-person-approval workflow, fully wired, fully
 * audited, fully PlatformAdmin-typed already.
 *
 * Architectural constraint (see PlatformFirmUserDirectoryService's own
 * docblock, the original template): `deletion_requests` carries
 * permanent FORCE ROW LEVEL SECURITY, firm-scoped only. The per-firm
 * loop under runWithFirmContext() is the same pattern every other
 * cross-firm directory service in this mission uses.
 *
 * `deletion_approvals` carries no firm_id of its own — InheritedTenant,
 * scoped transitively through deletion_request_id
 * (RowLevelSecurityCoverageMappingService). Read here ONLY via a
 * batched whereIn() keyed by deletion_request_id values already
 * resolved under the same firm's own FORCE-RLS-protected context in
 * the same loop iteration — never queried independently cross-firm,
 * mirroring PlatformDataExportGovernanceDirectoryService's identical
 * "join through the RLS-covered parent" discipline for
 * offboarding_exports.
 *
 * Hard read-only boundary this class enforces by omission: there is NO
 * `execute()`/`delete()` method anywhere in this codebase for
 * DeletionRequest — `ReadyForExecution` is a deliberate dead end (see
 * DeletionRequest's own docblock: "approved decision #1 ... Phase 17
 * never performs the physical row delete"). Every row shape returned
 * below is labeled with the raw enum value only; the Resource/Page
 * layer is responsible for never rendering "deleted" for
 * ReadyForExecution.
 */
final class PlatformDeletionRequestDirectoryService
{
    private const PER_FIRM_LIMIT = 200;

    private const DELETION_REQUEST_COLUMNS = [
        'id', 'uuid', 'subject_type', 'subject_id', 'subject_snapshot_json',
        'reason', 'status', 'offboarding_export_id', 'requested_by_type',
        'requested_by_id', 'requested_at', 'executed_at', 'cancelled_at', 'created_at',
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
     * @param  array{firm_uuid?: ?string, status?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function list(PlatformAdmin $admin, array $filters = []): Collection
    {
        $this->assertCanAccess($admin);

        $status = $filters['status'] ?? null;

        $rows = collect();

        foreach ($this->firmsForFilter($filters['firm_uuid'] ?? null) as $firm) {
            $requests = $this->tenantContext->runWithFirmContext($firm, fn () => DeletionRequest::query()
                ->when($status !== null, fn ($q) => $q->where('status', $status))
                ->orderByDesc('requested_at')
                ->orderByDesc('id')
                ->limit(self::PER_FIRM_LIMIT)
                ->get(self::DELETION_REQUEST_COLUMNS));

            $approvals = $this->approvalsForRequests($requests->pluck('id'));

            foreach ($requests as $request) {
                $rows->push($this->toRow($firm, $request, $approvals->get($request->id)));
            }
        }

        return $this->sortDeterministically($rows)->values();
    }

    public function find(PlatformAdmin $admin, Firm $firm, int $id): ?array
    {
        $this->assertCanAccess($admin);

        $request = $this->tenantContext->runWithFirmContext($firm, fn () => DeletionRequest::query()
            ->where('id', $id)
            ->first(self::DELETION_REQUEST_COLUMNS));

        if ($request === null) {
            return null;
        }

        $approval = $this->approvalsForRequests(collect([$request->id]))->get($request->id);

        return $this->toRow($firm, $request, $approval);
    }

    /**
     * ONE batched query, keyed only by ids already established as
     * belonging to the calling firm (see class docblock) — never called
     * with an unbounded/unscoped id set.
     *
     * @param  Collection<int, int>  $requestIds
     * @return Collection<int, array<string, mixed>> keyed by deletion_request_id
     */
    private function approvalsForRequests(Collection $requestIds): Collection
    {
        $ids = $requestIds->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return DeletionApproval::query()
            ->whereIn('deletion_request_id', $ids)
            ->get([
                'id', 'uuid', 'deletion_request_id', 'high_risk_change_request_id', 'status',
                'first_approved_by', 'first_approved_at', 'second_approved_by',
                'second_approved_at', 'denied_by', 'denied_at', 'denial_reason', 'created_at',
            ])
            ->keyBy('deletion_request_id')
            ->map(fn (DeletionApproval $approval): array => [
                'id' => $approval->id,
                'uuid' => $approval->uuid,
                'high_risk_change_request_id' => $approval->high_risk_change_request_id,
                'status' => $approval->status?->value,
                'first_approved_by' => $approval->first_approved_by,
                'first_approved_at' => $approval->first_approved_at,
                'second_approved_by' => $approval->second_approved_by,
                'second_approved_at' => $approval->second_approved_at,
                'denied_by' => $approval->denied_by,
                'denied_at' => $approval->denied_at,
                'denial_reason' => $approval->denial_reason,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Firm $firm, DeletionRequest $request, ?array $approval): array
    {
        return [
            'id' => $request->id,
            'uuid' => $request->uuid,
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'subject_type' => $request->subject_type,
            'subject_id' => $request->subject_id,
            'subject_snapshot_json' => $request->subject_snapshot_json,
            'reason' => $request->reason,
            'status' => $request->status?->value,
            'offboarding_export_id' => $request->offboarding_export_id,
            'requested_by_type' => $request->requested_by_type,
            'requested_by_id' => $request->requested_by_id,
            'requested_at' => $request->requested_at,
            'cancelled_at' => $request->cancelled_at,
            'approval' => $approval,
            'approval_status' => $approval['status'] ?? null,
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
            $aTime = $a['requested_at']?->timestamp ?? 0;
            $bTime = $b['requested_at']?->timestamp ?? 0;

            return $bTime <=> $aTime ?: $b['id'] <=> $a['id'];
        });

        return collect($items)->values();
    }
}
