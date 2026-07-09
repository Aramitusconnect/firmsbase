<?php

namespace App\Services;

use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Enums\EmailAttachmentPromotionStatus;
use App\Enums\EmailStorageMode;
use App\Enums\EmailSyncEventType;
use App\Enums\EmailSyncOutcome;
use App\Enums\WebhookEventType;
use App\Models\Document;
use App\Models\EmailAttachment;
use App\ValueObjects\EmailAttachmentPromotionResult;
use Illuminate\Support\Facades\DB;

/**
 * EmailAttachmentPromotionService — the ONLY place an EmailAttachment
 * may become a real Document row. Requires BOTH:
 *   1. the owning message's storage_mode was
 *      EncryptedBodyAndAttachments at capture time (the only mode
 *      where attachment scanning is actually supported — approved
 *      storage-mode matrix), and
 *   2. a clean scan result (DocumentScanStatus::Clean), obtained via
 *      EmailAttachmentSafetyService, which itself wraps the EXISTING
 *      DocumentUploadPolicyService + VirusScanner.
 *
 * The created Document's firm_id is ALWAYS taken from
 * EmailAttachment->emailMessage->emailAccount->firm_id (approved
 * requirement) — never from provider_attachment_id or any other
 * provider-supplied field — which is what makes "no cross-firm mailbox
 * exposure" hold even against malformed/malicious fixture data.
 *
 * Phase 14b addition: fires document.uploaded exactly once, only on
 * the true promotion-success path below (after the firm-crossing guard
 * and the document_id/promotion_status update) — never from either
 * block() early return (unsupported storage mode, failed/infected
 * scan). Not wrapped in an explicit DB::transaction(), so
 * DB::afterCommit() runs the closure immediately (no active
 * transaction to defer past), same documented behavior relied on in
 * DocumentSecurityService::upload().
 */
class EmailAttachmentPromotionService
{
    public function __construct(
        private readonly EmailAttachmentSafetyService $safetyService,
        private readonly EmailSyncAuditService $auditService,
    ) {
    }

    public function scanAndPromote(EmailAttachment $attachment): EmailAttachmentPromotionResult
    {
        $message = $attachment->emailMessage;

        if ($message->storage_mode !== EmailStorageMode::EncryptedBodyAndAttachments) {
            return $this->block(
                $attachment,
                'attachment scanning/promotion is only supported when the message storage_mode is encrypted_body_and_attachments'
            );
        }

        $scanStatus = $this->safetyService->assertSafeAndScan($attachment);

        $attachment->update(['scan_status' => $scanStatus]);

        if ($scanStatus !== DocumentScanStatus::Clean) {
            $mappedStatus = match ($scanStatus) {
                DocumentScanStatus::Infected => EmailAttachmentPromotionStatus::ScanInfected,
                DocumentScanStatus::Failed => EmailAttachmentPromotionStatus::ScanFailed,
                default => EmailAttachmentPromotionStatus::Blocked,
            };

            $attachment->update(['promotion_status' => $mappedStatus]);

            return $this->block($attachment, "attachment did not pass a clean virus scan (status={$scanStatus->value})", false);
        }

        // firm_id is derived exclusively from the message's own account — never from provider data.
        $firmId = $message->emailAccount->firm_id;

        $document = (new TenantContextService())->runWithFirmContext($firmId, fn () => Document::create([
            'firm_id' => $firmId,
            'matter_id' => null,
            'client_id' => null,
            'status' => DocumentStatus::Uploaded,
            'scan_status' => DocumentScanStatus::Clean,
            'scanned_at' => now(),
            'storage_disk' => 'local',
            'storage_path' => $attachment->simulated_storage_path,
            'original_filename' => $attachment->original_filename,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => $attachment->size_bytes,
            'file_hash' => hash('sha256', $attachment->simulated_storage_path),
        ]));

        if ($document->firm_id !== $firmId) {
            throw new \RuntimeException('Promoted document must not cross firms.');
        }

        $attachment->update([
            'document_id' => $document->id,
            'promotion_status' => EmailAttachmentPromotionStatus::Promoted,
            'scan_status' => DocumentScanStatus::Clean,
        ]);

        $this->auditPromoted($attachment);

        DB::afterCommit(function () use ($attachment, $document) {
            try {
                app(WebhookEventRecorderService::class)->record($attachment->firm, WebhookEventType::DocumentUploaded, $document);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return EmailAttachmentPromotionResult::promoted($document->id);
    }

    private function block(EmailAttachment $attachment, string $reason, bool $writeStatus = true): EmailAttachmentPromotionResult
    {
        if ($writeStatus) {
            $attachment->update([
                'promotion_status' => EmailAttachmentPromotionStatus::Blocked,
                'blocked_reason' => $reason,
            ]);
        } else {
            $attachment->update(['blocked_reason' => $reason]);
        }

        $this->auditBlocked($attachment, $reason);

        return EmailAttachmentPromotionResult::blocked($reason);
    }

    private function auditPromoted(EmailAttachment $attachment): void
    {
        $this->auditService->record(
            $attachment->firm,
            $attachment->emailMessage->emailAccount,
            EmailSyncEventType::AttachmentPromoted,
            EmailSyncOutcome::Success,
            detail: "email_attachment_id={$attachment->id} document_id={$attachment->document_id}",
        );
    }

    private function auditBlocked(EmailAttachment $attachment, string $reason): void
    {
        $this->auditService->record(
            $attachment->firm,
            $attachment->emailMessage->emailAccount,
            EmailSyncEventType::AttachmentBlocked,
            EmailSyncOutcome::Blocked,
            detail: "email_attachment_id={$attachment->id}: {$reason}",
        );
    }
}
