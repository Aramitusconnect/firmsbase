<?php

namespace App\Enums;

enum VendorStatus: string
{
    case Active = 'active';
    case UnderReview = 'under_review';
    case Terminated = 'terminated';
}
