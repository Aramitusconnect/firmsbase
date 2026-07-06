<?php

namespace App\Enums;

enum LegalHoldStatus: string
{
    case Active = 'active';
    case Released = 'released';
}
