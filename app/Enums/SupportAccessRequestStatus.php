<?php

namespace App\Enums;

enum SupportAccessRequestStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Denied = 'denied';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
