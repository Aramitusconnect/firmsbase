<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmUserRole;

/**
 * DocumentRequestAccessPolicyService — role ceiling for the Document
 * Requests + Document Chase cluster (Firm Feature Manifest §5:
 * "Document Requests — READY... no storage dependency — the safest win
 * in this whole category" / "Document Chase — PARTIAL... computes
 * eligibility/logs only, never actually dispatches a reminder"). Same
 * plain in_array() single-tier-per-action shape as
 * ClientCrmAccessPolicyService/ConsentAccessPolicyService — no second
 * source of truth; every Action/Policy in this cluster calls this
 * service directly rather than re-deriving a role list.
 *
 * Role ceilings, and the reasoning behind each:
 *
 *   - VIEW (list/view a DocumentRequest, its DocumentRequestItem rows,
 *     DocumentChaseRule rows, and DocumentChaseEvent history): every
 *     active staff role, including Receptionist and BillingStaff.
 *     Mirrors ClientCrmAccessPolicyService::VIEW_ROLES/
 *     ConsentAccessPolicyService::VIEW_ROLES exactly — knowing which
 *     documents a client still owes, or whether a reminder rule fired,
 *     is not confidential-by-role.
 *
 *   - MANAGE_REQUEST (DocumentRequestService::create() — "+ New Document
 *     Request" — and every per-item status transition: markViewed/
 *     markSubmitted/markUnderReview/approve/reject/requestReplacement/
 *     waive): FirmOwner, Attorney, Paralegal, LegalAssistant only.
 *     Deliberately narrower than ClientCrmAccessPolicyService::
 *     INTAKE_ROLES/ConsentAccessPolicyService::INTAKE_ROLES (which both
 *     include Receptionist) — reviewing/approving/rejecting a client's
 *     submitted legal document is a case-management judgment call, the
 *     same class of consequence ClientCrmAccessPolicyService reserves
 *     for CLIENT_MANAGEMENT_ROLES (editing an existing Client, converting
 *     a lead) rather than its broader front-desk INTAKE_ROLES. Role
 *     ceilings in this codebase may only be narrowed, never widened by
 *     convenience, so Receptionist/BillingStaff are excluded here even
 *     though they may capture a Consent or manage a Contact.
 *
 *   - MANAGE_CHASE_RULE (create/edit/pause/archive a DocumentChaseRule —
 *     firm-wide reminder-cadence configuration, direct safe Eloquent
 *     CRUD, confirmed by direct source read: no dedicated write service
 *     exists, only DocumentChaseSchedulerService's read-only
 *     applicableRule()/DocumentChaseService's own event-logging):
 *     FirmOwner, Attorney only. Narrower still than MANAGE_REQUEST —
 *     a chase rule is firm-wide policy configuration (applies to every
 *     client's outstanding items, not one specific case), the same
 *     "narrowest ceiling for firm-wide configuration" reasoning
 *     ClientCrmAccessPolicyService::CONFLICT_RESOLUTION_ROLES and
 *     TrustAccessPolicyService's own approve-tier already establish in
 *     this codebase.
 */
class DocumentRequestAccessPolicyService
{
    private const VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
        FirmUserRole::Receptionist,
        FirmUserRole::BillingStaff,
    ];

    private const MANAGE_REQUEST_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
    ];

    private const MANAGE_CHASE_RULE_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
    ];

    public function canView(FirmUserRole $role): bool
    {
        return in_array($role, self::VIEW_ROLES, true);
    }

    public function canManageRequest(FirmUserRole $role): bool
    {
        return in_array($role, self::MANAGE_REQUEST_ROLES, true);
    }

    public function canManageChaseRule(FirmUserRole $role): bool
    {
        return in_array($role, self::MANAGE_CHASE_RULE_ROLES, true);
    }
}
