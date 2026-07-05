<?php

namespace App\Enums;

/**
 * TrustTransferRequestStatus — the trust-to-invoice application
 * workflow. Applied is terminal and is only reachable once the invoice
 * is at/after Approved status and the locked trust balance covers the
 * amount (TrustTransferRequestService::apply()).
 */
enum TrustTransferRequestStatus: string
{
    case Requested = 'requested';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Applied = 'applied';
    case Denied = 'denied';
    case Cancelled = 'cancelled';
}
