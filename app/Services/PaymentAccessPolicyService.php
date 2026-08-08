<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmUserRole;

/**
 * PaymentAccessPolicyService — role ceiling for the Manual Client
 * Payments cluster (Firm Feature Manifest §6, cross-cutting finding
 * #11: "the safest, highest-value Billing feature to expose first").
 * Same plain in_array() shape as TimeExpenseAccessPolicyService/
 * TaskCrudAccessPolicyService/ClientCrmAccessPolicyService — no second
 * source of truth, every Payment Action in this cluster calls this
 * service directly rather than re-deriving a role list.
 *
 * Role ceilings, and the reasoning behind each:
 *
 *   - VIEW_PAYMENT (list/view a recorded Payment): every active staff
 *     role, including Receptionist and BillingStaff. Mirrors
 *     TimeExpenseAccessPolicyService::VIEW_ROLES exactly — a payment
 *     record is not confidential-by-role the way an approval decision
 *     is, and front-desk/billing-office staff routinely need to
 *     confirm "has this client paid" without being able to record one
 *     themselves.
 *
 *   - RECORD_PAYMENT (ManualPaymentService::submit() — the "record an
 *     externally-received payment" action, the point of no return
 *     before it is applied to an invoice/installment): FirmOwner,
 *     Attorney, BillingStaff only. Mirrors the Firm Feature Manifest
 *     §7 Trust convention ("Request: FirmOwner, Attorney, BillingStaff")
 *     — recording that money was received is core billing-office work
 *     (BillingStaff is included, unlike TimeExpenseAccessPolicyService::
 *     TIME_ENTRY_MANAGEMENT_ROLES which excludes it), but is still
 *     fee-earner/ownership-adjacent enough that Paralegal, Legal
 *     Assistant, and Receptionist are excluded — role ceilings in this
 *     codebase may only be narrowed, never widened by convenience.
 */
class PaymentAccessPolicyService
{
    private const VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
        FirmUserRole::Receptionist,
        FirmUserRole::BillingStaff,
    ];

    private const RECORD_PAYMENT_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::BillingStaff,
    ];

    public function canViewPayment(FirmUserRole $role): bool
    {
        return in_array($role, self::VIEW_ROLES, true);
    }

    public function canRecordPayment(FirmUserRole $role): bool
    {
        return in_array($role, self::RECORD_PAYMENT_ROLES, true);
    }
}
