<?php

namespace App\Services;

use App\Enums\TrustLedgerStatus;
use App\Exceptions\TrustLedgerHasResidualBalanceException;
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
 *
 * freeze()/close() route through TrustConcurrencyLockService::
 * withLockedBalances() (Trust & Accounting Integrity Hardening,
 * Mission 1.2) — the same lock every money-moving service acquires —
 * so a status transition can never race a concurrent deposit/transfer/
 * refund/adjustment for the same ledger, and close() can read the
 * ledger's true, race-safe balance before deciding whether zero-balance
 * closure is allowed.
 */
class TrustLedgerService
{
    public function __construct(
        private readonly TrustEligibilityService $eligibility,
        private readonly TenantSafeTrustPolicyService $tenantSafePolicy,
        private readonly TrustConcurrencyLockService $lockService,
    ) {}

    public function open(Firm $firm, TrustAccount $account, Client $client): TrustLedger
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustAccountBelongsToFirm($account, $firm);

        if ($client->firm_id !== $firm->id) {
            throw new \RuntimeException('Client does not belong to this firm.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $account, $client) {
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

    /**
     * Active → Frozen only. Freezing an already-Frozen or an already-
     * Closed ledger is rejected rather than silently accepted, matching
     * this codebase's existing lifecycle-guard convention elsewhere
     * (e.g. "This transfer request is not awaiting approval.") — every
     * status transition is a single, deliberate, auditable action.
     */
    public function freeze(Firm $firm, TrustLedger $ledger): TrustLedger
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustLedgerBelongsToFirm($ledger, $firm);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($ledger) {
            return $this->lockService->withLockedBalances($ledger, null, function ($lockedBalance, $lockedMatterBalance, $lockedLedger) {
                if ($lockedLedger->status !== TrustLedgerStatus::Active) {
                    throw new \RuntimeException('Only an Active trust ledger can be frozen.');
                }

                $lockedLedger->update(['status' => TrustLedgerStatus::Frozen]);

                return $lockedLedger->fresh();
            });
        });
    }

    /**
     * Active or Frozen → Closed, but only with a zero balance (read from
     * the row locked by TrustConcurrencyLockService, so it cannot be
     * fooled by a stale cache or a concurrent in-flight deposit).
     * Closing an already-Closed ledger is rejected, not a silent no-op.
     */
    public function close(Firm $firm, TrustLedger $ledger): TrustLedger
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustLedgerBelongsToFirm($ledger, $firm);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($ledger) {
            return $this->lockService->withLockedBalances($ledger, null, function ($lockedBalance, $lockedMatterBalance, $lockedLedger) {
                if ($lockedLedger->status === TrustLedgerStatus::Closed) {
                    throw new \RuntimeException('This trust ledger has already been closed.');
                }

                if ($lockedBalance->balance_cents !== 0) {
                    throw new TrustLedgerHasResidualBalanceException($lockedLedger, $lockedBalance->balance_cents);
                }

                $lockedLedger->update(['status' => TrustLedgerStatus::Closed]);

                return $lockedLedger->fresh();
            });
        });
    }
}
