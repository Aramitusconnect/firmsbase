<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TrustLedgerEntry;
use App\Models\User;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustEligibilityService;

/**
 * TrustLedgerEntryPolicy — mirrors TrustAccountPolicy/TrustLedgerPolicy's
 * shape. viewAny()/view() only — TrustLedgerEntryResource has no
 * Create/Edit page anywhere (rule #1: the model's own booted() guard
 * already blocks update/delete, but a raw unguarded create() is still a
 * real vulnerability the guard doesn't catch, so no Filament code path
 * is wired to create one directly at all; every entry is posted only by
 * TrustDepositService::post()/TrustTransferRequestService::apply()/
 * TrustRefundRequestService::complete()/
 * TrustHighRiskAdjustmentService::secondApprove()/
 * TrustLedgerEntryReversalService::reverse()).
 *
 * trust_ledger_entries deliberately does NOT use BelongsToTenant (see
 * TrustLedgerEntry's own model docblock), so this policy's explicit
 * `firm_id` comparison is not merely a convenience check duplicating a
 * global scope — for this table it is one of the only two tenant
 * guards that exist at all (the other being FORCE RLS at the database
 * layer, which only applies once `app.current_firm_id` is actually
 * set for the current database session).
 */
class TrustLedgerEntryPolicy
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

    public function view(User $user, TrustLedgerEntry $entry): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $entry->firm_id
            && $this->accessPolicy->canRequest($firmUser->role)
            && $this->eligibility->isEligible($firmUser->firm);
    }
}
