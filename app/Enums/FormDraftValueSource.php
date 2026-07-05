<?php

namespace App\Enums;

enum FormDraftValueSource: string
{
    case Mapped = 'mapped';
    case ManualOverride = 'manual_override';
    case Missing = 'missing';
}
