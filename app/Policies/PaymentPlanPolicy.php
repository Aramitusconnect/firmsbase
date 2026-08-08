<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PaymentPlan;
use App\Models\User;
use App\Services\BillingAccessPolicyService;

/**
 * PaymentPlanPolicy — mirrors InvoicePolicy/PaymentPolicy's shape:
 * viewAny()/view() only. There is no generic Create/Edit page for
 * PaymentPlanResource — every mutation (create/activate/renegotiate/
 * cancel/markDefaulted) is a dedicated PaymentPlanResource\Actions\*
 * Action calling exactly one PaymentPlanService method, each checking
 * BillingAccessPolicyService directly inside its own closures.
 */
class PaymentPlanPolicy
{
    public function __construct(
        private readonly BillingAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canViewBilling($firmUser->role);
    }

    public function view(User $user, PaymentPlan $paymentPlan): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $paymentPlan->firm_id
            && $this->accessPolicy->canViewBilling($firmUser->role);
    }
}
