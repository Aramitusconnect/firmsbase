<?php

namespace App\Services;

use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Enums\DomainEventType;
use App\Enums\EmailAttachmentPromotionStatus;
use App\Enums\EmailStorageMode;
use App\Enums\EmailSyncEventType;
use App\Enums\EmailSyncOutcome;
use App\Enums\WebhookEventType;
use App\Models\Document;
use App\Models\EmailAttachment;
use App\Services\Automation\DomainEventRecorderService;
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
 *
 * Tenant-context wiring (email_attachments/email_messages/
 * email_sync_events FORCE ROW LEVEL SECURITY activation, Section
 * 39A-5 Wave 5): every write (and the one lazy-load read of
 * $attachment->emailMessage) gets its own independent, sibling-
 * sequential runWithFirmContext() wrap — never a wrap spanning more
 * than one statement, and never two wraps nested inside each other.
 * This removes the nesting hazard categorically (no outer wrap exists
 * for any inner wrap to nest inside) and avoids introducing a new
 * atomicity guarantee this method never had. Every wrap that mutates
 * the attachment's OWN row is keyed on $attachment->firm_id (its own
 * already-loaded column, which is what RLS on email_attachments
 * actually checks).
 *
 * REQUIRED, security-relevant correction — do NOT simplify: the
 * promoted Document's firm_id, its wrap key, and the subsequent
 * cross-firm guard are derived via the two-hop
 * $message->emailAccount->firm_id path (see the class docblock above,
 * lines 28-32) — NEVER via $attachment->firm_id, even though every
 * other statement in this method uses $attachment->firm_id as its own
 * wrap key. $attachment->firm_id and $message->emailAccount->firm_id
 * are only empirically equal under today's disciplined writers — no
 * composite FK/CHECK/trigger guarantees it at the database layer (see
 * this batch's migrations' own documented deferred gap #1) — so
 * substituting the shortcut here would silently trade away the one
 * defense that stops a malformed/malicious provider fixture from
 * producing a cross-firm Document.
 */
class EmailAttachmentPromotionService
{
    public function __construct(
        private readonly EmailAttachmentSafetyService $safetyService,
        private readonly EmailSyncAuditService $auditService,
        private readonly DomainEventRecorderService $domainEvents,
    ) {}

    public function scanAndPromote(EmailAttachment $attachment): EmailAttachmentPromotionResult
    {
        $service = new TenantContextService;

        $message = $service->runWithFirmContext($attachment->firm_id, fn () => $attachment->emailMessage);

        if ($message->storage_mode !== EmailStorageMode::EncryptedBodyAndAttachments) {
            return $this->block(
                $attachment,
                'attachment scanning/promotion is only supported when the message storage_mode is encrypted_body_and_attachments'
            );
        }

        $scanStatus = $this->safetyService->assertSafeAndScan($attachment);

        $service->runWithFirmContext($attachment->firm_id, fn () => $attachment->update(['scan_status' => $scanStatus]));

        if ($scanStatus !== DocumentScanStatus::Clean) {
            $mappedStatus = match ($scanStatus) {
                DocumentScanStatus::Infected => EmailAttachmentPromotionStatus::ScanInfected,
                DocumentScanStatus::Failed => EmailAttachmentPromotionStatus::ScanFailed,
                default => EmailAttachmentPromotionStatus::Blocked,
            };

            $service->runWithFirmContext($attachment->firm_id, fn () => $attachment->update(['promotion_status' => $mappedStatus]));

            return $this->block($attachment, "attachment did not pass a clean virus scan (status={$scanStatus->value})", false);
        }

        // firm_id for the promoted Document is derived via the ORIGINAL, approved
        // two-hop account-chain path (message->emailAccount->firm_id), NOT
        // $attachment->firm_id — preserving the documented anti-cross-firm-leak
        // invariant exactly as it exists today (this class's own docblock,
        // lines 28-32).
        $firmId = $service->runWithFirmContext($message->firm_id, fn () => $message->emailAccount->firm_id);

        $document = $service->runWithFirmContext($firmId, fn () => Document::create([
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

        // Own sibling-sequential wrap, matching every other statement in
        // this method (class docblock: "never a wrap spanning more than
        // one statement, and never two wraps nested inside each other").
        // Deliberately $document->firm (the just-verified, two-hop-safe
        // firm), never $attachment->firm — see this class's own
        // "REQUIRED, security-relevant correction" docblock paragraph on
        // why $attachment->firm_id is not interchangeable with it.
        $service->runWithFirmContext($firmId, fn () => $this->domainEvents->record($document->firm, DomainEventType::DocumentUploaded, [
            'document' => [
                'id' => $document->id,
                'file_name' => $document->original_filename,
                'document_request_item_id' => null,
                'matter_id' => null,
            ],
            'matter' => ['id' => null, 'assigned_attorney_id' => null],
            'client' => ['id' => null],
        ], subject: $document));

        $service->runWithFirmContext($attachment->firm_id, fn () => $attachment->update([
            'document_id' => $document->id,
            'promotion_status' => EmailAttachmentPromotionStatus::Promoted,
            'scan_status' => DocumentScanStatus::Clean,
        ]));

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
        $service = new TenantContextService;

        if ($writeStatus) {
            $service->runWithFirmContext($attachment->firm_id, fn () => $attachment->update([
                'promotion_status' => EmailAttachmentPromotionStatus::Blocked,
                'blocked_reason' => $reason,
            ]));
        } else {
            $service->runWithFirmContext($attachment->firm_id, fn () => $attachment->update(['blocked_reason' => $reason]));
        }

        $this->auditBlocked($attachment, $reason);

        return EmailAttachmentPromotionResult::blocked($reason);
    }

    private function auditPromoted(EmailAttachment $attachment): void
    {
        (new TenantContextService)->runWithFirmContext($attachment->firm_id, fn () => $this->auditService->record(
            $attachment->firm,
            $attachment->emailMessage->emailAccount,
            EmailSyncEventType::AttachmentPromoted,
            EmailSyncOutcome::Success,
            detail: "email_attachment_id={$attachment->id} document_id={$attachment->document_id}",
        ));
    }

    private function auditBlocked(EmailAttachment $attachment, string $reason): void
    {
        (new TenantContextService)->runWithFirmContext($attachment->firm_id, fn () => $this->auditService->record(
            $attachment->firm,
            $attachment->emailMessage->emailAccount,
            EmailSyncEventType::AttachmentBlocked,
            EmailSyncOutcome::Blocked,
            detail: "email_attachment_id={$attachment->id}: {$reason}",
        ));
    }
}
