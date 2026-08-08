<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmUserRole;

/**
 * BillingAccessPolicyService — role ceiling for the Invoices / Payment
 * Plans cluster (Firm Feature Manifest §6: "Invoices/Payment Plans
 * remain Tier 2... UNSAFE if exposed as raw CRUD... every mutation must
 * be a named Action calling the service"). Same plain in_array() shape
 * as PaymentAccessPolicyService/TimeExpenseAccessPolicyService — no
 * second source of truth; every Invoice/PaymentPlan Action in this
 * cluster calls this service directly rather than re-deriving a role
 * list.
 *
 * Role ceilings, and the reasoning behind each:
 *
 *   - VIEW (list/view an Invoice or PaymentPlan, view its Payments/
 *     Installments tab): every active staff role, including
 *     Receptionist and BillingStaff. Mirrors PaymentAccessPolicyService
 *     ::VIEW_ROLES exactly — knowing "what does this client owe" is
 *     not confidential-by-role the way an approval decision is.
 *
 *   - DRAFT tier — InvoiceDraftingService::draftFromTimeEntries()/
 *     createFlatFee()/addManualCharge()/submitForReview();
 *     PaymentPlanService::create() (drafting a schedule, still fully
 *     editable/undone before it becomes binding): FirmOwner, Attorney,
 *     BillingStaff. Mirrors PaymentAccessPolicyService::
 *     RECORD_PAYMENT_ROLES and the Firm Feature Manifest §7 Trust
 *     convention ("Request: FirmOwner, Attorney, BillingStaff") —
 *     drafting/submitting billing paperwork is core billing-office
 *     work, but Paralegal/LegalAssistant/Receptionist remain excluded
 *     (role ceilings in this codebase may only be narrowed, never
 *     widened by convenience).
 *
 *   - APPROVE tier — InvoiceDraftingService::approve()/send()/void();
 *     PaymentPlanService::activate()/renegotiate()/cancel()/
 *     markDefaulted(): FirmOwner, Attorney ONLY (BillingStaff
 *     excluded). Mirrors the Firm Feature Manifest §7 Trust convention
 *     ("Approve: FirmOwner, Attorney only, distinct approvers for
 *     adjustments") and TimeExpenseAccessPolicyService::
 *     TIME_ENTRY_APPROVAL_ROLES. Every one of these transitions is the
 *     point of no return before real financial liability attaches to
 *     the firm or the client (an invoice becomes collectible; a
 *     payment plan schedule becomes a binding, defaultable
 *     obligation) — this is deliberately narrower than the DRAFT tier
 *     above, same reasoning the manifest itself calls out: "Approve/
 *     Send/Void/MarkDefaulted narrower (FirmOwner/Attorney only,
 *     given financial-liability weight)". PaymentPlan::activate()/
 *     renegotiate()/cancel() are held to the same narrow ceiling as
 *     markDefaulted() — activating LOCKS the schedule (PaymentPlan's
 *     own docblock), renegotiating supersedes it with a brand new
 *     binding plan row, and cancelling terminates it outright; all
 *     three carry the same "commits/uncommits real money" weight as
 *     Approve/Send/Void on an Invoice, so BillingStaff is excluded
 *     from all of them, not just MarkDefaulted.
 */
class BillingAccessPolicyService
{
    private const VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
        FirmUserRole::Receptionist,
        FirmUserRole::BillingStaff,
    ];

    private const DRAFT_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::BillingStaff,
    ];

    private const APPROVE_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
    ];

    public function canViewBilling(FirmUserRole $role): bool
    {
        return in_array($role, self::VIEW_ROLES, true);
    }

    // -- Invoice -----------------------------------------------------

    public function canDraftInvoice(FirmUserRole $role): bool
    {
        return in_array($role, self::DRAFT_ROLES, true);
    }

    public function canApproveInvoice(FirmUserRole $role): bool
    {
        return in_array($role, self::APPROVE_ROLES, true);
    }

    public function canSendInvoice(FirmUserRole $role): bool
    {
        return in_array($role, self::APPROVE_ROLES, true);
    }

    public function canVoidInvoice(FirmUserRole $role): bool
    {
        return in_array($role, self::APPROVE_ROLES, true);
    }

    // -- Payment Plan --------------------------------------------------

    public function canCreatePaymentPlan(FirmUserRole $role): bool
    {
        return in_array($role, self::DRAFT_ROLES, true);
    }

    public function canActivatePaymentPlan(FirmUserRole $role): bool
    {
        return in_array($role, self::APPROVE_ROLES, true);
    }

    public function canRenegotiatePaymentPlan(FirmUserRole $role): bool
    {
        return in_array($role, self::APPROVE_ROLES, true);
    }

    public function canCancelPaymentPlan(FirmUserRole $role): bool
    {
        return in_array($role, self::APPROVE_ROLES, true);
    }

    public function canMarkPaymentPlanDefaulted(FirmUserRole $role): bool
    {
        return in_array($role, self::APPROVE_ROLES, true);
    }
}
