<?php

namespace App\Services;

use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Enums\DomainEventType;
use App\Enums\FirmUserStatus;
use App\Enums\WebhookEventType;
use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\Document;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Models\FirmUser;
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
 * rule) — canAccess() is the firm-boundary check; canBeDownloadedBy()
 * (Mission 1C, section 17) is the finer-grained actor-scoped check —
 * any future signed-URL/download endpoint must call the latter first,
 * canAccess() alone is not sufficient to authorize a specific actor.
 * A document may only reach Approved while scan_status is Clean
 * (project rule: virus scanning must be enforced) — enforced here,
 * not left to callers.
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
     * Zero-Click Core Workflow Automation pass. Links an already-
     * uploaded Document to a DocumentRequestItem discovered after the
     * fact (deterministic matching — see DocumentMatchingService) —
     * the upload-time linkage upload() itself sets is the common case,
     * but several real upload paths (confirmed by audit — e.g. the
     * client-portal fallback page, email-attachment promotion) never
     * know the target item at creation time. Never overwrites an
     * existing link (a document already tied to one request item is
     * never silently re-tied to another), and never links across a
     * firm boundary.
     */
    public function linkToRequestItem(Document $document, DocumentRequestItem $item): Document
    {
        if ($document->document_request_item_id !== null) {
            throw new \RuntimeException('This document is already linked to a document request item.');
        }

        return (new TenantContextService)->runWithFirmContext($document->firm_id, function () use ($document, $item) {
            if ((int) $item->documentRequest->firm_id !== (int) $document->firm_id) {
                throw new \RuntimeException('This document request item does not belong to this document\'s firm.');
            }

            $document->update(['document_request_item_id' => $item->id]);

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

    /**
     * The actor-scoped download-authorization primitive — Mission 1C
     * (Security Validation, Activation & Staging Proof), section 17:
     * "prove the authorization primitive without building the full
     * feature." canAccess() above only proves firm-boundary
     * membership; it does NOT prove this specific actor may reach this
     * specific document. A Paralegal in the right firm with no
     * MatterAssignment for the document's matter would still pass
     * canAccess() — a future download/signed-URL endpoint trusting
     * canAccess() alone would reopen exactly the IDOR gap
     * MatterAccessPolicyService/ClientPortalMatterAccessPolicyService
     * were already built to close for matters elsewhere. This method
     * composes those two proven, tested policies so the real boundary
     * is correct from day one. Deliberately adds no route, controller,
     * or storage-layer code — out of Mission 1C's own scope.
     *
     * A document with no matter_id (firm-level, never linked to a
     * matter) is accessible to any active firm-staff member of the
     * owning firm, but to no Client Portal user — Client Portal access
     * is always via an explicit matter grant (project rule; see
     * ClientPortalMatterAccessPolicyService's own docblock), and no
     * equivalent matter-independent grant concept exists for a Client
     * Portal user, so this method does not invent one.
     */
    public function canBeDownloadedBy(Document $document, User|ClientPortalUser $actor): bool
    {
        if ($actor instanceof ClientPortalUser) {
            if ($document->matter_id === null) {
                return false;
            }

            return app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($actor, $document->matter);
        }

        if ($document->matter_id !== null) {
            return app(MatterAccessPolicyService::class)->canAccessMatter($actor, $document->matter);
        }

        return (new TenantContextService)->runWithFirmContext(
            $document->firm_id,
            fn () => FirmUser::query()
                ->where('user_id', $actor->id)
                ->where('firm_id', $document->firm_id)
                ->where('status', FirmUserStatus::Active)
                ->exists(),
        );
    }
}
