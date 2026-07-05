<?php

namespace App\Services;

use App\Enums\DocumentScanStatus;
use App\Models\EmailAttachment;
use App\Services\VirusScan\VirusScanner;

/**
 * EmailAttachmentSafetyService — wraps the EXISTING Phase 4
 * DocumentUploadPolicyService (extension/size allowlist) and
 * VirusScanner foundation (FakeVirusScanner — no real daemon), exactly
 * mirroring Phase 8's ImportDocumentSafetyService. Only ever invoked
 * by EmailAttachmentPromotionService, and only for messages captured
 * under storage_mode EncryptedBodyAndAttachments (approved storage-
 * mode matrix — scanning is not "actually supported" for the other
 * three modes, so this class is never called for them).
 */
class EmailAttachmentSafetyService
{
    public function __construct(
        private readonly DocumentUploadPolicyService $uploadPolicyService,
        private readonly VirusScanner $virusScanner,
    ) {
    }

    public function assertSafeAndScan(EmailAttachment $attachment): DocumentScanStatus
    {
        $this->uploadPolicyService->assertUploadIsAllowed($attachment->original_filename, $attachment->size_bytes);

        $result = $this->virusScanner->scan('local', $attachment->simulated_storage_path);

        return $result->status;
    }
}
