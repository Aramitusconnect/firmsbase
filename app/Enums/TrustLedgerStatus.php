<?php

namespace App\Enums;

/**
 * TrustLedgerStatus — the lifecycle of one client's IOLTA sub-ledger
 * within a firm's trust account. Frozen blocks new deposits/withdrawals
 * without losing history; Closed is terminal.
 */
enum TrustLedgerStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';
    case Closed = 'closed';
}
