<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TrustAccount;
use App\Models\User;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustEligibilityService;

/**
 * TrustAccountPolicy — mirrors PaymentPolicy/InvoicePolicy's shape:
 * declares ONLY viewAny()/view(). There is no CreateRecord/EditRecord
 * page for TrustAccountResource at all (Firm Feature Manifest §7 /
 * Trust-module rule #3: "every mutation must be a Filament Action
 * calling exactly one Trust*Service method — never a
 * CreateRecord/EditRecord page bound to model fields") — Open/Suspend/
 * Close are dedicated Actions in
 * TrustAccountResource\Actions\* that check TrustAccessPolicyService
 * directly inside their own visible()/action() closures.
 *
 * View access is additionally gated on
 * TrustEligibilityService::isEligible() (rule #4: "Trust-mode
 * eligibility gates ALL visibility, not just actions") and narrowed to
 * TrustAccessPolicyService::canRequest() — Paralegal/LegalAssistant/
 * Receptionist have no Trust action available to them anywhere in this
 * module, so they are not granted read access to trust ledger data
 * either.
 */
class TrustAccountPolicy
{
    public function __construct(
        private readonly TrustAccessPolicyService $accessPolicy,
        private readonly TrustEligibilityService $eligibility,
    ) {}

    public function viewAny(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && $this->accessPolicy->canRequest($firmUser->role)
            && $this->eligibility->isEligible($firmUser->firm);
    }

    public function view(User $user, TrustAccount $account): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $account->firm_id
            && $this->accessPolicy->canRequest($firmUser->role)
            && $this->eligibility->isEligible($firmUser->firm);
    }
}
