<?php

namespace App\Enums;

enum VendorAiZeroRetentionStatus: string
{
    case NotApplicable = 'not_applicable';
    case RequiredNotConfirmed = 'required_not_confirmed';
    case Confirmed = 'confirmed';
}
