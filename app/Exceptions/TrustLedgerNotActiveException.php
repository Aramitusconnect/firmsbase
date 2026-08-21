<?php

namespace App\Exceptions;

use App\Enums\TrustLedgerStatus;
use App\Models\TrustLedger;

/**
 * TrustLedgerNotActiveException — Trust & Accounting Integrity
 * Hardening, Mission 1.1: the single, canonical failure mode for every
 * money-moving trust operation (deposit posting, trust-to-invoice
 * transfer, refund, high-risk adjustment, reversal/chargeback-reversal)
 * attempted against a trust ledger whose status is not Active.
 *
 * Thrown from TenantSafeTrustPolicyService::assertLedgerAllowsMoneyMovement(),
 * always from inside the same locked transaction the calling money-moving
 * service is already running in (TrustConcurrencyLockService::withLockedBalances()),
 * so throwing it rolls back the entire attempted entry atomically — no
 * partial state, no orphaned TrustLedgerEntry row.
 *
 * A Frozen ledger blocks new deposits/withdrawals but preserves history;
 * a Closed ledger is terminal. No governed exception path for either
 * state exists anywhere in this codebase today (e.g. no "reversal
 * during freeze" carve-out) — this applies uniformly to every entry
 * type, including reversals and chargeback reversals.
 */
class TrustLedgerNotActiveException extends \RuntimeException
{
    public function __construct(public readonly TrustLedger $ledger, public readonly TrustLedgerStatus $status)
    {
        parent::__construct(
            "Trust ledger [id={$ledger->id}] is {$status->value} and cannot accept new financial activity."
        );
    }
}
