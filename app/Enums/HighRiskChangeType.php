<?php

namespace App\Enums;

/**
 * HighRiskChangeType — the closed set of change types that require the
 * reason-required, two-person-approval-ready workflow foundation. This
 * enum enumerates WHICH kinds of changes are high-risk; it does not
 * execute any of them. Trust/IOLTA money movement and production
 * deletion execution remain entirely out of scope for Phase 7.
 */
enum HighRiskChangeType: string
{
    case TrustModeActivation = 'trust_mode_activation';
    case ProductionDataDeletion = 'production_data_deletion';
    case PaymentTrustSettingChange = 'payment_trust_setting_change';
    case EmergencySupportAccess = 'emergency_support_access';
}
