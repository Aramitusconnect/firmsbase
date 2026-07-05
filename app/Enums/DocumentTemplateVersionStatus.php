<?php

namespace App\Enums;

enum DocumentTemplateVersionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Retired = 'retired';
}
