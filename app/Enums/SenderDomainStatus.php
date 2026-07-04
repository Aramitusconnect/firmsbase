<?php

namespace App\Enums;

/**
 * SenderDomainStatus — notification_templates.spf_status/dkim_status/
 * dmarc_status. Tracks a STORED verification outcome only — no live
 * DNS lookups are performed anywhere in Phase 4 (approved
 * clarification). A real verification process (external to this
 * phase) would update these fields; SenderDomainVerificationService
 * only reads them to decide whether NotificationDispatchService may
 * proceed.
 */
enum SenderDomainStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
    case Revoked = 'revoked';
}
