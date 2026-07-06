<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * EmergencyAccessGovernanceGapService — documents, precisely and
 * honestly, the emergency support access governance gap identified in
 * Section 27. Does NOT fix the wiring — that is explicitly out of
 * scope for this section (a future emergency-access hardening phase
 * owns the fix). Purely declarative.
 *
 * Confirmed by direct inspection: HighRiskChangeType::EmergencySupportAccess
 * exists and HighRiskPlatformChangePolicyService can process it
 * generically (exercised only by HighRiskPlatformChangePolicyServiceTest
 * in isolation), but neither SupportAccessRequestService nor
 * SupportAccessPolicyService ever calls HighRiskPlatformChangePolicyService
 * — confirmed by a repository-wide search finding zero such call
 * sites. SupportAccessPolicyService::canStartSession() allows an
 * emergency request the moment emergency_justification is non-empty;
 * no platform-level approval step or high_risk_change_requests row is
 * ever created for it in the real emergency-access flow.
 */
class EmergencyAccessGovernanceGapService
{
    private const REQUIRED_CONTROLS = [
        'platform_approval',
        'reason_required',
        'time_limit',
        'automatic_notification',
        'full_audit_trail',
        'high_risk_change_request',
    ];

    /**
     * Controls confirmed present in the real emergency-access flow
     * (SupportAccessRequestService + SupportAccessPolicyService +
     * SupportAccessSession), by direct inspection.
     *
     * @var array<int, string>
     */
    private const CURRENT_CONTROLS = [
        'reason_required',
        'time_limit',
        'automatic_notification',
        'full_audit_trail',
    ];

    public function result(): GovernanceMappingResult
    {
        return new GovernanceMappingResult(
            item_key: 'emergency_support_access_high_risk_approval_not_wired',
            item_label: 'Emergency support access high-risk approval wiring',
            owning_class: \App\Services\SupportAccessPolicyService::class,
            status: GovernanceMappingStatus::PartiallyImplemented,
            notes: 'reason_required, time_limit, automatic_notification, and full_audit_trail are all real and enforced today (SupportAccessPolicyService::canStartSession()/logNotification()/logSessionAudit(), SupportAccessSession::isCurrentlyValid()). platform_approval and high_risk_change_request are missing: HighRiskChangeType::EmergencySupportAccess exists and HighRiskPlatformChangePolicyService can process it in isolation, but the real emergency-access flow never calls it — an emergency request is allowed the instant emergency_justification is non-empty, with no platform-admin approval step and no high_risk_change_requests row ever created. Not fixed here — see the emergency_support_access_high_risk_approval_not_wired gap-register item (High severity).',
        );
    }

    public function isHighRiskApprovalWired(): bool
    {
        return false;
    }

    /**
     * @return array<int, string>
     */
    public function requiredControls(): array
    {
        return self::REQUIRED_CONTROLS;
    }

    /**
     * @return array<int, string>
     */
    public function currentControls(): array
    {
        return self::CURRENT_CONTROLS;
    }

    /**
     * @return array<int, string>
     */
    public function missingControls(): array
    {
        return array_values(array_diff(self::REQUIRED_CONTROLS, self::CURRENT_CONTROLS));
    }
}
