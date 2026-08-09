<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Firm;
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

    /**
     * Matter-scoped sibling of reconcileCacheAgainstLedger() (Phase E
     * — "audit MatterTrustBalance carefully... add consistency/
     * rebuild verification where needed"). Same non-mutating
     * cache-vs-live-sum comparison, additionally scoped by matter_id.
     * Reuses the same TrustBalanceReconciliationResult value object —
     * no new comparison result type.
     */
    public function reconcileMatterCacheAgainstLedger(TrustLedger $ledger, Matter $matter): TrustBalanceReconciliationResult
    {
        $cached = MatterTrustBalance::query()
            ->where('trust_ledger_id', $ledger->id)
            ->where('matter_id', $matter->id)
            ->firstOrFail();

        $computed = (int) TrustLedgerEntry::query()
            ->where('trust_ledger_id', $ledger->id)
            ->where('matter_id', $matter->id)
            ->sum('amount_cents');

        return new TrustBalanceReconciliationResult(
            matches: $cached->balance_cents === $computed,
            cachedBalanceCents: $cached->balance_cents,
            computedBalanceCents: $computed,
            differenceCents: $cached->balance_cents - $computed,
        );
    }

    /**
     * Client-level aggregate (Phase E — client/matter balance
     * aggregation): sums the CACHED trust_balances.balance_cents
     * across every TrustLedger owned by this client. A client
     * ordinarily has exactly one ledger, but the schema does not
     * enforce that, so this sums rather than assumes a single row.
     * Read-only — never writes trust_balances; the canonical
     * TrustLedgerEntry rows (via recomputeForLedger()) remain the only
     * authoritative source the cache itself is derived from. This IS
     * the "unearned retainer balance" for the client under Phase C's
     * earned/unearned model: funds still sitting in trust are, by
     * definition, not yet firm revenue.
     */
    public function clientBalanceCents(Firm $firm, Client $client): int
    {
        return (int) (new TenantContextService)->runWithFirmContext($firm, fn () => TrustBalance::query()
            ->whereIn('trust_ledger_id', TrustLedger::query()
                ->where('firm_id', $firm->id)
                ->where('client_id', $client->id)
                ->pluck('id'))
            ->sum('balance_cents'));
    }

    /**
     * Matter-level aggregate across every ledger the matter has a
     * balance row in (recomputeForMatter() above is scoped to a single
     * ledger; this sums across all of them, mirroring
     * clientBalanceCents()'s aggregation shape). Read-only.
     */
    public function matterBalanceCentsAggregate(Firm $firm, Matter $matter): int
    {
        return (int) (new TenantContextService)->runWithFirmContext($firm, fn () => MatterTrustBalance::query()
            ->where('firm_id', $firm->id)
            ->where('matter_id', $matter->id)
            ->sum('balance_cents'));
    }

    /**
     * Phase H — the third leg of true three-way trust reconciliation:
     * "sum of individual client/matter trust liabilities" as a value
     * GENUINELY independent of system_balance_cents. matter_trust_balances
     * is a separate cache table, recomputed by a SEPARATE call
     * (recomputeForMatter(), at a different call site/time than
     * recomputeForLedger() updates trust_balances) — the two caches CAN
     * legitimately drift apart even when neither is individually stale
     * relative to trust_ledger_entries, e.g. a bug that posts an entry
     * and recomputes the ledger cache but forgets to recompute the
     * affected matter's cache. Money not attributed to any matter
     * (matter_id IS NULL) is summed directly from the live, immutable
     * trust_ledger_entries rows themselves (there is no "unattributed
     * balance" cache table to read instead).
     */
    public function verifyMatterLiabilitiesReconcileToLedger(TrustLedger $ledger): TrustBalanceReconciliationResult
    {
        $ledgerCached = TrustBalance::query()->where('trust_ledger_id', $ledger->id)->firstOrFail();

        $matterAttributedCents = (int) MatterTrustBalance::query()
            ->where('trust_ledger_id', $ledger->id)
            ->sum('balance_cents');

        $unattributedCents = (int) TrustLedgerEntry::query()
            ->where('trust_ledger_id', $ledger->id)
            ->whereNull('matter_id')
            ->sum('amount_cents');

        $computed = $matterAttributedCents + $unattributedCents;

        return new TrustBalanceReconciliationResult(
            matches: $ledgerCached->balance_cents === $computed,
            cachedBalanceCents: $ledgerCached->balance_cents,
            computedBalanceCents: $computed,
            differenceCents: $ledgerCached->balance_cents - $computed,
        );
    }

    /**
     * Accounting Integrity Hardening Pass, item 6 — the historical
     * counterpart to clientBalanceCents()/matterBalanceCentsAggregate()/
     * recomputeForLedger(): every trust_balances/matter_trust_balances
     * cache row represents the balance RIGHT NOW, with no history at
     * all (an ordinary Eloquent UPDATE overwrites the prior value), so
     * "the trust liability as of a past closed period's end date" can
     * only be answered by summing the immutable trust_ledger_entries
     * themselves up to that date — never by reading a cache. This is
     * the SAME live table every cache is itself derived from
     * (recomputeForLedger()/recomputeForMatter() both already sum
     * trust_ledger_entries.amount_cents), just filtered by
     * TrustLedgerEntry.posted_at instead of taken as of "now" —
     * deliberately not a second ledger or cache. posted_at is set once
     * at creation and the model's own booted() hook forbids ever
     * updating or deleting a row (see TrustLedgerEntry's own
     * docblock), so a transaction posted AFTER $asOf can never retroactively
     * change a balance computed as of a date before it.
     */
    public function firmTrustLiabilityAsOf(Firm $firm, \DateTimeInterface $asOf): int
    {
        return (int) (new TenantContextService)->runWithFirmContext($firm, fn () => TrustLedgerEntry::query()
            ->where('firm_id', $firm->id)
            ->where('posted_at', '<=', $asOf)
            ->sum('amount_cents'));
    }

    public function clientBalanceCentsAsOf(Firm $firm, Client $client, \DateTimeInterface $asOf): int
    {
        return (int) (new TenantContextService)->runWithFirmContext($firm, fn () => TrustLedgerEntry::query()
            ->where('firm_id', $firm->id)
            ->where('posted_at', '<=', $asOf)
            ->whereIn('trust_ledger_id', TrustLedger::query()
                ->where('firm_id', $firm->id)
                ->where('client_id', $client->id)
                ->pluck('id'))
            ->sum('amount_cents'));
    }

    public function matterBalanceCentsAsOf(Firm $firm, Matter $matter, \DateTimeInterface $asOf): int
    {
        return (int) (new TenantContextService)->runWithFirmContext($firm, fn () => TrustLedgerEntry::query()
            ->where('firm_id', $firm->id)
            ->where('matter_id', $matter->id)
            ->where('posted_at', '<=', $asOf)
            ->sum('amount_cents'));
    }
}
