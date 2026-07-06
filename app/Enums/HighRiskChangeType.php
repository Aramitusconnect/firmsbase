<?php

namespace App\Enums;

/**
 * HighRiskChangeType — the closed set of change types that require the
 * reason-required, two-person-approval-ready workflow foundation. This
 * enum enumerates WHICH kinds of changes are high-risk; it does not
 * execute any of them. Trust/IOLTA money movement and production
 * deletion execution remain entirely out of scope for Phase 7.
 *
 * Phase 16 addition (additive only, no existing case changed):
 * DedicatedLegalSpecialistApproval gates a dedicated-mode firm whose
 * customer_type is legal_specialist (law_firm + dedicated needs no
 * such gate) — see DedicatedCustomerTypeApprovalService.
 * OperatingOnlyTrustDisableAcknowledgment is the platform-admin-approval
 * half of an operating-only dedicated law firm's trust/IOLTA-disabled
 * posture — see TrustIoltaDisableAcknowledgmentService. Both reuse
 * this existing two-person-approval workflow exactly as
 * TrustModeActivationService (Phase 13) already does; no second
 * approval system is introduced.
 *
 * Phase 17 addition (additive only, no existing case changed):
 * CryptographicKeyDestruction gates the two-person approval required
 * before a firm's envelope encryption key(s) may be irreversibly
 * destroyed (crypto-shredding) — see KeyDestructionApprovalService. This
 * is deliberately a NEW case, not a reuse of ProductionDataDeletion
 * (approved decision #2): key destruction is a distinct, uniquely
 * irreversible action with its own clearance chain (offboarding export,
 * retention, legal hold), and ProductionDataDeletion is reserved for
 * deletion_requests governance (DeletionApprovalService) only.
 */
enum HighRiskChangeType: string
{
    case TrustModeActivation = 'trust_mode_activation';
    case ProductionDataDeletion = 'production_data_deletion';
    case PaymentTrustSettingChange = 'payment_trust_setting_change';
    case EmergencySupportAccess = 'emergency_support_access';
    case DedicatedLegalSpecialistApproval = 'dedicated_legal_specialist_approval';
    case OperatingOnlyTrustDisableAcknowledgment = 'operating_only_trust_disable_acknowledgment';
    case CryptographicKeyDestruction = 'cryptographic_key_destruction';
}
