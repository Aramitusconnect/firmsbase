<?php

namespace App\Enums;

enum EmailOAuthTokenStatus: string
{
    case Active = 'active';
    case Rotated = 'rotated';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
