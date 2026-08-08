<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentAccessPolicyService;

/**
 * PaymentPolicy — mirrors ExpensePolicy/TimeEntryPolicy's shape, but
 * deliberately declares ONLY viewAny()/view(). Manifest rule #4: this
 * module is create-only (via RecordPaymentAction, an Action calling
 * ManualPaymentService::submit() — never a bare `Payment::create()`)
 * plus read/list — there is no CreateRecord/EditRecord page for
 * PaymentResource at all, so a `create()`/`update()` policy method
 * would imply an authorization surface that doesn't exist. Record
 * Payment's own role ceiling is checked directly against
 * PaymentAccessPolicyService::canRecordPayment() inside
 * RecordPaymentAction/RecordClientPaymentAction's own closures,
 * matching ConvertLeadToClientAction's established convention of
 * checking an AccessPolicyService directly for an Action that has no
 * corresponding CRUD policy method.
 */
class PaymentPolicy
{
    public function __construct(
        private readonly PaymentAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canViewPayment($firmUser->role);
    }

    public function view(User $user, Payment $payment): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $payment->firm_id
            && $this->accessPolicy->canViewPayment($firmUser->role);
    }
}
