<?php

namespace App\Enums;

enum TrialRequestStatus: string
{
    case Requested = 'requested';
    case Provisioned = 'provisioned';
    case Active = 'active';
    case Expired = 'expired';
    case Converted = 'converted';
    case Cancelled = 'cancelled';
}
