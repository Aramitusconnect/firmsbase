<?php

namespace App\Enums;

/**
 * SignatureCertificateStatus — a single-value enum by design. Once a
 * signature_requests row reaches Completed, the master-plan state
 * machine gives that state no further transitions, so there is no
 * legitimate path to revoking or superseding a generated certificate
 * in this phase. No revoke/supersede workflow is invented here; if one
 * is needed later, it must be explicitly requested and approved.
 */
enum SignatureCertificateStatus: string
{
    case Generated = 'generated';
}
