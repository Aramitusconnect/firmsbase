<?php

namespace App\Enums;

enum TrustRefundRequestStatus: string
{
    case Requested = 'requested';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Completed = 'completed';
    case Denied = 'denied';
    case Cancelled = 'cancelled';
}
