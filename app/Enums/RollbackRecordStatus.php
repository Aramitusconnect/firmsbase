<?php

namespace App\Enums;

enum RollbackRecordStatus: string
{
    case Pending = 'pending';
    case RolledBack = 'rolled_back';
    case Failed = 'failed';
    case NotApplicable = 'not_applicable';
}
