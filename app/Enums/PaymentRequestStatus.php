<?php

namespace App\Enums;

/**
 * PaymentRequestStatus — Payment Link / QR Routing phase. The lifecycle
 * states named explicitly by the phase spec. PaymentRequestService is
 * the only writer.
 */
enum PaymentRequestStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Paid = 'paid';
    case PendingReview = 'pending_review';
    case Failed = 'failed';
}
