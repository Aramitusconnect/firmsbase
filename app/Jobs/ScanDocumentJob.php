<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentSecurityService;
use App\Services\VirusScan\VirusScanner;
use App\Support\TenantAwareJobContext;
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
 *
 * Mission 1B (Extreme Security Hardening) fix: `documents` is one of
 * the tables under FORCE ROW LEVEL SECURITY, so the unscoped
 * `Document::query()->find()` this job used to run always returned
 * null once RLS was actually live — indistinguishable from the
 * legitimate "document was deleted before the scan ran" case, so this
 * silently disabled scanning fleet-wide with no error surfaced. Firm
 * context is now explicit (via TenantAwareJobContext, the established
 * pattern — see that trait's own docblock), supplied by the caller at
 * dispatch time, never inferred from the document row itself.
 */
class ScanDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    public function __construct(public int $documentId, public int $firmId) {}

    public function handle(VirusScanner $scanner, DocumentSecurityService $documentSecurity): void
    {
        $this->runInFirmContext($this->firmId, function () use ($scanner, $documentSecurity) {
            $document = Document::query()->find($this->documentId);

            if (! $document) {
                // Document was deleted/replaced before the scan ran — nothing to do.
                return;
            }

            $result = $scanner->scan($document->storage_disk, $document->storage_path);

            $documentSecurity->applyScanResult($document, $result);
        });
    }
}
