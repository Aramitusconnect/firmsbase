<?php

namespace App\Services;

use App\Enums\TrustAccountStatus;
use App\Models\Firm;
use App\Models\TrustAccount;

/**
 * TrustAccountService — the only writer of trust_accounts. Gated on
 * TrustEligibilityService first — a firm without approved trust setup
 * cannot open a trust account at all.
 */
class TrustAccountService
{
    public function __construct(private readonly TrustEligibilityService $eligibility) {}

    public function open(Firm $firm, string $accountName, ?string $bankNameReference = null): TrustAccount
    {
        $this->eligibility->assertEligible($firm);

        return (new TenantContextService)->runWithFirmContext($firm, fn () => TrustAccount::create([
            'firm_id' => $firm->id,
            'account_name' => $accountName,
            'bank_name_reference' => $bankNameReference,
            'status' => TrustAccountStatus::Active,
            'opened_at' => now(),
        ]));
    }

    public function suspend(Firm $firm, TrustAccount $account): TrustAccount
    {
        $this->eligibility->assertEligible($firm);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($account) {
            $account->update(['status' => TrustAccountStatus::Suspended]);

            return $account->fresh();
        });
    }

    public function close(Firm $firm, TrustAccount $account): TrustAccount
    {
        $this->eligibility->assertEligible($firm);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($account) {
            $account->update(['status' => TrustAccountStatus::Closed]);

            return $account->fresh();
        });
    }
}
