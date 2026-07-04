<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentSecurityService;
use App\Services\VirusScan\VirusScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ScanDocumentJob — queued after DocumentSecurityService::upload()
 * leaves a document at scan_status=Pending. Resolves VirusScanner
 * (bound to FakeVirusScanner in this phase — no real ClamAV daemon
 * required, project rule) and applies the result via
 * DocumentSecurityService::applyScanResult(), which is the ONLY place
 * scan_status/document status transition as a result of a scan.
 */
class ScanDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $documentId)
    {
    }

    public function handle(VirusScanner $scanner, DocumentSecurityService $documentSecurity): void
    {
        $document = Document::query()->find($this->documentId);

        if (! $document) {
            // Document was deleted/replaced before the scan ran — nothing to do.
            return;
        }

        $result = $scanner->scan($document->storage_disk, $document->storage_path);

        $documentSecurity->applyScanResult($document, $result);
    }
}
