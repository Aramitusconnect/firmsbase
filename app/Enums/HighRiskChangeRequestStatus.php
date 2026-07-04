<?php

namespace App\Enums;

enum HighRiskChangeRequestStatus: string
{
    case Pending = 'pending';
    case FirstApproved = 'first_approved';
    case Approved = 'approved';
    case Denied = 'denied';
    case Cancelled = 'cancelled';
}
