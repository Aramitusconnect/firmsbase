<?php

namespace App\Exceptions;

use App\Models\TrustLedger;

/**
 * TrustLedgerHasResidualBalanceException — Trust & Accounting Integrity
 * Hardening, Mission 1.2: thrown by TrustLedgerService::close() when a
 * ledger still holds client funds. balanceCents is read from the
 * trust_balances row locked by TrustConcurrencyLockService::
 * withLockedBalances() for the same firm/ledger, so it reflects the
 * race-safe current balance, not a stale cached read.
 *
 * A trust ledger must be brought to a zero balance — via an ordinary
 * transfer, refund, or high-risk adjustment — before it can be closed.
 * There is no override or force-close path.
 */
class TrustLedgerHasResidualBalanceException extends \RuntimeException
{
    public function __construct(public readonly TrustLedger $ledger, public readonly int $balanceCents)
    {
        parent::__construct(
            "Trust ledger [id={$ledger->id}] cannot be closed with a residual balance of {$balanceCents} cents. ".
            'Transfer, refund, or adjust the balance to zero before closing.'
        );
    }
}
