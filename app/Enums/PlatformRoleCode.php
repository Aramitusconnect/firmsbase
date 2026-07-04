<?php

namespace App\Enums;

/**
 * PlatformRoleCode — the fixed catalog of platform-staff roles. Not a
 * database-driven catalog (no separate role table) — platform_roles is
 * purely a grant/assignment table keyed to this enum, mirroring how
 * Phase 1's FirmUserRole is a fixed enum rather than an admin-editable
 * table. Attaches to PlatformAdmin only, never to firm_users/users.
 */
enum PlatformRoleCode: string
{
    case SuperAdmin = 'super_admin';
    case PlatformAdmin = 'platform_admin';
    case SupportAgent = 'support_agent';
    case BillingAdmin = 'billing_admin';
    case SalesManager = 'sales_manager';
    case SalesRep = 'sales_rep';
    case ImplementationSpecialist = 'implementation_specialist';
    case SecurityAuditor = 'security_auditor';
    case ReadOnlyAuditor = 'read_only_auditor';
}
