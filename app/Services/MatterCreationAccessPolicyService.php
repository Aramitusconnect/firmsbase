<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmUserRole;

/**
 * MatterCreationAccessPolicyService — role ceiling for the two new
 * Matters-domain actions this Tier 3 mission item adds: "+ Add Matter"
 * (MatterCreationService::create()) and "Open Matter"
 * (MatterOpeningService::openMatter()). One dedicated *AccessPolicyService
 * class per domain (project convention — see ClientCrmAccessPolicyService,
 * BillingAccessPolicyService, PaymentAccessPolicyService, etc.), kept
 * separate from MatterAccessPolicyService (Phase 15's per-record
 * "can this user open THIS matter" boundary — an orthogonal concern
 * this service does not duplicate or replace; every caller below
 * checks both).
 *
 * Role ceiling: FirmOwner, Attorney, Paralegal, LegalAssistant —
 * deliberately the exact same set as ClientCrmAccessPolicyService::
 * CLIENT_MANAGEMENT_ROLES (its own "CONVERT_LEAD"/"RUN_CONFLICT_CHECK"
 * ceiling), matching the Client/CRM module's own precedent for
 * consequential-but-not-money-moving actions: creating a matter (or
 * moving one out of Draft into Open) is more consequential than
 * ordinary CRUD, but does not itself touch billing/trust funds the way
 * TrustAccessPolicyService's narrower "Approve" ceiling protects.
 * Receptionist/BillingStaff are excluded — narrower than
 * ClientCrmAccessPolicyService::VIEW_ROLES on purpose (role ceilings in
 * this codebase may only be narrowed, never widened by convenience).
 */
class MatterCreationAccessPolicyService
{
    private const MATTER_MANAGEMENT_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
    ];

    public function canCreateMatter(FirmUserRole $role): bool
    {
        return in_array($role, self::MATTER_MANAGEMENT_ROLES, true);
    }

    public function canOpenMatter(FirmUserRole $role): bool
    {
        return in_array($role, self::MATTER_MANAGEMENT_ROLES, true);
    }
}
