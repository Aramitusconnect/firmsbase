<?php

namespace App\Enums;

/**
 * EmailStorageMode — email_accounts.storage_mode (the configured
 * setting) and email_messages.storage_mode (the effective mode
 * captured at ingestion time, frozen per-message so a later change to
 * the account setting never reinterprets already-captured messages).
 *
 * Disabled is NOT the same as MetadataOnly (approved correction):
 *   - Disabled blocks EmailSyncService from capturing anything at all
 *     for that account — no email_messages row, no email_attachments
 *     row, zero messages captured.
 *   - MetadataOnly captures message metadata (subject, addresses,
 *     dates, thread/message ids) but never fetches or encrypts a body;
 *     attachment metadata may exist but can never be promoted to a
 *     Document.
 *   - EncryptedBody additionally stores the encrypted body via the
 *     firm's active TenantEncryptionKey.
 *   - EncryptedBodyAndAttachments additionally runs attachment safety/
 *     scanning and allows Document promotion on a clean scan.
 * The exact behavior matrix per mode lives in EmailSyncService and
 * EmailAttachmentPromotionService, never re-derived elsewhere.
 */
enum EmailStorageMode: string
{
    case Disabled = 'disabled';
    case MetadataOnly = 'metadata_only';
    case EncryptedBody = 'encrypted_body';
    case EncryptedBodyAndAttachments = 'encrypted_body_and_attachments';
}
