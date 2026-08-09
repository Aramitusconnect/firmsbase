<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PaymentRequestAccessPolicyService;

/**
 * PaymentRequestPolicy — mirrors PaymentPolicy's shape exactly:
 * viewAny()/view() only. Create/activate/revoke are Action-based
 * (CreatePaymentRequestAction/ActivatePaymentRequestAction/
 * RevokePaymentRequestAction), each checking
 * PaymentRequestAccessPolicyService::canManagePaymentRequest()
 * directly in its own closure — never a bare
 * PaymentRequest::create()/update() reachable through a generic
 * Filament Create/Edit page.
 */
class PaymentRequestPolicy
{
    public function __construct(
        private readonly PaymentRequestAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canViewPaymentRequest($firmUser->role);
    }

    public function view(User $user, PaymentRequest $paymentRequest): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $paymentRequest->firm_id
            && $this->accessPolicy->canViewPaymentRequest($firmUser->role);
    }
}
