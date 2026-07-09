<?php

namespace App\Services;

use App\Enums\DocumentRequestItemStatus;
use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentVersionStatus;
use App\Enums\WebhookEventType;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * DocumentReplacementService — the only place a new DocumentVersion is
 * created and the only place a document's replacement chain
 * (replaces_document_id) is set. "Clients cannot hard-delete submitted
 * documents unless firm policy allows replacement and preserves
 * history" (project rule) — the prior version/document is never
 * deleted, only superseded.
 *
 * Phase 14b addition: replaceWith() fires document.uploaded exactly
 * once for the new replacement Document (never for $original, which is
 * merely marked Replaced, not re-uploaded), registered via
 * DB::afterCommit() from inside this method's existing
 * DB::transaction() so it never fires ahead of the durable commit and
 * rolls back together with the version snapshot / original-document
 * update if anything here throws.
 */
class DocumentReplacementService
{
    /**
     * Snapshots the document's CURRENT storage pointer into a new
     * document_versions row before the document itself is updated to
     * point at the new file — this is what "preserves history" means
     * concretely.
     */
    public function captureCurrentAsVersion(Document $document): DocumentVersion
    {
        $nextVersion = ($document->versions()->max('version_number') ?? 0) + 1;

        return DB::transaction(function () use ($document, $nextVersion) {
            $document->versions()->where('status', DocumentVersionStatus::Current)
                ->update(['status' => DocumentVersionStatus::Superseded]);

            return DocumentVersion::create([
                'document_id' => $document->id,
                'version_number' => $nextVersion,
                'status' => DocumentVersionStatus::Current,
                'storage_disk' => $document->storage_disk,
                'storage_path' => $document->storage_path,
                'file_hash' => $document->file_hash,
                'size_bytes' => $document->size_bytes,
                'uploaded_by' => $document->uploaded_by,
            ]);
        });
    }

    /**
     * Replaces $original with a brand-new Document row (its own scan
     * lifecycle starts over at Pending) linked back via
     * replaces_document_id. $original is marked NeedsReplacement's
     * resolution — its own status becomes Replaced, never deleted.
     */
    public function replaceWith(
        Document $original,
        string $storageDisk,
        string $storagePath,
        string $originalFilename,
        string $mimeType,
        int $sizeBytes,
        string $fileHash,
        ?User $uploadedBy = null,
    ): Document {
        return (new TenantContextService())->runWithFirmContext($original->firm_id, function () use ($original, $storageDisk, $storagePath, $originalFilename, $mimeType, $sizeBytes, $fileHash, $uploadedBy) {
            $this->captureCurrentAsVersion($original);

            $replacement = Document::create([
                'firm_id' => $original->firm_id,
                'matter_id' => $original->matter_id,
                'client_id' => $original->client_id,
                'document_request_item_id' => $original->document_request_item_id,
                'status' => DocumentStatus::Uploaded,
                'scan_status' => DocumentScanStatus::Pending,
                'storage_disk' => $storageDisk,
                'storage_path' => $storagePath,
                'original_filename' => $originalFilename,
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
                'file_hash' => $fileHash,
                'uploaded_by' => $uploadedBy?->id,
                'replaces_document_id' => $original->id,
            ]);

            $original->update(['status' => DocumentStatus::Replaced]);

            if ($original->documentRequestItem && $original->documentRequestItem->status === DocumentRequestItemStatus::NeedsReplacement) {
                $original->documentRequestItem->update(['status' => DocumentRequestItemStatus::Submitted, 'submitted_at' => now()]);
            }

            DB::afterCommit(function () use ($replacement) {
                try {
                    app(WebhookEventRecorderService::class)->record($replacement->firm, WebhookEventType::DocumentUploaded, $replacement);
                } catch (\Throwable $e) {
                    report($e);
                }
            });

            return $replacement;
        });
    }
}
