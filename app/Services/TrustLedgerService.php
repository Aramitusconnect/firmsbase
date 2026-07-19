<?php

namespace App\Services;

use App\Enums\TrustLedgerStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\TrustAccount;
use App\Models\TrustBalance;
use App\Models\TrustLedger;

/**
 * TrustLedgerService — the only writer of trust_ledgers. Also creates
 * the paired trust_balances row (starting at zero) at the same time,
 * since a ledger without a balance row would be an invalid state no
 * other service should ever have to defend against.
 */
class TrustLedgerService
{
    public function __construct(
        private readonly TrustEligibilityService $eligibility,
        private readonly TenantSafeTrustPolicyService $tenantSafePolicy,
    ) {
    }

    public function open(Firm $firm, TrustAccount $account, Client $client): TrustLedger
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustAccountBelongsToFirm($account, $firm);

        if ($client->firm_id !== $firm->id) {
            throw new \RuntimeException('Client does not belong to this firm.');
        }

        return (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $account, $client) {
            $ledger = TrustLedger::create([
                'firm_id' => $firm->id,
                'trust_account_id' => $account->id,
                'client_id' => $client->id,
                'status' => TrustLedgerStatus::Active,
            ]);

            TrustBalance::create([
                'firm_id' => $firm->id,
                'trust_ledger_id' => $ledger->id,
                'balance_cents' => 0,
                'last_recomputed_at' => now(),
            ]);

            return $ledger;
        });
    }

    public function freeze(Firm $firm, TrustLedger $ledger): TrustLedger
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustLedgerBelongsToFirm($ledger, $firm);

        return (new TenantContextService())->runWithFirmContext($firm, function () use ($ledger) {
            $ledger->update(['status' => TrustLedgerStatus::Frozen]);

            return $ledger->fresh();
        });
    }

    public function close(Firm $firm, TrustLedger $ledger): TrustLedger
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustLedgerBelongsToFirm($ledger, $firm);

        return (new TenantContextService())->runWithFirmContext($firm, function () use ($ledger) {
            $ledger->update(['status' => TrustLedgerStatus::Closed]);

            return $ledger->fresh();
        });
    }
}
