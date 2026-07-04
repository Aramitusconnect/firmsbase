<?php

namespace App\Enums;

/**
 * LicenseStatus — firm_licenses.license_status. The 12 canonical values
 * from the master plan's commercial lifecycle. Status label only in
 * Phase 1 — no lifecycle transition rules, downgrade logic, or billing
 * enforcement is built yet (that is Phase 6+).
 */
enum LicenseStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case GracePeriod = 'grace_period';
    case ReadOnly = 'read_only';
    case Restricted = 'restricted';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case ExportOnly = 'export_only';
    case Manual = 'manual';
    case Lifetime = 'lifetime';
}
