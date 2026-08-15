<?php

namespace App\Services;

use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\HighRiskChangeType;
use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\HighRiskChangeRequest;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use Illuminate\Support\Facades\DB;

/**
 * SupportAccessRequestService — the only writer of
 * support_access_requests. reason is always required (project rule:
 * "reason required"). Standard access additionally requires firm
 * approval before a session can start (enforced by
 * SupportAccessPolicyService, not here); emergency access requires
 * emergency_justification and bypasses the firm-approval step, but is
 * never exempt from the reason requirement.
 *
 * Section 39C: emergency access is no longer self-declared-only. Every
 * SupportAccessType::Emergency request also raises a
 * high_risk_change_requests row through the EXISTING, unmodified
 * HighRiskPlatformChangePolicyService, using the change type that
 * already existed for exactly this purpose
 * (HighRiskChangeType::EmergencySupportAccess — already declared as
 * the one change type that does not require a second approver, since
 * HighRiskChangeRequest::requiresSecondApproval() is false for it).
 * The link back to this SupportAccessRequest is stored in the
 * high_risk_change_requests row's existing, already-fillable
 * `metadata` json column (no schema change needed), mirroring exactly
 * how TrustModeActivationService (Phase 13) and
 * TrustIoltaDisableAcknowledgmentService link their own requests via
 * metadata rather than a new column. isEmergencyHighRiskApproved()
 * mirrors TrustIoltaDisableAcknowledgmentService::isAdminApproved()'s
 * exact query shape. SupportAccessPolicyService::canStartSession() is
 * the single place that actually enforces this — this service only
 * raises the high-risk request, it never gates a session itself.
 */
class SupportAccessRequestService
{
    public function __construct(
        private readonly HighRiskPlatformChangePolicyService $highRiskPolicy = new HighRiskPlatformChangePolicyService,
    ) {}

    public function request(
        Firm $firm,
        PlatformAdmin $requestedBy,
        SupportAccessType $accessType,
        string $reason,
        int $requestedDurationMinutes,
        ?string $emergencyJustification = null,
    ): SupportAccessRequest {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A reason is required for every support access request.');
        }

        if ($accessType === SupportAccessType::Emergency && trim((string) $emergencyJustification) === '') {
            throw new \InvalidArgumentException('Emergency support access requires an emergency_justification in addition to reason.');
        }

        $request = (new TenantContextService)->runWithFirmContext($firm, fn () => SupportAccessRequest::create([
            'firm_id' => $firm->id,
            'requested_by' => $requestedBy->id,
            'access_type' => $accessType,
            'reason' => $reason,
            'status' => SupportAccessRequestStatus::Requested,
            'requested_duration_minutes' => $requestedDurationMinutes,
            'emergency_justification' => $emergencyJustification,
        ]));

        if ($accessType === SupportAccessType::Emergency) {
            $this->highRiskPolicy->request(
                HighRiskChangeType::EmergencySupportAccess,
                $requestedBy,
                $reason,
                ['support_access_request_id' => $request->id],
            );
        }

