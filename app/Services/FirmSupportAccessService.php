<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessSessionStatus;
use App\Enums\SupportAccessType;
use App\Models\FirmUser;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * FirmSupportAccessService — Prompt 6. The single chokepoint the
 * firm-facing Support Access page goes through for every read and every
 * decision. Mirrors PlatformFirmIntegrationBoundedAccessService's role on
 * the platform side: authorization is enforced HERE, in a service, never
 * by a Filament ->visible()/->hidden() condition or a hidden button.
 *
 * This closes a real, previously-flagged P0 gap. Ordinary (non-emergency)
 * support access is designed to require explicit firm consent —
 * SupportAccessPolicyService::canStartSession() refuses to issue a session
 * until the request reaches Approved, and SupportAccessRequestService::
 * approve()/deny() exist to make that transition — but before this class
 * there was NO firm-facing surface anywhere in the application that could
 * call them. Their only callers were tests. The consent step the whole
 * zero-trust design rests on was unreachable by the party whose consent it
 * is, which is why SupportCaseResource's own empty state used to describe
 * firm-side approval as a boundary rather than a gap.
 *
 * Every method below:
 *   - resolves the acting FirmUser's OWN firm and never accepts a firm id
 *     from the caller — a firm id in a URL, form field or query string can
 *     therefore never widen what is read or decided;
 *   - re-checks the FirmSupportAccessPolicyService role ceiling on every
 *     call, not once at page mount;
 *   - resolves the target record by id INSIDE that firm's tenant context,
 *     so a substituted request/session id belonging to another firm
 *     resolves to nothing (RLS + explicit firm predicate, defence in
 *     depth) and fails closed;
 *   - delegates every mutation to the canonical service
 *     (SupportAccessRequestService / SupportAccessSessionService), which
 *     owns state validation, locking, idempotency and audit — this class
 *     adds no second write path and no second audit system.
 */
final class FirmSupportAccessService
{
    private const HISTORY_LIMIT = 100;

