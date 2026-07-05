<?php

namespace App\Services;

use App\Enums\EmailSyncEventType;
use App\Enums\EmailSyncOutcome;
use App\Models\EmailAccount;
use App\Models\EmailSyncEvent;
use App\Models\Firm;

/**
 * EmailSyncAuditService — the ONLY place email_sync_events rows are
 * created. Also exposes latestCursorFor(), which replaces the job the
 * removed email_sync_state table would have done: the "current cursor"
 * for an account is derived by reading the latest successful SyncRun
 * row rather than maintaining a separate mutable state row (approved
 * correction).
 */
class EmailSyncAuditService
{
    public function record(
        Firm $firm,
        ?EmailAccount $account,
        EmailSyncEventType $eventType,
        EmailSyncOutcome $outcome,
        ?string $resultingCursor = null,
        ?string $detail = null,
    ): EmailSyncEvent {
        return EmailSyncEvent::create([
            'firm_id' => $firm->id,
            'email_account_id' => $account?->id,
            'event_type' => $eventType,
            'outcome' => $outcome,
            'resulting_cursor' => $resultingCursor,
            'detail' => $detail,
            'created_at' => now(),
        ]);
    }

    public function latestCursorFor(EmailAccount $account): ?string
    {
        $latest = EmailSyncEvent::query()
            ->where('email_account_id', $account->id)
            ->where('event_type', EmailSyncEventType::SyncRun)
            ->where('outcome', EmailSyncOutcome::Success)
            ->whereNotNull('resulting_cursor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $latest?->resulting_cursor;
    }
}
