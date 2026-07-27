<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * PlatformSupportAccessDirectoryService — Phase 4 (FirmsVault Platform
 * Admin Control Center, "Support" category). The cross-firm read path
 * behind SupportCaseResource (support_access_requests) and
 * SupportSessionResource (support_access_sessions) — two GLOBAL,
 * cross-firm oversight lists that fill a real, previously-flagged gap:
 * PlatformFirmIntegrationsPage (Checkpoint 11) hosts mutating header
 * Actions for this same data (RequestSupportAccessAction/
 * EnterSupportAccessSessionAction/LeaveSupportAccessSessionAction/
 * RevokeSupportAccessSessionAction) but has never had a browsable
 * list/table of either table, single-firm or cross-firm — a gap both
 * force-RLS migrations' own docblocks explicitly anticipated and
 * pre-specified the fix for (see this class's own architecture
 * investigation, phase4-architecture-map-support-configuration.md §1).
 *
 * Mirrors PlatformIntegrationCrossFirmDirectoryService's exact shape
 * (read that class's own docblock first — this one does not repeat the
 * full architectural-constraint rationale). Both underlying tables
 * carry permanent FORCE ROW LEVEL SECURITY, firm-scoped, with no
 * cross-firm-read policy and no BYPASSRLS grant anywhere in this
 * application's runtime database role — the only architecturally-sound
 * way to build a cross-firm list is the same per-firm-loop,
 * runWithFirmContext(), merge-in-PHP pattern every other cross-firm
 * directory service in this codebase already uses.
 *
 * Every per-firm iteration below routes through
 * PlatformFirmIntegrationBoundedAccessService::readWithinFirmAccess() —
 * the SAME chokepoint Checkpoint 11's own single-firm support-access
 * actions already use, deliberately reused rather than duplicated (see
 * PlatformStaffAccessPolicyService::SUPPORT_ACCESS_MANAGEMENT_ROLES'
 * own docblock for why this phase's read-side authorization
 * deliberately reuses canAccessIntegrationOversight() via this same
 * chokepoint instead of introducing a second, divergent read gate over
 * the identical underlying session-governance model). A
 * RuntimeException from that chokepoint (role ceiling not met, or a
 * SupportAgent with no active governed session for that specific firm)
 * is caught PER FIRM and that firm is silently skipped — a SupportAgent
 * legitimately may hold a session for firm A but not firm B and should
 * still see firm A's rows.
 *
 * No redaction concerns of the kind PlatformIntegrationCrossFirmDirectoryService
 * documents (no raw last_error/payload columns exist on either
 * underlying table) — every column selected below is already part of
 * this data's normal oversight surface (reason/emergency_justification
 * are operationally necessary for a support-case triage view, not
 * secrets).
 */
final class PlatformSupportAccessDirectoryService
{
    private const SUPPORT_CASES_PER_FIRM_LIMIT = 200;

    private const SUPPORT_SESSIONS_PER_FIRM_LIMIT = 200;

    private const SUPPORT_REQUEST_COLUMNS = [
        'id', 'uuid', 'firm_id', 'requested_by', 'access_type', 'reason', 'status',
        'approved_by', 'denied_by', 'approved_at', 'denied_at',
        'requested_duration_minutes', 'emergency_justification',
        'created_at', 'updated_at',
    ];

    private const SUPPORT_SESSION_COLUMNS = [
        'id', 'uuid', 'support_access_request_id', 'firm_id', 'platform_admin_id',
        'status', 'started_at', 'expires_at', 'ended_at', 'revoked_by', 'revoked_at',
    ];

