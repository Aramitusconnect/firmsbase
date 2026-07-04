<?php

namespace App\Enums;

/**
 * DocumentScanStatus — documents.scan_status. Exactly 4 states per
 * approved clarification: Infected is the blocked/quarantined
 * malware-found outcome; Failed means the scan itself could not
 * complete or was inconclusive — a different legal/security outcome
 * from Infected, and handled differently (Failed may be retried;
 * Infected never is). A document may only reach DocumentStatus::
 * Approved while scan_status is Clean — enforced by
 * DocumentSecurityService, never by this enum alone.
 */
enum DocumentScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case Failed = 'failed';
}
