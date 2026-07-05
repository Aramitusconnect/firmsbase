<?php

namespace App\ValueObjects;

use App\Enums\EmailSyncOutcome;

/**
 * EmailSyncRunResult — return type of EmailSyncService::sync(). When
 * the account's storage_mode is Disabled, a caller must receive
 * outcome=Blocked and messagesCaptured=0 (approved correction) — no
 * email_messages/email_attachments row is ever created in that case.
 */
class EmailSyncRunResult
{
    public function __construct(
        public readonly int $emailAccountId,
        public readonly EmailSyncOutcome $outcome,
        public readonly int $messagesCaptured = 0,
        public readonly ?string $resultingCursor = null,
        public readonly ?string $error = null,
    ) {
    }
}
