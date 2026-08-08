<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmUserRole;

/**
 * ConsentAccessPolicyService — role ceiling for the Communication
 * Consent cluster (Firm Feature Manifest §16: "Communication consent —
 * READY. ConsentService — solid, append-only audit trail, safe to
 * expose read/manual-entry UI today independent of dispatch going
 * live."). Same plain in_array() single-tier-per-action shape as
 * ClientCrmAccessPolicyService/PaymentAccessPolicyService — no second
 * source of truth; every Consent Action in this cluster calls this
 * service directly rather than re-deriving a role list.
 *
 * Role ceilings, and the reasoning behind each:
 *
 *   - VIEW (list/view a CommunicationConsent row and its
 *     CommunicationConsentEvent history): every active staff role,
 *     including Receptionist and BillingStaff. Mirrors
 *     ClientCrmAccessPolicyService::VIEW_ROLES/PaymentAccessPolicyService::
 *     VIEW_ROLES exactly — "can this client be contacted on channel X"
 *     is not confidential-by-role the way an approval decision is;
 *     front-desk and billing-office staff routinely need to check this
 *     before placing a call or sending a statement.
 *
 *   - CAPTURE (ConsentService::capture() — "Record Consent", the
 *     create-or-recapture write): FirmOwner, Attorney, Paralegal, Legal
 *     Assistant, Receptionist. Deliberately mirrors
 *     ClientCrmAccessPolicyService::INTAKE_ROLES exactly (per this
 *     mission's explicit instruction) — capturing a client's consent to
 *     be contacted is an intake-adjacent activity, the same class of
 *     work as logging an intake call or managing the contact directory.
 *     BillingStaff is excluded: their job is billing, not client
 *     intake/consent capture, and role ceilings in this codebase may
 *     only be narrowed, never widened by convenience.
 *
 *   - REVOKE (ConsentService::revoke() — withdrawing a previously
 *     granted consent): same ceiling as CAPTURE. Revoking is the direct
 *     inverse of capturing (e.g. a client calls in and asks to stop
 *     being contacted by SMS) and is handled by the same intake-adjacent
 *     staff who would have captured it in the first place — there is no
 *     "approval" step or money-movement analog here the way Trust's
 *     request/approve split has, so a narrower ceiling than CAPTURE
 *     would not track any real difference in consequence.
 */
class ConsentAccessPolicyService
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

    public function canView(FirmUserRole $role): bool
    {
        return in_array($role, self::VIEW_ROLES, true);
    }

    public function canCapture(FirmUserRole $role): bool
    {
        return in_array($role, self::INTAKE_ROLES, true);
    }

    public function canRevoke(FirmUserRole $role): bool
    {
        return in_array($role, self::INTAKE_ROLES, true);
    }
}
