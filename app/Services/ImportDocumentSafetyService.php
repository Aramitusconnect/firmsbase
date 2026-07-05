<?php

namespace App\Services;

use App\Enums\DocumentScanStatus;
use App\Models\Document;
use App\Models\Firm;
use App\Models\ImportRow;
use App\Services\VirusScan\VirusScanner;

/**
 * ImportDocumentSafetyService — every imported document MUST pass the
 * existing DocumentUploadPolicyService (extension/size allowlist) and
 * VirusScanner foundation (FakeVirusScanner in every environment
 * without a real daemon) before being accepted (project rule 9).
 * firm_id is ALWAYS taken from the import batch's own firm, never from
 * anything in the row's raw/mapped data — this is what makes "no
 * imported document may cross firms" true regardless of what a
 * malicious or malformed source row claims (project rule 10).
 */
class ImportDocumentSafetyService
{
    public function __construct(
        private readonly DocumentUploadPolicyService $uploadPolicyService,
        private readonly VirusScanner $virusScanner,
    ) {
    }

    /**
     * Validates and scans a staged document row's file metadata before
     * ImportApplyService is allowed to create the Document record.
     * Throws on policy rejection; returns the scan status otherwise.
     */
    public function assertSafeToAccept(Firm $firm, ImportRow $row): DocumentScanStatus
    {
        $data = $row->mapped_data ?? $row->raw_data;

        $originalFilename = $data['original_filename'] ?? null;
        $sizeBytes = (int) ($data['size_bytes'] ?? 0);
        $storagePath = $data['storage_path'] ?? '';

        if ($originalFilename === null) {
            throw new \InvalidArgumentException('Imported document row is missing original_filename.');
        }

        $this->uploadPolicyService->assertUploadIsAllowed($originalFilename, $sizeBytes);

        $scanResult = $this->virusScanner->scan($data['storage_disk'] ?? 'local', $storagePath);

        if ($scanResult->status === DocumentScanStatus::Infected) {
            throw new \RuntimeException("Imported document failed virus scan: {$scanResult->detail}");
        }

        return $scanResult->status;
    }

    /**
     * Confirms an already-created Document row truly belongs to the
     * importing firm — defense in depth alongside
     * assertSafeToAccept()'s use of $firm rather than row data.
     */
    public function assertDocumentBelongsToFirm(Document $document, Firm $firm): void
    {
        if ($document->firm_id !== $firm->id) {
            throw new \RuntimeException('Imported document must not cross firms.');
        }
    }
}
