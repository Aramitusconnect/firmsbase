<?php

namespace App\Enums;

enum ApiKeyStatus: string
{
    case Active = 'active';
    case Rotated = 'rotated';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
