<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmUserRole;

/**
 * PaymentRequestAccessPolicyService — role ceiling for the Payment
 * Link / QR Routing cluster. Same plain in_array() shape as
 * PaymentAccessPolicyService, deliberately mirroring its exact role
 * ceilings: a PaymentRequest is a billing-office entry channel onto
 * the same canonical Payment/Trust domain Manual Client Payments
 * already writes to, so it inherits that cluster's own reasoning
 * rather than defining a new one.
 *
 *   - VIEW_ROLES: every active staff role — matches
 *     PaymentAccessPolicyService::VIEW_ROLES exactly.
 *
 *   - MANAGE_ROLES (create/activate/revoke a payment request): FirmOwner,
 *     Attorney, BillingStaff only — matches
 *     PaymentAccessPolicyService::RECORD_PAYMENT_ROLES exactly, since
 *     issuing a payment link is the same class of billing-office
 *     decision as recording a payment received outside the system.
 */
class PaymentRequestAccessPolicyService
{
    private const VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
        FirmUserRole::Receptionist,
        FirmUserRole::BillingStaff,
    ];

    private const MANAGE_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::BillingStaff,
    ];

    public function canViewPaymentRequest(FirmUserRole $role): bool
    {
        return in_array($role, self::VIEW_ROLES, true);
    }

    public function canManagePaymentRequest(FirmUserRole $role): bool
    {
        return in_array($role, self::MANAGE_ROLES, true);
    }
}
