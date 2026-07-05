<?php

namespace App\Services;

use App\Enums\PdfAnnotationType;
use App\Enums\PdfViewerViewerType;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\GeneratedDocument;
use App\Models\PdfViewEvent;
use App\Models\SignatureRequestRecipient;

/**
 * PdfAnnotationService — "annotations if enabled" (master-plan scope
 * wording), represented entirely within pdf_view_events (action =
 * annotation_added) — no dedicated pdf_annotation_events table exists.
 * Disabled unless enabled: this service refuses to write ANY
 * annotation row unless the firm's EXISTING e_signature entitlement
 * has settings_json.annotations_enabled = true. No new module_catalog
 * row is added for this.
 */
class PdfAnnotationService
{
    public function __construct(
        private readonly SignatureAndPdfAccessPolicyService $accessPolicy,
        private readonly PdfViewEventService $viewEventService,
    ) {
    }

    public function annotate(
        Firm $firm,
        PdfViewerViewerType $viewerType,
        ?FirmUser $viewerFirmUser,
        ?SignatureRequestRecipient $viewerRecipient,
        SignatureSourceDocumentType $sourceType,
        ?Document $document,
        ?GeneratedDocument $generatedDocument,
        PdfAnnotationType $annotationType,
        int $pageNumber,
        ?string $content,
        string $ipAddress,
        string $userAgent,
    ): PdfViewEvent {
        if (! $this->accessPolicy->annotationsEnabledForFirm($firm->id)) {
            throw new \RuntimeException(
                'Annotations are disabled for this firm. Annotations must be explicitly enabled via the e_signature entitlement settings before any annotation can be recorded.'
            );
        }

        return $this->viewEventService->recordAnnotation(
            $firm, $viewerType, $viewerFirmUser, $viewerRecipient, $sourceType, $document, $generatedDocument,
            $annotationType, $pageNumber, $content, $ipAddress, $userAgent,
        );
    }
}
