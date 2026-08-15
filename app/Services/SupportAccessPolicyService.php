<?php

namespace App\Services;

use App\Enums\SupportAccessRequestStatus;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use App\ValueObjects\SupportAccessDecision;
use Illuminate\Support\Facades\DB;

/**
 * SupportAccessPolicyService — decides whether a support access
 * request/session may proceed, and is the ONE place that logs the
 * "automatic notification event" required by the approved scope. No
 * real notification provider is used (forbidden in Phase 7) — the
 * notification is represented as a row in the existing security_events
 * table (Phase 1), reused as-is rather than inventing a second
 * audit/notification mechanism. Emergency access is always logged with
 * the stronger emergency_justification field included in metadata.
 *
 * Section 39C: emergency access is no longer allowed the instant
 * emergency_justification is non-empty — it also requires the linked
 * high_risk_change_requests row (raised by SupportAccessRequestService
 * via the existing, unmodified HighRiskPlatformChangePolicyService) to
 * have reached Approved. This is the single enforcement point; no
 * second approval/audit system was introduced.
 */
class SupportAccessPolicyService
{
    public function canStartSession(SupportAccessRequest $request): SupportAccessDecision
    {
        if (trim($request->reason) === '') {
            return SupportAccessDecision::deny('reason is required');
        }

        $requestService = new SupportAccessRequestService;

        // Prompt 6: a request that platform-side governance has already
        // terminated must never issue a session, on EITHER path. This
        // check deliberately precedes the emergency branch below —
        // emergency access bypasses the FIRM-CONSENT step (the one
        // documented, canonically-authorized bypass), never the request's
        // own lifecycle. Previously the emergency branch returned allow()
        // without consulting status at all, so an Expired or Revoked
        // emergency request stayed indefinitely startable.
        if (in_array($request->status, [SupportAccessRequestStatus::Expired, SupportAccessRequestStatus::Revoked], true)) {
            return SupportAccessDecision::deny('this support access request is no longer active ('.$request->status->value.') — a new request is required');
        }

        // Prompt 6: no approval, on either path, may issue more than one
        // privileged session. Without this a single firm approval was an
        // unbounded, indefinitely re-usable licence to re-enter the firm:
        // start, leave, start again, with the firm having consented once.
        // One approval -> one bounded JIT session; more time requires a
        // new request and a new firm decision.
        if ($request->sessions()->exists()) {
            return SupportAccessDecision::deny('a support session has already been issued for this request — a new request and firm decision are required for further access');
        }

        if ($request->isEmergency()) {
            if (trim((string) $request->emergency_justification) === '') {
                return SupportAccessDecision::deny('emergency access requires emergency_justification');
            }

            if (! $requestService->isEmergencyHighRiskApproved($request)) {
                return SupportAccessDecision::deny('emergency access requires platform high-risk approval before a session may start');
            }

            return SupportAccessDecision::allow();
        }

        if ($request->status !== SupportAccessRequestStatus::Approved) {
            return SupportAccessDecision::deny('support access requires firm approval unless emergency');
        }

        // Prompt 6: firm consent is a point-in-time decision about a
        // point-in-time situation, not a standing grant. An approval left
        // unconsumed beyond the canonical window is stale and must not
        // silently authorize a privileged session later.
        if ($requestService->isApprovalConsumptionWindowExpired($request)) {
            return SupportAccessDecision::deny('this firm approval is no longer current — a new support access request and firm decision are required');
        }

        return SupportAccessDecision::allow();
    }

    /**
     * Logs the request/grant/denial to security_events (Phase 1) —
     * this IS the "automatic notification event" required by the
     * approved scope. No real email/SMS/webhook is ever sent.
     */
    public function logNotification(SupportAccessRequest $request, string $eventType): void
    {
        (new TenantContextService)->runWithFirmContext($request->firm_id, function () use ($request, $eventType) {
            DB::table('security_events')->insert([
                'firm_id' => $request->firm_id,
                'actor_type' => PlatformAdmin::class,
                'actor_id' => $request->requested_by,
                'event_type' => $eventType,
                'category' => 'support_access',
                'metadata' => json_encode([
                    'support_access_request_id' => $request->id,
                    'access_type' => $request->access_type->value,
                    'reason' => $request->reason,
                    'emergency_justification' => $request->emergency_justification,
                ]),
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Prompt 6 actor-attribution correction. This method previously
     * ALWAYS wrote actor_id = $session->platform_admin_id — the
     * PlatformAdmin the session was ISSUED FOR — regardless of who
     * actually performed the audited action. For every cross-actor
     * action (Platform Admin B revoking Platform Admin A's session,
     * which is RevokeSupportAccessSessionAction's entire documented
     * purpose) that silently attributed B's action to A: a false
     * attribution in a security audit trail, not merely a missing one.
     *
     * The fix is on the canonical path itself rather than through yet
     * another compensating audit row: $actor, when supplied, is the
     * PlatformAdmin who actually performed the action and becomes
     * actor_id. The session OWNER is never lost — it is recorded
     * explicitly as session_owner_platform_admin_id in metadata, so
     * "who acted" and "whose session" are two separate, both-present
     * facts rather than one conflated field.
     *
     * $actor is optional so that every pre-existing caller keeps
     * byte-for-byte identical behavior when it is omitted (the actor
     * then falls back to the session owner, exactly as before) —
     * mirroring SupportAccessRequestService::expire()'s own established
     * optional-$actor shape. PlatformFirmIntegrationBoundedAccessService
     * — the only application caller — passes the real acting admin at
     * every call site.
     */
    public function logSessionAudit(SupportAccessSession $session, string $eventType, ?PlatformAdmin $actor = null): void
    {
        (new TenantContextService)->runWithFirmContext($session->firm_id, function () use ($session, $eventType, $actor) {
            DB::table('security_events')->insert([
                'firm_id' => $session->firm_id,
                'actor_type' => PlatformAdmin::class,
                'actor_id' => $actor?->id ?? $session->platform_admin_id,
                'event_type' => $eventType,
                'category' => 'support_access',
                'metadata' => json_encode([
                    'support_access_session_id' => $session->id,
                    'expires_at' => $session->expires_at?->toIso8601String(),
                    'session_owner_platform_admin_id' => $session->platform_admin_id,
                    'acting_platform_admin_id' => $actor?->id ?? $session->platform_admin_id,
                ]),
                'created_at' => now(),
            ]);
        });
    }
}
