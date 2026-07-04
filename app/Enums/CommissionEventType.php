<?php

namespace App\Enums;

enum CommissionEventType: string
{
    case NewBusiness = 'new_business';
    case Expansion = 'expansion';
    case Renewal = 'renewal';
}
