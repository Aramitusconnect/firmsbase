<?php

namespace App\Enums;

enum CommissionPlanStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
