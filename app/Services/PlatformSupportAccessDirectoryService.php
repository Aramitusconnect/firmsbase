<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessSessionStatus;
use App\Enums\SupportAccessType;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use Carbon\CarbonInterface;
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

    /**
     * How close to its automatic expiry an active session must be to
     * count as "expiring soon" on the Support Overview.
     */
    private const EXPIRING_SOON_MINUTES = 15;

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
            'reference' => $this->reference('SAR', $request->uuid),
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
            'reference' => $this->reference('SAS', $session->uuid),
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'support_access_request_id' => $session->support_access_request_id,
            'platform_admin_name' => $session->platformAdmin?->name,
            'status' => $session->status?->value,
            // Whether this session would authorize access RIGHT NOW, which
            // is not the same question as what `status` says: a row still
            // flagged Active whose expiry has passed authorizes nothing,
            // because every authorization re-checks the clock. Surfaced
            // separately so an operator is never left inferring live
            // authority from a stale status badge.
            'is_currently_valid' => $session->isCurrentlyValid(),
            'started_at' => $session->started_at,
            'expires_at' => $session->expires_at,
            'time_remaining' => $session->isCurrentlyValid() && $session->expires_at !== null
                ? $session->expires_at->diffForHumans(now(), syntax: CarbonInterface::DIFF_ABSOLUTE)
                : null,
            'ended_at' => $session->ended_at,
            'revoked_at' => $session->revoked_at,
        ];
    }

    /**
     * A stable, operator-readable reference derived from the row's
     * existing immutable uuid. Deliberately not a new sequential
     * identifier column: these rows already carry a permanent identity,
     * and minting a second one would mean a schema change and two
     * competing answers to "which request is this?".
     */
    private function reference(string $prefix, ?string $uuid): string
    {
        return $uuid === null
            ? $prefix
            : $prefix.'-'.strtoupper(substr(str_replace('-', '', $uuid), 0, 12));
    }

    // ---------------------------------------------------------------
    // Support Overview (Prompt 6)
    // ---------------------------------------------------------------

    /**
     * Aggregate counters and rule-based attention signals across every
     * firm this admin may actually see, built from the SAME
     * chokepoint-gated, per-firm reads the two list views use — so the
     * overview can never show an admin a count that includes rows they
     * are not permitted to read.
     *
     * Every number here is measured from real rows. A genuine zero is
     * reported as zero; there is no placeholder, no projection and no
     * behavioural inference — the attention signals below are plain
     * deterministic rules over persisted state.
     *
     * @return array{requests: array<string, int>, sessions: array<string, int>, attention: array<int, array{severity: string, title: string, detail: string, count: int}>}
     */
    public function supportOverview(PlatformAdmin $admin): array
    {
        $requests = $this->listSupportCases($admin);
        $sessions = $this->listApprovedSupportSessions($admin);

        $requestService = new SupportAccessRequestService;

        $pending = $requests->where('status', SupportAccessRequestStatus::Requested->value);
        $approved = $requests->where('status', SupportAccessRequestStatus::Approved->value);

        $activeNow = $sessions->where('is_currently_valid', true);

        // Persisted as Active, but the clock has already passed. This is an
        // operational-state lag, NOT an access grant: every authorization
        // re-checks expires_at, so these sessions already authorize
        // nothing. Surfaced so the discrepancy is visible and explainable
        // rather than quietly wrong-looking in the sessions list.
        $expiredStillFlaggedActive = $sessions
            ->where('status', SupportAccessSessionStatus::Active->value)
            ->where('is_currently_valid', false);

        $expiringSoon = $activeNow->filter(fn (array $row): bool => $row['expires_at'] !== null
            && $row['expires_at']->isBefore(now()->addMinutes(self::EXPIRING_SOON_MINUTES)));

        // Emergency requests that have not (yet) cleared the canonical
        // high-risk platform change approval. These cannot start a session
        // — SupportAccessPolicyService refuses them — but they represent a
        // claimed emergency nobody has acted on.
        // Resolved as one set rather than a per-row lookup: re-fetching each
        // SupportAccessRequest here would run outside any firm context and,
        // under FORCE RLS, return nothing — silently under-reporting the
        // exact signal this counter exists to raise.
        $highRiskApprovedRequestIds = $requestService->emergencyHighRiskApprovedRequestIds();

        $emergencyAwaitingApproval = $requests
            ->where('access_type', SupportAccessType::Emergency->value)
            ->whereIn('status', [SupportAccessRequestStatus::Requested->value, SupportAccessRequestStatus::Approved->value])
            ->reject(fn (array $row): bool => in_array((int) $row['id'], $highRiskApprovedRequestIds, true));

        // Approvals the firm granted that were never consumed and are now
        // past their consumption window — the firm consented, nobody used
        // it, and it will no longer work. A new request is needed.
        $staleApprovals = $approved->filter(fn (array $row): bool => $row['approved_at'] !== null
            && $row['approved_at']->copy()->addMinutes(SupportAccessRequestService::APPROVAL_CONSUMPTION_WINDOW_MINUTES)->isPast());

        $staleUndecided = $pending->filter(fn (array $row): bool => $row['created_at'] !== null
            && $row['created_at']->copy()->addMinutes(SupportAccessRequestService::PENDING_REQUEST_DECISION_WINDOW_MINUTES)->isPast());

        $attention = [];

        $this->pushAttention($attention, 'danger', 'Emergency access awaiting high-risk approval', $emergencyAwaitingApproval->count(),
            'Emergency support access has been requested but the canonical high-risk platform change approval has not been granted. No session can start until it is.');

        $this->pushAttention($attention, 'warning', 'Support sessions expiring soon', $expiringSoon->count(),
            'These sessions end automatically within '.self::EXPIRING_SOON_MINUTES.' minutes. Continuing beyond that requires a new request and a new firm decision — sessions are never silently extended.');

        $this->pushAttention($attention, 'warning', 'Requests still awaiting a firm decision', $pending->count() - $staleUndecided->count(),
            'These firms have not yet approved or denied. Standard support access cannot begin without their decision.');

        $this->pushAttention($attention, 'gray', 'Requests that expired undecided', $staleUndecided->count(),
            'Past the decision window and no longer approvable. Their status reconciles to Expired the next time a decision is attempted; they authorize nothing in the meantime.');

        $this->pushAttention($attention, 'gray', 'Firm approvals that went unused', $staleApprovals->count(),
            'The firm approved, but no session was started within the consumption window. These approvals no longer authorize a session — a new request is required.');

        $this->pushAttention($attention, 'gray', 'Expired sessions not yet reconciled', $expiredStillFlaggedActive->count(),
            'Authorization is already denied for these — expiry is re-checked on every access, so they grant nothing. Only their stored status still reads Active; no reaper reconciles it for this domain.');

        return [
            'requests' => [
                'pending_firm_approval' => $pending->count(),
                'approved' => $approved->count(),
                'denied' => $requests->where('status', SupportAccessRequestStatus::Denied->value)->count(),
                'expired' => $requests->where('status', SupportAccessRequestStatus::Expired->value)->count(),
            ],
            'sessions' => [
                'active_now' => $activeNow->count(),
                'expiring_soon' => $expiringSoon->count(),
                'revoked' => $sessions->where('status', SupportAccessSessionStatus::Revoked->value)->count(),
                'ended' => $sessions->where('status', SupportAccessSessionStatus::Expired->value)->count(),
            ],
            'attention' => $attention,
        ];
    }

    /**
     * @param  array<int, array{severity: string, title: string, detail: string, count: int}>  $attention
     */
    private function pushAttention(array &$attention, string $severity, string $title, int $count, string $detail): void
    {
        if ($count <= 0) {
            return;
        }

        $attention[] = [
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'count' => $count,
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
