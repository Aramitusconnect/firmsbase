<?php

namespace Tests\Feature\Webhooks\Wiring;

use App\Enums\EmailStorageMode;
use App\Enums\ImportEntityType;
use App\Enums\ImportRowStatus;
use App\Enums\ImportSourceType;
use App\Enums\WebhookEventType;
use App\Models\Document;
use App\Models\EmailAccount;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Services\DocumentReplacementService;
use App\Services\DocumentSecurityService;
use App\Services\DocumentUploadPolicyService;
use App\Services\EmailAttachmentPromotionService;
use App\Services\EmailAttachmentSafetyService;
use App\Services\EmailSyncAuditService;
use App\Services\ImportApplyService;
use App\Services\ImportAuditService;
use App\Services\ImportBatchService;
use App\Services\ImportDocumentSafetyService;
use App\Services\InvoiceDraftingService;
use App\Services\PaymentPlanService;
use App\Services\VirusScan\FakeVirusScanner;
use App\Services\WebhookEventRecorderService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Feature\Webhooks\Concerns\SetsUpWebhookEntitledFirm;
use Tests\TestCase;

/**
 * document.uploaded is wired at all FOUR real Document::create() call
 * sites (Phase 14b decision D): DocumentSecurityService::upload()
 * (organic), EmailAttachmentPromotionService::scanAndPromote() (email
 * attachment promotion), DocumentReplacementService::replaceWith()
 * (version replacement), and ImportApplyService's Document branch
 * (bulk import).
 */
class DocumentUploadedWiringTest extends TestCase
{
    use DatabaseMigrations, SetsUpWebhookEntitledFirm;

    public function test_document_uploaded_fires_exactly_once_from_document_security_service_upload(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $service = new DocumentSecurityService(new DocumentUploadPolicyService);

        $document = $service->upload(
            $firm,
            originalFilename: 'passport.pdf',
            mimeType: 'application/pdf',
            sizeBytes: 1024,
            storageDisk: 'local',
            storagePath: 'documents/passport.pdf',
            fileHash: hash('sha256', 'passport'),
        );

        // webhook_events is permanently FORCE RLS'd (Wave 11) — a raw,
        // no-context assertion would fail closed regardless of what
        // was actually persisted, since upload()'s own tenant context
        // has already been cleared by the time this assertion runs.
        $this->runWithFirmContext($firm, function () use ($document) {
            $this->assertDatabaseCount('webhook_events', 1);
            $this->assertDatabaseHas('webhook_events', [
                'event_type' => WebhookEventType::DocumentUploaded->value,
                'subject_type' => Document::class,
                'subject_id' => $document->id,
            ]);
        });
    }

    public function test_document_uploaded_does_not_fire_when_upload_policy_rejects_the_file(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $service = new DocumentSecurityService(new DocumentUploadPolicyService);

        try {
            $service->upload(
                $firm,
                originalFilename: 'malware.exe',
                mimeType: 'application/octet-stream',
                sizeBytes: 1024,
                storageDisk: 'local',
                storagePath: 'documents/malware.exe',
                fileHash: hash('sha256', 'malware'),
            );
            $this->fail('Expected an InvalidArgumentException for a blocked extension.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_recorder_exception_does_not_break_document_security_service_upload(): void
    {
        $this->mock(WebhookEventRecorderService::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('simulated recorder failure'));
        });

        $firm = $this->makeWebhookEntitledFirm();
        $service = new DocumentSecurityService(new DocumentUploadPolicyService);

        $document = $service->upload(
            $firm,
            originalFilename: 'still-uploaded.pdf',
            mimeType: 'application/pdf',
            sizeBytes: 1024,
            storageDisk: 'local',
            storagePath: 'documents/still-uploaded.pdf',
            fileHash: hash('sha256', 'still-uploaded'),
        );

        $this->runWithFirmContext($firm, fn () => $this->assertDatabaseHas('documents', ['id' => $document->id, 'original_filename' => 'still-uploaded.pdf']));
    }

    public function test_document_uploaded_fires_exactly_once_from_email_attachment_promotion(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $account = EmailAccount::factory()->create(['firm_id' => $firm->id]);
        $message = EmailMessage::factory()->forAccount($account)->create(['storage_mode' => EmailStorageMode::EncryptedBodyAndAttachments]);
        $attachment = EmailAttachment::factory()->forMessage($message)->create();

        $service = new EmailAttachmentPromotionService(
            new EmailAttachmentSafetyService(new DocumentUploadPolicyService, new FakeVirusScanner),
            new EmailSyncAuditService,
        );

        $result = $service->scanAndPromote($attachment);

        $this->assertTrue($result->promoted);
        $this->runWithFirmContext($firm, function () {
            $this->assertDatabaseCount('webhook_events', 1);
            $this->assertDatabaseHas('webhook_events', ['event_type' => WebhookEventType::DocumentUploaded->value]);
        });
    }

    public function test_document_uploaded_does_not_fire_when_attachment_promotion_is_blocked(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $account = EmailAccount::factory()->create(['firm_id' => $firm->id]);
        // Deliberately left at the default MetadataOnly storage_mode -> blocked before any Document is created.
        $message = EmailMessage::factory()->forAccount($account)->create();
        $attachment = EmailAttachment::factory()->forMessage($message)->create();

        $service = new EmailAttachmentPromotionService(
            new EmailAttachmentSafetyService(new DocumentUploadPolicyService, new FakeVirusScanner),
            new EmailSyncAuditService,
        );

        $result = $service->scanAndPromote($attachment);

        $this->assertFalse($result->promoted);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_document_uploaded_fires_exactly_once_from_document_replacement(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $original = Document::factory()->create(['firm_id' => $firm->id]);
        $service = new DocumentReplacementService;

        $replacement = $service->replaceWith(
            $original,
            storageDisk: 'local',
            storagePath: 'documents/replacement.pdf',
            originalFilename: 'replacement.pdf',
            mimeType: 'application/pdf',
            sizeBytes: 2048,
            fileHash: hash('sha256', 'replacement'),
        );

        $this->runWithFirmContext($firm, function () use ($original, $replacement) {
            $this->assertDatabaseCount('webhook_events', 1);
            $this->assertDatabaseHas('webhook_events', [
                'event_type' => WebhookEventType::DocumentUploaded->value,
                'subject_type' => Document::class,
                'subject_id' => $replacement->id,
            ]);

            // The original document is never re-fired as document.uploaded.
            $this->assertDatabaseMissing('webhook_events', ['subject_type' => Document::class, 'subject_id' => $original->id]);
        });
    }

    public function test_document_uploaded_fires_exactly_once_from_bulk_import(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $auditService = new ImportAuditService;
        $batchService = new ImportBatchService($auditService);
        $documentSafetyService = new ImportDocumentSafetyService(new DocumentUploadPolicyService, new FakeVirusScanner);
        $service = new ImportApplyService($documentSafetyService, $auditService, app(InvoiceDraftingService::class), app(PaymentPlanService::class));

        $batch = $batchService->create($firm, ImportEntityType::Document, ImportSourceType::CsvUpload);
        $batchService->stageRows($batch, [[
            'storage_path' => 'documents/imported.pdf',
            'original_filename' => 'imported.pdf',
            'size_bytes' => 1024,
        ]]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $confirmed = $service->confirmBatch($batch);
        $service->apply($confirmed);

        $this->runWithFirmContext($firm, function () {
            $this->assertDatabaseCount('webhook_events', 1);
            $this->assertDatabaseHas('webhook_events', ['event_type' => WebhookEventType::DocumentUploaded->value]);
        });
    }
}