    public function __construct(
        private readonly PlatformFirmIntegrationBoundedAccessService $boundedAccess,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    // ---------------------------------------------------------------
    // Support Cases — SupportAccessRequest rows (the request/lifecycle
    // view).
    // ---------------------------------------------------------------

    /**
     * @param  array{firm_uuid?: ?string, status?: ?string, access_type?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function listSupportCases(PlatformAdmin $admin, array $filters = []): Collection
    {
        $status = $filters['status'] ?? null;
        $accessType = $filters['access_type'] ?? null;

        $rows = collect();

        foreach ($this->firmsForFilter($filters['firm_uuid'] ?? null) as $firm) {
            try {
                $requests = $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($status, $accessType): Collection {
                    return SupportAccessRequest::query()
                        ->when($status !== null, fn ($q) => $q->where('status', $status))
                        ->when($accessType !== null, fn ($q) => $q->where('access_type', $accessType))
                        ->with('requestedBy')
                        ->orderByDesc('created_at')
                        ->orderBy('id')
                        ->limit(self::SUPPORT_CASES_PER_FIRM_LIMIT)
                        ->get(self::SUPPORT_REQUEST_COLUMNS);
                });
            } catch (RuntimeException) {
                continue;
            }

            foreach ($requests as $request) {
                $rows->push($this->supportCaseRow($firm, $request));
            }
        }

        return $this->sortDeterministically($rows, 'created_at');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSupportCase(PlatformAdmin $admin, Firm $firm, int $id): ?array
    {
        return $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($firm, $id): ?array {
            $request = SupportAccessRequest::query()
                ->where('id', $id)
                ->with('requestedBy')
                ->first(self::SUPPORT_REQUEST_COLUMNS);

            return $request === null ? null : $this->supportCaseRow($firm, $request);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function supportCaseRow(Firm $firm, SupportAccessRequest $request): array
    {
        return [
            'id' => $request->id,
            'uuid' => $request->uuid,
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'requested_by_name' => $request->requestedBy?->name,
            'access_type' => $request->access_type?->value,
            'reason' => $request->reason,
            'status' => $request->status?->value,
            'requested_duration_minutes' => $request->requested_duration_minutes,
            'emergency_justification' => $request->emergency_justification,
            'approved_at' => $request->approved_at,
            'denied_at' => $request->denied_at,
            'created_at' => $request->created_at,
            'updated_at' => $request->updated_at,
        ];
    }

    // ---------------------------------------------------------------
    // Approved Support Sessions — SupportAccessSession rows (the
    // active/historical time-limited access grant view).
    // ---------------------------------------------------------------

    /**
     * @param  array{firm_uuid?: ?string, status?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function listApprovedSupportSessions(PlatformAdmin $admin, array $filters = []): Collection
    {
        $status = $filters['status'] ?? null;

        $rows = collect();

        foreach ($this->firmsForFilter($filters['firm_uuid'] ?? null) as $firm) {
            try {
                $sessions = $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($status): Collection {
                    return SupportAccessSession::query()
                        ->when($status !== null, fn ($q) => $q->where('status', $status))
                        ->with('platformAdmin')
                        ->orderByDesc('started_at')
                        ->orderBy('id')
                        ->limit(self::SUPPORT_SESSIONS_PER_FIRM_LIMIT)
                        ->get(self::SUPPORT_SESSION_COLUMNS);
                });
            } catch (RuntimeException) {
                continue;
            }

            foreach ($sessions as $session) {
                $rows->push($this->supportSessionRow($firm, $session));
            }
        }

        return $this->sortDeterministically($rows, 'started_at');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findApprovedSupportSession(PlatformAdmin $admin, Firm $firm, int $id): ?array
    {
        return $this->boundedAccess->readWithinFirmAccess($admin, $firm, function () use ($firm, $id): ?array {
            $session = SupportAccessSession::query()
                ->where('id', $id)
                ->with('platformAdmin')
                ->first(self::SUPPORT_SESSION_COLUMNS);

            return $session === null ? null : $this->supportSessionRow($firm, $session);
        });
    }

    /**
     * Used by RevokeApprovedSupportSessionAction to resolve the real
     * SupportAccessSession model (revokeSupportAccessSession() takes a
     * model, not an array row) without a second unbounded query — a
     * single-row, firm-scoped lookup, still gated by the same
     * chokepoint as every other read here.
     */
    public function findApprovedSupportSessionModel(PlatformAdmin $admin, Firm $firm, int $id): ?SupportAccessSession
    {
        return $this->boundedAccess->readWithinFirmAccess(
            $admin,
            $firm,
            fn (): ?SupportAccessSession => SupportAccessSession::query()->where('id', $id)->first()
        );
    }

    /**
     * Used by ExpireSupportCaseAction to resolve the real
     * SupportAccessRequest model — same reasoning as
     * findApprovedSupportSessionModel() above.
     */
    public function findSupportCaseModel(PlatformAdmin $admin, Firm $firm, int $id): ?SupportAccessRequest
    {
        return $this->boundedAccess->readWithinFirmAccess(
            $admin,
            $firm,
            fn (): ?SupportAccessRequest => SupportAccessRequest::query()->where('id', $id)->first()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function supportSessionRow(Firm $firm, SupportAccessSession $session): array
    {
        return [
            'id' => $session->id,
            'uuid' => $session->uuid,
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'support_access_request_id' => $session->support_access_request_id,
            'platform_admin_name' => $session->platformAdmin?->name,
            'status' => $session->status?->value,
            'started_at' => $session->started_at,
            'expires_at' => $session->expires_at,
            'ended_at' => $session->ended_at,
            'revoked_at' => $session->revoked_at,
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

    /**
     * Deterministic, id-tie-broken descending sort across the merged,
     * multi-firm result set — mirrors
     * PlatformIntegrationCrossFirmDirectoryService::sortDeterministically()
     * exactly.
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
