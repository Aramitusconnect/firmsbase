<?php

namespace App\Enums;

/**
 * EmailBodyStatus — email_messages.body_status. NotStored is the
 * expected, intentional outcome whenever the message's storage_mode is
 * Disabled (never reached — Disabled blocks capture before a row is
 * even created) or MetadataOnly (body deliberately never fetched or
 * encrypted). EncryptionFailed means the firm had no active
 * TenantEncryptionKey at capture time — EmailBodyEncryptionService
 * fails closed rather than storing plaintext, and the caller must
 * leave encrypted_body_ciphertext null when this status is set.
 */
enum EmailBodyStatus: string
{
    case Encrypted = 'encrypted';
    case EncryptionFailed = 'encryption_failed';
    case NotStored = 'not_stored';
}
