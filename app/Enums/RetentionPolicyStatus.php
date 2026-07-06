<?php

namespace App\Enums;

enum RetentionPolicyStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Superseded = 'superseded';
    case Archived = 'archived';
}
