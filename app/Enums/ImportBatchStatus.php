<?php

namespace App\Enums;

enum ImportBatchStatus: string
{
    case Draft = 'draft';
    case Staged = 'staged';
    case Validated = 'validated';
    case PreviewReady = 'preview_ready';
    case Confirmed = 'confirmed';
    case Applying = 'applying';
    case Applied = 'applied';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
    case Cancelled = 'cancelled';
}
