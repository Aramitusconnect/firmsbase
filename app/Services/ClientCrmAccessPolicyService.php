<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmUserRole;

/**
 * ClientCrmAccessPolicyService — role ceiling for the Client/Contact/
 * FirmLead/Conflict-Check cluster (Firm Feature Manifest §1). Same
 * single-tier-per-action shape as IntegrationAccessPolicyService/
 * MatterAccessPolicyService (a plain in_array() check against a
 * per-action allowlist) — no requester/approver split except where an
 * action carries the same "consequential, narrower ceiling" reasoning
 * TrustAccessPolicyService applies to its own request/approve split.
 *
 * Role ceilings, and the reasoning behind each (per this mission's own
 * instruction to document the "why", mirroring the terse-comment style
 * of IntegrationAccessPolicyService/TrustAccessPolicyService):
 *
 *   - VIEW (list/view Client, Contact, FirmLead; view Conflict Check
 *     runs/results): every active staff role, including Receptionist
 *     and BillingStaff. Nothing in this cluster is confidential-by-role
 *     the way Trust/Billing money-movement is — front desk, billing,
 *     and legal staff alike all legitimately need to look up a client/
 *     contact/lead record to do their own job (answer a call, send an
 *     invoice, prepare a matter).
 *
 *   - MANAGE_CONTACT / MANAGE_LEAD (create/edit a Contact; create/edit
 *     a FirmLead — never its status field, see FirmLeadResource's own
 *     docblock): FirmOwner, Attorney, Paralegal, LegalAssistant,
 *     Receptionist. Receptionist is included here (unlike every other
 *     mutating ability below) because front-desk staff routinely take
 *     intake calls and manage the contact directory — that is core to
 *     the role. BillingStaff is excluded: their job is billing, not
 *     case intake, and role ceilings in this codebase may only be
 *     narrowed, never widened by convenience.
 *
 *   - EDIT_CLIENT (safe-field profile edits on an existing Client) /
 *     CONVERT_LEAD ("+ Add Client", "Convert to Client" —
 *     LeadConversionService::convert()): FirmOwner, Attorney,
 *     Paralegal, LegalAssistant only. Turning a lead into a real,
 *     billable client relationship (or editing an existing client's
 *     record) is more consequential than logging an intake call —
 *     Receptionist may take the call and create the lead
 *     (MANAGE_LEAD), but not finalize the client relationship or edit
 *     an existing client's profile. This mirrors the same "narrower
 *     ceiling as action severity increases" pattern
 *     TrustAccessPolicyService already establishes between its
 *     request-tier and approve-tier roles.
 *
 *   - RUN_CONFLICT_CHECK: same ceiling as CONVERT_LEAD — running a
 *     check is a case-management action gated on the acting user
 *     already being authorized for the matter (MatterAccessPolicyService
 *     is checked independently, in addition to this role ceiling, by
 *     every caller).
 *
 *   - RESOLVE_CONFLICT_RESULT: FirmOwner, Attorney only. The actual
 *     legal judgment of whether a possible match is a real conflict of
 *     interest is the narrowest ceiling in this cluster, matching
 *     Trust's own "Approve: FirmOwner, Attorney only" convention (Firm
 *     Feature Manifest §7).
 */
class ClientCrmAccessPolicyService
{
    private const VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
        FirmUserRole::Receptionist,
        FirmUserRole::BillingStaff,
    ];

    private const INTAKE_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
        FirmUserRole::Receptionist,
    ];

    private const CLIENT_MANAGEMENT_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
    ];

    private const CONFLICT_RESOLUTION_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
    ];

    public function canView(FirmUserRole $role): bool
    {
        return in_array($role, self::VIEW_ROLES, true);
    }

    public function canManageContact(FirmUserRole $role): bool
    {
        return in_array($role, self::INTAKE_ROLES, true);
    }

    public function canManageLead(FirmUserRole $role): bool
    {
        return in_array($role, self::INTAKE_ROLES, true);
    }

    public function canEditClient(FirmUserRole $role): bool
    {
        return in_array($role, self::CLIENT_MANAGEMENT_ROLES, true);
    }

    public function canConvertLead(FirmUserRole $role): bool
    {
        return in_array($role, self::CLIENT_MANAGEMENT_ROLES, true);
    }

    public function canRunConflictCheck(FirmUserRole $role): bool
    {
        return in_array($role, self::CLIENT_MANAGEMENT_ROLES, true);
    }

    public function canResolveConflictResult(FirmUserRole $role): bool
    {
        return in_array($role, self::CONFLICT_RESOLUTION_ROLES, true);
    }
}
