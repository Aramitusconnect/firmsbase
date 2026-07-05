<?php

namespace App\Services;

use App\Enums\EmailBodyStatus;
use App\Enums\EmailStorageMode;
use App\Enums\EmailSyncEventType;
use App\Enums\EmailSyncOutcome;
use App\Models\EmailAccount;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Services\EmailProvider\EmailProviderClient;
use App\ValueObjects\EmailSyncRunResult;

/**
 * EmailSyncService — the only place mailbox capture happens. This
 * class deliberately has NO constructor dependency on
 * NotificationDispatchService, NotificationEligibilityService,
 * ConsentService, SuppressionService, or DispatchNotificationJob —
 * mailbox capture is a passive mirror of what already exists in the
 * provider mailbox, never a communication attempt, and must never be
 * able to trigger or imply a Phase 4 send/consent side effect. See
 * tests/Feature/Email/Deliverability/EmailSyncDoesNotBypassPhase4DeliverabilityTest.
 *
 * Storage-mode matrix (approved correction), enforced here and nowhere
 * else:
 *   Disabled: sync() returns immediately. No provider call. No
 *     email_messages row. No email_attachments row. Exactly one
 *     email_sync_events row (SyncRun/Blocked) is written to audit the
 *     blocked attempt. messagesCaptured is always 0.
 *   MetadataOnly: message metadata is captured; body_status=NotStored,
 *     no encryption call is made at all; attachment rows may be
 *     created as inert metadata (scan_status/promotion_status stay
 *     Pending forever — this service never invokes attachment safety/
 *     scanning under this mode).
 *   EncryptedBody: same as MetadataOnly for attachments (metadata
 *     only, never scanned/promoted); the body is additionally
 *     encrypted via EmailBodyEncryptionService and stored.
 *   EncryptedBodyAndAttachments: body is encrypted and stored; this is
 *     the only mode where attachment safety/scanning is actually
 *     invoked (by EmailAttachmentSafetyService, called separately —
 *     see EmailAttachmentPromotionService) and eligible for Document
 *     promotion.
 */
class EmailSyncService
{
    public function __construct(
        private readonly EmailProviderClient $providerClient,
        private readonly EmailBodyEncryptionService $bodyEncryption,
        private readonly EmailSyncAuditService $auditService,
        private readonly EmailAccessPolicyService $accessPolicy,
    ) {
    }

    public function sync(EmailAccount $account): EmailSyncRunResult
    {
        $firm = $account->firm;

        if (! $this->accessPolicy->canUseEmail($firm->id)) {
            $this->auditService->record(
                $firm,
                $account,
                EmailSyncEventType::SyncRun,
                EmailSyncOutcome::Blocked,
                detail: 'firm is not entitled to the email module',
            );

            return new EmailSyncRunResult($account->id, EmailSyncOutcome::Blocked, 0);
        }

        if ($account->storage_mode === EmailStorageMode::Disabled) {
            $this->auditService->record(
                $firm,
                $account,
                EmailSyncEventType::SyncRun,
                EmailSyncOutcome::Blocked,
                detail: 'storage_mode is disabled; sync/capture is blocked entirely (approved correction)',
            );

            return new EmailSyncRunResult($account->id, EmailSyncOutcome::Blocked, 0);
        }

        $sinceCursor = $this->auditService->latestCursorFor($account);

        $fetch = $this->providerClient->fetchNewMessages($account, $sinceCursor);
        $fixtureMessages = $fetch['messages'] ?? [];
        $resultingCursor = $fetch['resulting_cursor'] ?? $sinceCursor;

        $captured = 0;
        $anyEncryptionFailed = false;

        foreach ($fixtureMessages as $fixture) {
            $message = $this->captureMessage($firm, $account, $fixture);
            $captured++;

            if ($message->body_status === EmailBodyStatus::EncryptionFailed) {
                $anyEncryptionFailed = true;
            }

            $this->auditService->record(
                $firm,
                $account,
                EmailSyncEventType::MessageCaptured,
                EmailSyncOutcome::Success,
                detail: "captured provider_message_id={$fixture['provider_message_id']}",
            );
        }

        $account->update(['last_synced_at' => now()]);

        $outcome = $anyEncryptionFailed ? EmailSyncOutcome::PartialFailure : EmailSyncOutcome::Success;

        $this->auditService->record(
            $firm,
            $account,
            EmailSyncEventType::SyncRun,
            $outcome,
            resultingCursor: $resultingCursor,
            detail: "captured {$captured} message(s)",
        );

        return new EmailSyncRunResult($account->id, $outcome, $captured, $resultingCursor);
    }

    private function captureMessage(\App\Models\Firm $firm, EmailAccount $account, array $fixture): EmailMessage
    {
        $storageMode = $account->storage_mode;
        $bodyStatus = EmailBodyStatus::NotStored;
        $ciphertext = null;
        $encryptionKeyId = null;

        if (in_array($storageMode, [EmailStorageMode::EncryptedBody, EmailStorageMode::EncryptedBodyAndAttachments], true)) {
            $result = $this->bodyEncryption->encrypt($firm, $fixture['body'] ?? '');

            if ($result->succeeded) {
                $bodyStatus = EmailBodyStatus::Encrypted;
                $ciphertext = $result->ciphertext;
                $encryptionKeyId = $result->encryptionKeyId;
            } else {
                $bodyStatus = EmailBodyStatus::EncryptionFailed;
            }
        }

        $attachments = $fixture['attachments'] ?? [];

        $message = EmailMessage::create([
            'firm_id' => $firm->id,
            'email_account_id' => $account->id,
            'provider_thread_id' => $fixture['provider_thread_id'],
            'provider_message_id' => $fixture['provider_message_id'],
            'direction' => $fixture['direction'],
            'from_address' => $fixture['from_address'],
            'to_addresses' => $fixture['to_addresses'] ?? [],
            'subject' => $fixture['subject'] ?? null,
            'sent_at' => $fixture['sent_at'] ?? null,
            'received_at' => $fixture['received_at'] ?? null,
            'storage_mode' => $storageMode,
            'body_status' => $bodyStatus,
            'encrypted_body_ciphertext' => $ciphertext,
            'encryption_key_id' => $encryptionKeyId,
            'has_attachments' => count($attachments) > 0,
        ]);

        foreach ($attachments as $attachmentFixture) {
            EmailAttachment::create([
                'firm_id' => $firm->id,
                'email_message_id' => $message->id,
                'original_filename' => $attachmentFixture['original_filename'],
                'mime_type' => $attachmentFixture['mime_type'],
                'size_bytes' => $attachmentFixture['size_bytes'],
                'provider_attachment_id' => $attachmentFixture['provider_attachment_id'],
                'scan_status' => 'pending',
                'simulated_storage_path' => "email-attachments/{$firm->uuid}/{$account->uuid}/{$attachmentFixture['provider_attachment_id']}",
                'promotion_status' => 'pending',
            ]);
        }

        return $message;
    }
}
