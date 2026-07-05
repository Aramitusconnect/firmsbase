<?php

namespace App\Enums;

/**
 * TrustChargebackStatus — Reported when the external chargeback fact is
 * logged; Reversed once the offsetting ChargebackReversal ledger entry
 * has been posted; Resolved is a final human-confirmed closure of the
 * incident (e.g. client repaid, or write-off approved elsewhere) —
 * Resolved never itself moves money.
 */
enum TrustChargebackStatus: string
{
    case Reported = 'reported';
    case Reversed = 'reversed';
    case Resolved = 'resolved';
}
