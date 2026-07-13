<?php

namespace App\Services;

use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessType;
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

        if ($request->isEmergency()) {
            if (trim((string) $request->emergency_justification) === '') {
                return SupportAccessDecision::deny('emergency access requires emergency_justification');
            }

            if (! (new SupportAccessRequestService())->isEmergencyHighRiskApproved($request)) {
                return SupportAccessDecision::deny('emergency access requires platform high-risk approval before a session may start');
            }

            return SupportAccessDecision::allow();
        }

        if ($request->status !== SupportAccessRequestStatus::Approved) {
            return SupportAccessDecision::deny('support access requires firm approval unless emergency');
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
        (new TenantContextService())->runWithFirmContext($request->firm_id, function () use ($request, $eventType) {
            DB::table('security_events')->insert([
                'firm_id' => $request->firm_id,
                'actor_type' => \App\Models\PlatformAdmin::class,
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

    public function logSessionAudit(SupportAccessSession $session, string $eventType): void
    {
        (new TenantContextService())->runWithFirmContext($session->firm_id, function () use ($session, $eventType) {
            DB::table('security_events')->insert([
                'firm_id' => $session->firm_id,
                'actor_type' => \App\Models\PlatformAdmin::class,
                'actor_id' => $session->platform_admin_id,
                'event_type' => $eventType,
                'category' => 'support_access',
                'metadata' => json_encode([
                    'support_access_session_id' => $session->id,
                    'expires_at' => $session->expires_at?->toIso8601String(),
                ]),
                'created_at' => now(),
            ]);
        });
    }
}
