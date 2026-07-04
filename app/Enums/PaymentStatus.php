<?php

namespace App\Enums;

/**
 * PaymentStatus — values taken verbatim from the master plan PDF,
 * Section 33, Payment row: "initiated; pending; classified; blocked;
 * succeeded; failed; refunded; partially_refunded; disputed;
 * reversed". "Classification happens before saving or before Stripe
 * PaymentIntent creation" (same PDF row) — Phase 3's manual flow moves
 * initiated -> classified -> (succeeded | blocked) within one
 * transaction; the remaining values exist now so Phase 6 Stripe flows
 * can reuse this exact enum without redefining it.
 */
enum PaymentStatus: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Classified = 'classified';
    case Blocked = 'blocked';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Disputed = 'disputed';
    case Reversed = 'reversed';
}
