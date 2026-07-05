<?php

namespace App\Enums;

/**
 * PdfViewerViewerType — who is viewing a PDF: internal firm staff or an
 * external signature recipient. Deliberately limited to these two
 * actor types for this phase — no client-portal viewer session concept
 * is modeled here, since that would be unapproved scope.
 */
enum PdfViewerViewerType: string
{
    case FirmUser = 'firm_user';
    case Recipient = 'recipient';
}
