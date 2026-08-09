<?php

namespace App\Enums;

/**
 * PaymentRequestEventType — Payment Link / QR Routing phase. The
 * closed set of events PaymentRequestEvent (an append-only audit log,
 * mirroring TrustApprovalEvent/AccountingPeriodEvent) may record.
 */
enum PaymentRequestEventType: string
{
    case Created = 'created';
    case Activated = 'activated';
    case Revoked = 'revoked';
    case LinkAccessed = 'link_accessed';
    case PaymentAttempted = 'payment_attempted';
    case ProviderConfirmed = 'provider_confirmed';
    case ProviderFailed = 'provider_failed';
    case ClassificationDecided = 'classification_decided';
    case TrustDepositRequested = 'trust_deposit_requested';
    case PostedToAccounting = 'posted_to_accounting';
    case Failed = 'failed';
}
