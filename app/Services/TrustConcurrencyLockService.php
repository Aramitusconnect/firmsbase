<?php

namespace App\Services;

use App\Models\Matter;
use App\Models\MatterTrustBalance;
use App\Models\TrustBalance;
use App\Models\TrustLedger;
use Illuminate\Support\Facades\DB;

/**
 * TrustConcurrencyLockService — the ONLY lock helper used by every
 * money-moving Phase 13 service (correction #12): TrustDepositService,
 * TrustTransferRequestService::apply(), TrustRefundRequestService::complete(),
 * TrustChargebackService's reversal path, and
 * TrustHighRiskAdjustmentService. Mirrors Phase 6's
 * SeatAllocationService::allocateFromPool() pattern exactly:
 * DB::transaction() + SELECT ... FOR UPDATE on the balance row(s)
 * being changed, so a concurrent request for the same ledger/matter
 * can never read a stale balance and double-spend trust funds.
 *
 * Centralizing this in one class means the one piece of code where a
 * bug would be catastrophic (double-spend) is written and tested once,
 * not duplicated per service.
 */
class TrustConcurrencyLockService
{
    /**
     * Locks the TrustLedger row itself, its TrustBalance row, and
     * (when a matter is given) that matter's MatterTrustBalance row,
     * all for the duration of one transaction, then hands the locked
     * rows to the caller's callback as ($lockedBalance,
     * $lockedMatterBalance, $lockedLedger). The callback is
     * responsible for validating sufficient balance/ledger status and
     * performing the write(s) — this class only owns the lock/
     * transaction boundary, not the business rule.
     *
     * Locking the TrustLedger row too (Trust & Accounting Integrity
     * Hardening, Mission 1.1/1.2) is what makes every money-moving
     * write and every TrustLedgerService::freeze()/close() transition
     * mutually exclusive for the same ledger: whichever transaction
     * acquires this lock first sees — and the other must wait to see —
     * the ledger's true, current status, closing the race where a
     * deposit/transfer/refund/adjustment could post in the same
     * instant a ledger is being frozen or closed.
     */
    public function withLockedBalances(TrustLedger $ledger, ?Matter $matter, \Closure $callback): mixed
    {
        return DB::transaction(function () use ($ledger, $matter, $callback) {
            $lockedLedger = TrustLedger::query()
                ->whereKey($ledger->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedBalance = TrustBalance::query()
                ->where('trust_ledger_id', $ledger->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedMatterBalance = null;

            if ($matter) {
                $lockedMatterBalance = MatterTrustBalance::query()
                    ->where('trust_ledger_id', $ledger->id)
                    ->where('matter_id', $matter->id)
                    ->lockForUpdate()
                    ->first();
            }

            return $callback($lockedBalance, $lockedMatterBalance, $lockedLedger);
        });
    }
}
