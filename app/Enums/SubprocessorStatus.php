<?php

namespace App\Enums;

enum SubprocessorStatus: string
{
    case Active = 'active';
    case Replaced = 'replaced';
    case Removed = 'removed';
}
