<?php

namespace App\Enums;

enum DocumentTemplateStatus: string
{
    case Active = 'active';
    case Retired = 'retired';
}
