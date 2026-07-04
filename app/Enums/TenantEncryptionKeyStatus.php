<?php

namespace App\Enums;

enum TenantEncryptionKeyStatus: string
{
    case Active = 'active';
    case Rotated = 'rotated';
    case Destroyed = 'destroyed';
}
