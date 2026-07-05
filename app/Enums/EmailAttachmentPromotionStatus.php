<?php

namespace App\Enums;

/**
 * EmailAttachmentPromotionStatus — email_attachments.promotion_status.
 * A row may only reach Promoted (a real Document created) when it
 * previously reached ScanClean AND the owning message's storage_mode
 * was EncryptedBodyAndAttachments at capture time — enforced by
 * EmailAttachmentPromotionService, never by this enum alone.
 */
enum EmailAttachmentPromotionStatus: string
{
    case Pending = 'pending';
    case ScanClean = 'scan_clean';
    case ScanInfected = 'scan_infected';
    case ScanFailed = 'scan_failed';
    case Promoted = 'promoted';
    case Blocked = 'blocked';
}
