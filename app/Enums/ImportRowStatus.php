<?php

namespace App\Enums;

enum ImportRowStatus: string
{
    case Staged = 'staged';
    case Validated = 'validated';
    case Invalid = 'invalid';
    case Duplicate = 'duplicate';
    case Confirmed = 'confirmed';
    case Applied = 'applied';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
}
