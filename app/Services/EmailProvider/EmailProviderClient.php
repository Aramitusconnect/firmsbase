<?php

namespace App\Services\EmailProvider;

use App\Models\EmailAccount;

/**
 * EmailProviderClient — the abstraction every mailbox sync/capture
 * goes through. No production implementation exists in Phase 9
 * requiring a real Gmail API or Microsoft Graph API call, no SDK, no
 * network I/O — FakeEmailProviderClient is the only implementation,
 * used by both EmailSyncService and every test, mirroring Phase 4's
 * VirusScanner/FakeVirusScanner precedent exactly. A real
 * provider-backed implementation can be added later purely
 * additively — nothing depending on this interface needs to change.
 *
 * Return shape of fetchNewMessages():
 *   [
 *     'messages' => [
 *       [
 *         'provider_thread_id' => string,
 *         'provider_message_id' => string,
 *         'direction' => 'inbound'|'outbound',
 *         'from_address' => string,
 *         'to_addresses' => array<int, string>,
 *         'subject' => string,
 *         'sent_at' => ?string,
 *         'received_at' => ?string,
 *         'body' => string, // plaintext fixture body, never persisted as-is
 *         'attachments' => [
 *           ['provider_attachment_id' => string, 'original_filename' => string, 'mime_type' => string, 'size_bytes' => int],
 *           ...
 *         ],
 *       ],
 *       ...
 *     ],
 *     'resulting_cursor' => string,
 *   ]
 */
interface EmailProviderClient
{
    public function fetchNewMessages(EmailAccount $account, ?string $sinceCursor): array;
}
