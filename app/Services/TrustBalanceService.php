<?php

namespace App\Services;

use App\Models\Matter;
use App\Models\MatterTrustBalance;
use App\Models\TrustBalance;
use App\Models\TrustLedger;
use App\Models\TrustLedgerEntry;
use App\ValueObjects\TrustBalanceReconciliationResult;

/**
 * TrustBalanceService — the ONLY service allowed to write
 * trust_balances.balance_cents / matter_trust_balances.balance_cents
 * ("no silent balance mutation" — project rule). Every money-moving
 * service recomputes the cache in the SAME locked transaction as the
 * entry it just posted, by calling this service with the already-
 * locked row from TrustConcurrencyLockService — never a separate,
 * later job.
 */
class TrustBalanceService
{
    /**
     * Recomputes a ledger's cached balance from scratch as
     * SUM(trust_ledger_entries.amount_cents) for that ledger. If a
     * locked TrustBalance row is supplied (from
     * TrustConcurrencyLockService::withLockedBalances()), that exact
     * row is updated in place; otherwise the current row is fetched
     * fresh (used by standalone reconciliation runs, not by
     * money-moving services).
     */
    public function recomputeForLedger(TrustLedger $ledger, ?TrustBalance $lockedBalance = null): TrustBalance
    {
        $sum = (int) TrustLedgerEntry::query()
            ->where('trust_ledger_id', $ledger->id)
            ->sum('amount_cents');

        $balance = $lockedBalance ?? TrustBalance::query()->where('trust_ledger_id', $ledger->id)->firstOrFail();

        $balance->update([
            'balance_cents' => $sum,
            'last_recomputed_at' => now(),
        ]);

        return $balance->fresh();
    }

    /**
     * Recomputes one matter's attributed balance within a ledger, from
     * SUM(trust_ledger_entries.amount_cents) scoped additionally by
     * matter_id. This row must never go negative — the check itself
     * lives in TrustCrossMatterProtectionService, called by every
     * money-moving service BEFORE this recompute is allowed to persist
     * a debit.
     */
    public function recomputeForMatter(TrustLedger $ledger, Matter $matter, ?MatterTrustBalance $lockedMatterBalance = null): MatterTrustBalance
    {
        $sum = (int) TrustLedgerEntry::query()
            ->where('trust_ledger_id', $ledger->id)
            ->where('matter_id', $matter->id)
            ->sum('amount_cents');

        $matterBalance = $lockedMatterBalance ?? MatterTrustBalance::query()
            ->where('trust_ledger_id', $ledger->id)
            ->where('matter_id', $matter->id)
            ->first();

        if (! $matterBalance) {
            $matterBalance = MatterTrustBalance::create([
                'firm_id' => $ledger->firm_id,
                'trust_ledger_id' => $ledger->id,
                'matter_id' => $matter->id,
                'balance_cents' => 0,
            ]);
        }

        $matterBalance->update([
            'balance_cents' => $sum,
            'last_recomputed_at' => now(),
        ]);

        return $matterBalance->fresh();
    }

    /**
     * The standalone "cache vs. live sum" check — recomputes from
     * scratch and compares against whatever is currently cached,
     * without persisting a change. Called automatically at the start
     * of every TrustReconciliationService run, and independently
     * callable at any time.
     */
    public function reconcileCacheAgainstLedger(TrustLedger $ledger): TrustBalanceReconciliationResult
    {
        $cached = TrustBalance::query()->where('trust_ledger_id', $ledger->id)->firstOrFail();

        $computed = (int) TrustLedgerEntry::query()
            ->where('trust_ledger_id', $ledger->id)
            ->sum('amount_cents');

        return new TrustBalanceReconciliationResult(
            matches: $cached->balance_cents === $computed,
            cachedBalanceCents: $cached->balance_cents,
            computedBalanceCents: $computed,
            differenceCents: $cached->balance_cents - $computed,
        );
    }
}
