<?php

namespace App\Enums;

enum VendorSocReportStatus: string
{
    case NotProvided = 'not_provided';
    case Requested = 'requested';
    case Received = 'received';
    case Expired = 'expired';
}
