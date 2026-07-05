<?php

namespace App\Enums;

/**
 * ExportFileStatus — "Generated" means a governed metadata record
 * simulating package creation was produced. No real ZIP/file is ever
 * written to disk in Phase 8 (forbidden item) — simulated_storage_path
 * on ExportFile is metadata only.
 */
enum ExportFileStatus: string
{
    case Pending = 'pending';
    case Generated = 'generated';
    case Failed = 'failed';
    case Expired = 'expired';
}
