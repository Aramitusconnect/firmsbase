<?php

namespace App\Enums;

/**
 * PdfViewEventAction — deliberately coarse (no page-by-page/zoom-level
 * granularity) since no real in-browser PDF viewer exists yet to
 * produce that telemetry. DownloadRequested is always logged before
 * PdfDownloadPolicyService decides, and the decision itself is a
 * SEPARATE row (DownloadAllowed/DownloadDenied) — a policy decision is
 * never applied silently. AnnotationAdded is only ever written by
 * PdfAnnotationService, which refuses to write it unless the firm's
 * entitlement explicitly enables annotations.
 */
enum PdfViewEventAction: string
{
    case Opened = 'opened';
    case DownloadRequested = 'download_requested';
    case DownloadAllowed = 'download_allowed';
    case DownloadDenied = 'download_denied';
    case AnnotationAdded = 'annotation_added';
}
