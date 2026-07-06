<?php

namespace App\Enums;

enum VendorDpaStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Signed = 'signed';
    case Expired = 'expired';
}
