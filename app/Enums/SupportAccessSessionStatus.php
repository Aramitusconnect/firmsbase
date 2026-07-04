<?php

namespace App\Enums;

enum SupportAccessSessionStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