    public function __construct(
        private readonly FirmSupportAccessPolicyService $policy = new FirmSupportAccessPolicyService,
        private readonly SupportAccessRequestService $requests = new SupportAccessRequestService,
        private readonly SupportAccessSessionService $sessions = new SupportAccessSessionService,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    /**
     * Requests still awaiting this firm's decision. Requests whose
     * decision window has elapsed are excluded rather than offered as
     * decidable — approve()/deny() would refuse them anyway, and showing
     * an Approve button that is guaranteed to fail is a false affordance.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pendingRequests(FirmUser $firmUser): Collection
    {
        $this->assertCanReview($firmUser);

        return $this->tenantContext->runWithFirmContext((int) $firmUser->firm_id, fn (): Collection => SupportAccessRequest::query()
            ->where('firm_id', $firmUser->firm_id)
            ->where('status', SupportAccessRequestStatus::Requested->value)
            ->with('requestedBy')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reject(fn (SupportAccessRequest $request): bool => $this->requests->isPendingDecisionWindowExpired($request))
            ->map(fn (SupportAccessRequest $request): array => $this->requestRow($request))
            ->values());
    }

    /**
     * Support sessions currently authorizing access into this firm.
     *
     * Filtered on expires_at > now() as well as status, deliberately: a
     * row still flagged Active whose expiry has passed no longer
     * authorizes anything (SupportAccessSession::isCurrentlyValid() and
     * PlatformFirmIntegrationBoundedAccessService both re-check the clock
     * on every access), so listing it as active support would overstate
     * the firm's exposure. It appears under history instead.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function activeSessions(FirmUser $firmUser): Collection
    {
        $this->assertCanReview($firmUser);

        return $this->tenantContext->runWithFirmContext((int) $firmUser->firm_id, fn (): Collection => SupportAccessSession::query()
            ->where('firm_id', $firmUser->firm_id)
            ->where('status', SupportAccessSessionStatus::Active->value)
            ->where('expires_at', '>', now())
            ->with(['platformAdmin', 'supportAccessRequest'])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (SupportAccessSession $session): array => $this->sessionRow($session))
            ->values());
    }

    /**
     * Past support access into this firm — every session that is no
     * longer authorizing access, whether it ended, was revoked, or simply
     * expired. This is the firm's own permanent record of who entered
     * their data and why.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pastSessions(FirmUser $firmUser): Collection
    {
        $this->assertCanReview($firmUser);

        return $this->tenantContext->runWithFirmContext((int) $firmUser->firm_id, fn (): Collection => SupportAccessSession::query()
            ->where('firm_id', $firmUser->firm_id)
            ->where(fn ($query) => $query
                ->where('status', '!=', SupportAccessSessionStatus::Active->value)
                ->orWhere('expires_at', '<=', now()))
            ->with(['platformAdmin', 'supportAccessRequest'])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (SupportAccessSession $session): array => $this->sessionRow($session))
            ->values());
    }

    // ---------------------------------------------------------------
    // Decisions
    // ---------------------------------------------------------------

    /**
     * Grant this firm's consent to a pending support access request.
     *
     * @throws RuntimeException when unauthorized, or the request does not
     *                          belong to this firm, or it is no longer decidable.
     */
    public function approve(FirmUser $firmUser, int $requestId): SupportAccessRequest
    {
        $this->assertCanDecide($firmUser);

        return $this->requests->approve($this->resolveRequest($firmUser, $requestId), $firmUser);
    }

    /**
     * Refuse a pending support access request. A denied request can never
     * afterwards be approved and can never issue a session.
     *
     * @throws RuntimeException
     */
    public function deny(FirmUser $firmUser, int $requestId): SupportAccessRequest
    {
        $this->assertCanDecide($firmUser);

        return $this->requests->deny($this->resolveRequest($firmUser, $requestId), $firmUser);
    }

    /**
     * End an active support session into this firm immediately, before it
     * would otherwise expire.
     *
     * @throws RuntimeException
     */
    public function revokeSession(FirmUser $firmUser, int $sessionId): SupportAccessSession
    {
        if (! $this->policy->canRevoke($firmUser->role)) {
            throw new RuntimeException('You are not permitted to revoke support access for this firm.');
        }

        return $this->sessions->revokeByFirm($this->resolveSession($firmUser, $sessionId), $firmUser);
    }

    // ---------------------------------------------------------------
    // Resolution + authorization
    // ---------------------------------------------------------------

    /**
     * Resolves a request id strictly within the acting user's own firm.
     * The firm predicate is explicit AND the lookup runs inside that
     * firm's tenant context (support_access_requests carries FORCE ROW
     * LEVEL SECURITY, firm-scoped) — a request id belonging to another
     * firm resolves to nothing on both counts.
     */
    private function resolveRequest(FirmUser $firmUser, int $requestId): SupportAccessRequest
    {
        $request = $this->tenantContext->runWithFirmContext(
            (int) $firmUser->firm_id,
            fn (): ?SupportAccessRequest => SupportAccessRequest::query()
                ->where('id', $requestId)
                ->where('firm_id', $firmUser->firm_id)
                ->first()
        );

        if ($request === null) {
            throw new RuntimeException('That support access request was not found for this firm.');
        }

        return $request;
    }

    private function resolveSession(FirmUser $firmUser, int $sessionId): SupportAccessSession
    {
        $session = $this->tenantContext->runWithFirmContext(
            (int) $firmUser->firm_id,
            fn (): ?SupportAccessSession => SupportAccessSession::query()
                ->where('id', $sessionId)
                ->where('firm_id', $firmUser->firm_id)
                ->first()
        );

        if ($session === null) {
            throw new RuntimeException('That support session was not found for this firm.');
        }

        return $session;
    }

    private function assertCanReview(FirmUser $firmUser): void
    {
        if (! $this->policy->canReview($firmUser->role)) {
            throw new RuntimeException('You are not permitted to review support access for this firm.');
        }
    }

    private function assertCanDecide(FirmUser $firmUser): void
    {
        if (! $this->policy->canDecide($firmUser->role)) {
            throw new RuntimeException('You are not permitted to decide support access requests for this firm.');
        }
    }

    // ---------------------------------------------------------------
    // Presentation rows
    // ---------------------------------------------------------------

    /**
     * The firm-facing view of a request. Deliberately includes the
     * requesting platform staff member's name and their stated reason —
     * unlike FirmSecurityActivityPage, which summarizes support access
     * without an actor because it is a passive after-the-fact notice.
     * Here the firm is being asked to CONSENT, and consent to an
     * unidentified party for an undisclosed purpose is not consent.
     *
     * @return array<string, mixed>
     */
    private function requestRow(SupportAccessRequest $request): array
    {
        return [
            'id' => $request->id,
            'reference' => $this->reference('SAR', $request->uuid),
            'requested_by_name' => $request->requestedBy?->name,
            'access_type' => $request->access_type?->value,
            'access_type_label' => $request->access_type === SupportAccessType::Emergency ? 'Emergency' : 'Standard',
            'is_emergency' => $request->access_type === SupportAccessType::Emergency,
            'reason' => $request->reason,
            'requested_duration_minutes' => $request->requested_duration_minutes,
            'requested_at' => $request->created_at,
            'decision_deadline' => $request->created_at?->copy()
                ->addMinutes(SupportAccessRequestService::PENDING_REQUEST_DECISION_WINDOW_MINUTES),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionRow(SupportAccessSession $session): array
    {
        $isCurrentlyValid = $session->isCurrentlyValid();

        return [
            'id' => $session->id,
            'reference' => $this->reference('SAS', $session->uuid),
            'platform_admin_name' => $session->platformAdmin?->name,
            'reason' => $session->supportAccessRequest?->reason,
            'access_type_label' => $session->supportAccessRequest?->access_type === SupportAccessType::Emergency ? 'Emergency' : 'Standard',
            'status_label' => $this->sessionStatusLabel($session),
            'started_at' => $session->started_at,
            'expires_at' => $session->expires_at,
            // Server-derived, never a client countdown: this is the value
            // the server itself would authorize against.
            'time_remaining' => $isCurrentlyValid && $session->expires_at !== null
                ? $session->expires_at->diffForHumans(now(), syntax: CarbonInterface::DIFF_ABSOLUTE)
                : null,
            'ended_at' => $session->ended_at,
            'revoked_at' => $session->revoked_at,
        ];
    }

    /**
     * A stable, operator-readable reference derived from the row's
     * existing immutable uuid — deliberately NOT a new sequential
     * identifier column, which would be a schema change and a second
     * source of identity for a row that already has a permanent one.
     */
    private function reference(string $prefix, ?string $uuid): string
    {
        return $uuid === null
            ? $prefix
            : $prefix.'-'.strtoupper(substr(str_replace('-', '', $uuid), 0, 12));
    }

    /**
     * Distinguishes a session that has genuinely ended from one whose
     * persisted status still says Active but whose expiry has passed.
     * The latter is an operational-state lag, never an access grant — the
     * clock is re-checked on every authorization — and the firm is told
     * plainly that it is no longer authorizing access.
     */
    private function sessionStatusLabel(SupportAccessSession $session): string
    {
        if ($session->isCurrentlyValid()) {
            return 'Active';
        }

        return match ($session->status) {
            SupportAccessSessionStatus::Revoked => 'Revoked',
            SupportAccessSessionStatus::Expired => 'Ended',
            default => 'Expired',
        };
    }
}
