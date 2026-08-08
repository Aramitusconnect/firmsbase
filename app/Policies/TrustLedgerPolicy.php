<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TrustLedger;
use App\Models\User;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustEligibilityService;

/**
 * TrustLedgerPolicy — mirrors TrustAccountPolicy's shape exactly, for
 * the same reason. viewAny()/view() only; every mutation
 * (open/freeze/close a ledger, deposit/transfer/refund/adjustment
 * request-approve-post lifecycles) is a dedicated Action in
 * TrustLedgerResource\Actions\*.
 */
class TrustLedgerPolicy
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

    public function view(User $user, TrustLedger $ledger): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $ledger->firm_id
            && $this->accessPolicy->canRequest($firmUser->role)
            && $this->eligibility->isEligible($firmUser->firm);
    }
}
