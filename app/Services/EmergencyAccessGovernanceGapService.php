<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * EmergencyAccessGovernanceGapService — documents, precisely and
 * honestly, the emergency support access governance gap identified in
 * Section 27 and its Section 39C remediation. Purely declarative — it
 * does not itself enforce anything.
 *
 * Section 39C fixed the real behavior: SupportAccessRequestService::request()
 * now raises a high_risk_change_requests row
 * (HighRiskChangeType::EmergencySupportAccess) for every emergency
 * request, and SupportAccessPolicyService::canStartSession() now
 * denies session start unless that linked request has reached Approved
 * (SupportAccessRequestService::isEmergencyHighRiskApproved()) —
 * confirmed by direct inspection and by
 * tests/Feature/Security/SupportAccess/EmergencySupportHighRiskApprovalTest.php.
 * No second approval/audit system was introduced: the existing,
 * unmodified HighRiskPlatformChangePolicyService and security_events
 * table are reused exactly as they already were for
 * trust_mode_activation and the project's other existing high-risk
 * change types. The link back to the SupportAccessRequest is stored in
 * high_risk_change_requests' existing metadata json column — no schema
 * change was needed.
 *
 * The ComplianceGapRegistryService entry for
 * emergency_support_access_high_risk_approval_not_wired is
 * intentionally left open/untouched by Section 39C:
 * GapRegisterItem::$status is a plain, unenforced string always set to
 * 'open' today, with nothing in ComplianceGapRegistryService that
 * reads, filters, or reports on it — no resolved/remediated-state
 * lifecycle exists yet. Marking that registry entry "resolved" would
 * have no real, honestly-verifiable meaning until such a lifecycle is
 * built; a future section owns adding it.
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
     * SupportAccessSession), by direct inspection. As of Section 39C,
     * all six required controls are real and enforced.
     *
     * @var array<int, string>
     */
    private const CURRENT_CONTROLS = [
        'reason_required',
        'time_limit',
        'automatic_notification',
        'full_audit_trail',
        'platform_approval',
        'high_risk_change_request',
    ];

    public function result(): GovernanceMappingResult
    {
        return new GovernanceMappingResult(
            item_key: 'emergency_support_access_high_risk_approval_not_wired',
            item_label: 'Emergency support access high-risk approval wiring',
            owning_class: \App\Services\SupportAccessPolicyService::class,
            status: GovernanceMappingStatus::Implemented,
            notes: 'Section 39C wired the fix: SupportAccessRequestService::request() raises a high_risk_change_requests row (HighRiskChangeType::EmergencySupportAccess) for every emergency request, and SupportAccessPolicyService::canStartSession() denies session start unless that linked request has reached Approved (SupportAccessRequestService::isEmergencyHighRiskApproved()). All six required controls (reason_required, time_limit, automatic_notification, full_audit_trail, platform_approval, high_risk_change_request) are now real and enforced. No second approval/audit system was introduced — the existing HighRiskPlatformChangePolicyService and security_events table are reused unmodified. The ComplianceGapRegistryService entry for emergency_support_access_high_risk_approval_not_wired remains open/untouched pending a registry-level resolved-state lifecycle (GapRegisterItem has no such mechanism today).',
        );
    }

    public function isHighRiskApprovalWired(): bool
    {
        return true;
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
