<?php

namespace App\Services;

use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Enums\DomainEventType;
use App\Enums\WebhookEventType;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\Automation\DomainEventRecorderService;
use App\ValueObjects\VirusScanResult;
use Illuminate\Support\Facades\DB;

/**
 * DocumentSecurityService — the only place a Document row is created
 * or has its lifecycle status/scan outcome applied. Documents are
 * private by default and never exposed via a public URL (project
 * rule) — canAccess() is the explicit check any future signed-URL/
 * download endpoint must call first. A document may only reach
 * Approved while scan_status is Clean (project rule: virus scanning
 * must be enforced) — enforced here, not left to callers.
 *
 * Phase 14b addition: upload() fires document.uploaded exactly once
 * per successful creation. This method is NOT wrapped in an explicit
 * DB::transaction() (the single Document::create() call below is
 * already its own durable write), so DB::afterCommit() executes the
 * closure immediately, synchronously, with no active transaction to
 * wait for — this is documented Laravel behavior (a connection with
 * transactionLevel() === 0 runs afterCommit callbacks right away
 * rather than deferring them), covered by a dedicated wiring test
 * (Phase 14b rule 13).
 */
class DocumentSecurityService
{
    public function __construct(
        private DocumentUploadPolicyService $uploadPolicy,
        private DomainEventRecorderService $domainEvents,
    ) {}

    public function upload(
        Firm $firm,
        string $originalFilename,
        string $mimeType,
        int $sizeBytes,
        string $storageDisk,
        string $storagePath,
        string $fileHash,
        ?Matter $matter = null,
        ?Client $client = null,
        ?DocumentRequestItem $requestItem = null,
        ?User $uploadedBy = null,
        ?TenantEncryptionKey $encryptionKey = null,
    ): Document {
        $this->uploadPolicy->assertUploadIsAllowed($originalFilename, $sizeBytes);

        // Wrapped only around the create() call, not the
        // DB::afterCommit() below — runWithFirmContext()'s own
        // transaction commits (or, inside an already-open ambient
        // transaction such as a test's, releases as a savepoint)
        // before this method continues, so transactionLevel() is back
        // to whatever it was beforehand by the time afterCommit() is
        // reached, preserving the "runs immediately outside a real
        // transaction" behavior this method's own docblock documents.
        $document = (new TenantContextService)->runWithFirmContext($firm, function () use (
            $firm, $matter, $client, $requestItem, $storageDisk, $storagePath, $originalFilename,
            $mimeType, $sizeBytes, $fileHash, $encryptionKey, $uploadedBy,
        ) {
            $document = Document::create([
                'firm_id' => $firm->id,
                'matter_id' => $matter?->id,
                'client_id' => $client?->id,
                'document_request_item_id' => $requestItem?->id,
                'status' => DocumentStatus::Uploaded,
                'scan_status' => DocumentScanStatus::Pending,
                'storage_disk' => $storageDisk,
                'storage_path' => $storagePath,
                'original_filename' => $originalFilename,
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
                'file_hash' => $fileHash,
                'encryption_key_id' => $encryptionKey?->id,
                'uploaded_by' => $uploadedBy?->id,
            ]);

            $this->domainEvents->record($firm, DomainEventType::DocumentUploaded, [
                'document' => [
                    'id' => $document->id,
                    'file_name' => $document->original_filename,
                    'document_request_item_id' => $document->document_request_item_id,
                    'matter_id' => $document->matter_id,
                ],
                'matter' => [
                    'id' => $matter?->id,
                    'assigned_attorney_id' => $matter?->assigned_attorney_id,
                ],
                'client' => ['id' => $client?->id],
            ], subject: $document);

            return $document;
        });

        DB::afterCommit(function () use ($firm, $document) {
            try {
                app(WebhookEventRecorderService::class)->record($firm, WebhookEventType::DocumentUploaded, $document);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $document;
    }

    /**
     * Applied by ScanDocumentJob once a VirusScanner has run. Never
     * called directly by controllers/tests bypassing the scan step —
     * that would defeat the entire point of the scan gate.
     */
    public function applyScanResult(Document $document, VirusScanResult $result): Document
    {
        return (new TenantContextService)->runWithFirmContext($document->firm_id, function () use ($document, $result) {
            $document->update([
                'scan_status' => $result->status,
                'scan_result_detail' => $result->detail,
                'scanned_at' => now(),
            ]);

            if ($result->status === DocumentScanStatus::Infected) {
                $document->update([
                    'status' => DocumentStatus::Rejected,
                    'rejected_reason' => 'Virus scan detected malware: '.($result->threatName ?? 'unknown signature'),
                ]);
            }

            return $document->fresh();
        });
    }

    public function approve(Document $document, User $approver): Document
    {
        if (! $document->isUsable()) {
            throw new \RuntimeException('Only a document with a clean scan result can be approved.');
        }

        return (new TenantContextService)->runWithFirmContext($document->firm_id, function () use ($document, $approver) {
            $document->update([
                'status' => DocumentStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            return $document->fresh();
        });
    }

    public function reject(Document $document, User $approver, string $reason): Document
    {
        return (new TenantContextService)->runWithFirmContext($document->firm_id, function () use ($document, $approver, $reason) {
            $document->update([
                'status' => DocumentStatus::Rejected,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'rejected_reason' => $reason,
            ]);

            return $document->fresh();
        });
    }

    /**
     * The explicit private-access check. Documents are never exposed
     * via a public URL; any future signed-URL/download endpoint must
     * call this first (project rule).
     */
    public function canAccess(Document $document, Firm $contextFirm): bool
    {
        return $document->firm_id === $contextFirm->id;
    }
}
