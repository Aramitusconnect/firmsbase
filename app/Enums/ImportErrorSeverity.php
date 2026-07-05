<?php

namespace App\Enums;

enum ImportErrorSeverity: string
{
    case Warning = 'warning';
    case Error = 'error';
    case Blocking = 'blocking';
}