        return $request;
    }

    /**
     * Whether a high_risk_change_requests row raised for this exact
     * SupportAccessRequest (via the metadata linkage) has reached
     * Approved. EmergencySupportAccess never requires a second
     * approval, so a single firstApprove() call is sufficient to
     * satisfy this.
     */
    public function isEmergencyHighRiskApproved(SupportAccessRequest $request): bool
    {
        return HighRiskChangeRequest::query()
            ->where('change_type', HighRiskChangeType::EmergencySupportAccess->value)
            ->where('status', HighRiskChangeRequestStatus::Approved->value)
            ->get()
            ->contains(fn (HighRiskChangeRequest $highRiskRequest) => (int) ($highRiskRequest->metadata['support_access_request_id'] ?? 0) === $request->id);
    }

    /**
     * How long a Requested (pending) support access request stays
     * decidable. Past this window the firm's consent decision is stale —
     * the operational situation that justified the request has moved on —
     * and a fresh request must be raised instead. Enforced synchronously
     * here (approve()/deny()) and in
     * SupportAccessPolicyService::canStartSession(); NOT dependent on any
     * scheduler/reaper, which does not exist for this domain.
     *
     * Derived from the row's own created_at rather than a persisted
     * expires_at column — support_access_requests has no such column and
     * Prompt 6 introduces no schema change. This is real enforcement, not
     * a cosmetic badge: every path that could act on a stale request
     * denies, and the row's status is reconciled to Expired as it does so.
     */
    public const PENDING_REQUEST_DECISION_WINDOW_MINUTES = 24 * 60;

    /**
     * How long a firm's Approved decision may sit unconsumed before a
     * session may no longer be started from it. Prevents stale-consent
     * reuse: an approval granted for a situation days ago must not
     * silently authorize a privileged session today. Enforced in
     * SupportAccessPolicyService::canStartSession().
     */
    public const APPROVAL_CONSUMPTION_WINDOW_MINUTES = 60;

    /**
     * Whether a pending request is past PENDING_REQUEST_DECISION_WINDOW_MINUTES.
     */
    public function isPendingDecisionWindowExpired(SupportAccessRequest $request): bool
    {
        return $request->created_at !== null
            && $request->created_at->copy()->addMinutes(self::PENDING_REQUEST_DECISION_WINDOW_MINUTES)->isPast();
    }

    /**
     * Whether an approved request's consent is too old to start a
     * session from.
     */
    public function isApprovalConsumptionWindowExpired(SupportAccessRequest $request): bool
    {
        return $request->approved_at !== null
            && $request->approved_at->copy()->addMinutes(self::APPROVAL_CONSUMPTION_WINDOW_MINUTES)->isPast();
    }

    /**
     * Firm approval — the customer-consent step ordinary (non-emergency)
     * support access cannot proceed without.
     *
     * Prompt 6 hardening. This method previously performed a bare
     * $request->update(['status' => Approved, ...]) with NO firm-match
     * check, NO current-state validation, NO locking and NO audit — so a
     * FirmUser of firm B could approve firm A's request, an already
     * Denied/Expired request could be flipped to Approved, two concurrent
     * approvers could both write, and none of it left an audit trail.
     * All four are closed here, in the canonical service every caller
     * already goes through, rather than in any UI layer.
     *
     * @throws \RuntimeException when the approver belongs to a different
     *                           firm, or the request is not in a state
     *                           that can still be approved.
     */
    /**
     * Every SupportAccessRequest id whose linked emergency high-risk
     * change request has reached Approved.
     *
     * The set form of isEmergencyHighRiskApproved(), for callers that
     * need to classify many requests at once. Reading the linkage from
     * high_risk_change_requests (a platform-level table) rather than
     * re-fetching each SupportAccessRequest matters: the support tables
     * carry FORCE ROW LEVEL SECURITY, so a per-row re-fetch outside a
     * firm context returns nothing and would quietly under-report.
     *
     * @return array<int, int>
     */
    public function emergencyHighRiskApprovedRequestIds(): array
    {
        return HighRiskChangeRequest::query()
            ->where('change_type', HighRiskChangeType::EmergencySupportAccess->value)
            ->where('status', HighRiskChangeRequestStatus::Approved->value)
            ->get()
            ->map(fn (HighRiskChangeRequest $row): int => (int) ($row->metadata['support_access_request_id'] ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function approve(SupportAccessRequest $request, FirmUser $approver): SupportAccessRequest
    {
        return $this->decide($request, $approver, SupportAccessRequestStatus::Approved);
    }

    /**
     * Firm denial. Same authorization/state/race discipline as
     * approve() — a denied request can never afterwards be approved, and
     * can never issue a session.
     *
     * @throws \RuntimeException
     */
    public function deny(SupportAccessRequest $request, FirmUser $denier): SupportAccessRequest
    {
        return $this->decide($request, $denier, SupportAccessRequestStatus::Denied);
    }

    /**
     * The single, shared, race-safe firm-decision transition behind
     * approve()/deny().
     *
     * Ordering is deliberate and security-relevant:
     *  1. firm match is checked BEFORE anything else and outside the
     *     transaction — a cross-firm approver is never allowed to so much
     *     as lock the row;
     *  2. the row is then re-read FOR UPDATE inside a transaction, so the
     *     state the decision is validated against is the state it is
     *     written against (no TOCTOU between the read that rendered the
     *     approval screen and the write);
     *  3. a request whose decision window has elapsed is reconciled to
     *     Expired and rejected, rather than being silently approvable;
     *  4. only Requested may transition — an already-decided request is
     *     idempotent when re-decided the SAME way (returns the existing
     *     row unchanged, no duplicate audit) and rejected when re-decided
     *     a DIFFERENT way, so Approved+Denied can never both be true.
     */
    private function decide(SupportAccessRequest $request, FirmUser $decider, SupportAccessRequestStatus $target): SupportAccessRequest
    {
        if ((int) $decider->firm_id !== (int) $request->firm_id) {
            throw new \RuntimeException('A support access request may only be decided by a user of the firm it targets.');
        }

        $outcome = (new TenantContextService)->runWithFirmContext($request->firm_id, function () use ($request, $decider, $target) {
            return DB::transaction(function () use ($request, $decider, $target) {
                $fresh = SupportAccessRequest::query()
                    ->where('id', $request->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Idempotency: re-deciding the same way is a no-op that
                // returns the existing decision evidence untouched.
                if ($fresh->status === $target) {
                    return ['decided' => $fresh, 'refusal' => null];
                }

                if ($fresh->status !== SupportAccessRequestStatus::Requested) {
                    return ['decided' => null, 'refusal' => 'This support access request is no longer pending firm approval (current status: '.$fresh->status->value.').'];
                }

                if ($this->isPendingDecisionWindowExpired($fresh)) {
                    $fresh->update(['status' => SupportAccessRequestStatus::Expired]);

                    // Returned rather than thrown: throwing here would roll
                    // this reconciliation back with the transaction, leaving
                    // the stale row pending forever and re-refusing on every
                    // subsequent attempt without ever settling. The refusal
                    // is raised by the caller below, after the commit.
                    return ['decided' => null, 'refusal' => 'This support access request has expired and can no longer be decided. A new request is required.'];
                }

                $fresh->update($target === SupportAccessRequestStatus::Approved
                    ? [
                        'status' => SupportAccessRequestStatus::Approved,
                        'approved_by' => $decider->id,
                        'approved_at' => now(),
                    ]
                    : [
                        'status' => SupportAccessRequestStatus::Denied,
                        'denied_by' => $decider->id,
                        'denied_at' => now(),
                    ]);

                $decided = $fresh->fresh();

                $this->logFirmDecision($decided, $decider, $target);

                return ['decided' => $decided, 'refusal' => null];
            });
        });

        if ($outcome['refusal'] !== null) {
            throw new \RuntimeException($outcome['refusal']);
        }

        return $outcome['decided'];
    }

    /**
     * Firm-decision audit, written to the SAME security_events table and
     * the SAME `support_access` category every other support-access audit
     * row in this domain already uses (SupportAccessPolicyService::
     * logNotification()/logSessionAudit()) — no second audit system.
     *
     * actor_type is FirmUser here, not PlatformAdmin: the firm's own
     * approver genuinely performed this action, and conflating that with
     * the requesting platform admin is exactly the class of false
     * attribution Prompt 6 fixed in logSessionAudit(). Must be called
     * from inside an already-active runWithFirmContext(), matching this
     * domain's established audit-write shape.
     */
    private function logFirmDecision(SupportAccessRequest $request, FirmUser $decider, SupportAccessRequestStatus $target): void
    {
        DB::table('security_events')->insert([
            'firm_id' => $request->firm_id,
            'actor_type' => FirmUser::class,
            'actor_id' => $decider->id,
            'event_type' => $target === SupportAccessRequestStatus::Approved
                ? 'support_access.request_approved'
                : 'support_access.request_denied',
            'category' => 'support_access',
            'metadata' => json_encode([
                'support_access_request_id' => $request->id,
                'support_access_request_uuid' => $request->uuid,
                'access_type' => $request->access_type?->value,
                'requested_duration_minutes' => $request->requested_duration_minutes,
                'requesting_platform_admin_id' => $request->requested_by,
                'resulting_status' => $request->status?->value,
            ]),
            'created_at' => now(),
        ]);
    }

    /**
     * Phase 4 (FirmsVault Platform Admin Control Center, "Support"
     * category) addition: $actor + audit plumbing, added because
     * exposing this as an admin-facing "mark stale request Expired"
     * action (SupportCaseResource's ExpireSupportCaseAction) requires
     * both — this method previously had zero callers anywhere and
     * neither. Mirrors PlatformInvoiceService::finalize()/void()'s
     * exact shape: $actor is optional so every pre-existing caller
     * (currently none in application code; only tests call expire()
     * directly today) keeps byte-for-byte unchanged behavior when it is
     * omitted.
     *
     * Deliberately calls PlatformAdminAuditEventRecorder::record()
     * (firm-scoped), NOT recordPlatformEvent() (the null-firm_id
     * variant PlatformInvoiceService uses for its own actor/audit
     * addition) — unlike PlatformInvoice (keyed to billing_account_id,
     * which can span an organization's multiple firms, per that
     * class's own docblock), SupportAccessRequest carries a real,
     * single, non-nullable firm_id and already has an established
     * firm-scoped audit precedent in this exact table family: every
     * other support-access security_events row
     * (PlatformFirmIntegrationBoundedAccessService::
     * writeOversightAuditEvent(), SupportAccessPolicyService::
     * logSessionAudit()/logNotification()) is firm-attributed, never
     * null-firm_id. Using record() here keeps this action's audit row
     * consistent with its own sibling Revoke action's audit row, both
     * correctly queryable per-firm.
     */
    public function expire(SupportAccessRequest $request, ?PlatformAdmin $actor = null): SupportAccessRequest
    {
        return (new TenantContextService)->runWithFirmContext($request->firm_id, function () use ($request, $actor) {
            $request->update(['status' => SupportAccessRequestStatus::Expired]);

            $fresh = $request->fresh();

            if ($actor !== null) {
                (new PlatformAdminAuditEventRecorder)->record(
                    Firm::query()->findOrFail($fresh->firm_id),
                    $actor,
                    'support_access_request_expired',
                    'support_access',
                    [
                        'support_access_request_id' => $fresh->id,
                        'support_access_request_uuid' => $fresh->uuid,
                        'resulting_status' => $fresh->status->value,
                    ],
                );
            }

            return $fresh;
        });
    }
}
