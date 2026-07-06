<?php

namespace App\Enums;

/**
 * OffboardingExportStatus — "Generated"/"Verified" describe a governed
 * metadata record simulating package creation, matching ExportFileStatus
 * (Phase 8) convention verbatim. No real ZIP/file is ever produced.
 */
enum OffboardingExportStatus: string
{
    case Pending = 'pending';
    case Generated = 'generated';
    case Verified = 'verified';
    case Expired = 'expired';
}
