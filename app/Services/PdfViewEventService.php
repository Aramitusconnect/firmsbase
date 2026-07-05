<?php

namespace App\Services;

use App\Enums\PdfAnnotationType;
use App\Enums\PdfViewEventAction;
use App\Enums\PdfViewerViewerType;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\GeneratedDocument;
use App\Models\PdfViewEvent;
use App\Models\SignatureRequestRecipient;
use App\ValueObjects\PdfAccessDecision;

/**
 * PdfViewEventService — the single writer of pdf_view_events. Views,
 * download decisions, and (if enabled) annotations are all
 * represented as rows of this one append-only table — no
 * pdf_view_sessions or pdf_annotation_events table exists. A download
 * decision is NEVER applied silently: recordDownloadRequested() logs
 * the request first, and the caller must separately call
 * recordDownloadDecision() with the PdfAccessDecision returned by
 * PdfDownloadPolicyService.
 */
class PdfViewEventService
{
    public function recordOpened(
        Firm $firm,
        PdfViewerViewerType $viewerType,
        ?FirmUser $viewerFirmUser,
        ?SignatureRequestRecipient $viewerRecipient,
        SignatureSourceDocumentType $sourceType,
        ?Document $document,
        ?GeneratedDocument $generatedDocument,
        string $ipAddress,
        string $userAgent,
    ): PdfViewEvent {
        return $this->write(
            $firm, $viewerType, $viewerFirmUser, $viewerRecipient, $sourceType, $document, $generatedDocument,
            PdfViewEventAction::Opened, $ipAddress, $userAgent,
        );
    }

    public function recordDownloadRequested(
        Firm $firm,
        PdfViewerViewerType $viewerType,
        ?FirmUser $viewerFirmUser,
        ?SignatureRequestRecipient $viewerRecipient,
        SignatureSourceDocumentType $sourceType,
        ?Document $document,
        ?GeneratedDocument $generatedDocument,
        string $ipAddress,
        string $userAgent,
    ): PdfViewEvent {
        return $this->write(
            $firm, $viewerType, $viewerFirmUser, $viewerRecipient, $sourceType, $document, $generatedDocument,
            PdfViewEventAction::DownloadRequested, $ipAddress, $userAgent,
        );
    }

    /**
     * Logs the OUTCOME of a PdfDownloadPolicyService decision as its
     * own, separate row — a download is never allowed implicitly.
     */
    public function recordDownloadDecision(
        PdfAccessDecision $decision,
        Firm $firm,
        PdfViewerViewerType $viewerType,
        ?FirmUser $viewerFirmUser,
        ?SignatureRequestRecipient $viewerRecipient,
        SignatureSourceDocumentType $sourceType,
        ?Document $document,
        ?GeneratedDocument $generatedDocument,
        string $ipAddress,
        string $userAgent,
    ): PdfViewEvent {
        // The human-readable $decision->reason is returned synchronously to
        // the caller; it is not persisted here — pdf_view_events captures
        // the evidentiary FACT (which action occurred), not the policy
        // service's transient explanation for it.
        $action = $decision->allowed ? PdfViewEventAction::DownloadAllowed : PdfViewEventAction::DownloadDenied;

        return $this->write(
            $firm, $viewerType, $viewerFirmUser, $viewerRecipient, $sourceType, $document, $generatedDocument,
            $action, $ipAddress, $userAgent,
        );
    }

    /**
     * Only ever called by PdfAnnotationService, which refuses to call
     * this method at all unless the firm's entitlement explicitly
     * enables annotations — "disabled unless enabled" is enforced by
     * the caller, not here.
     */
    public function recordAnnotation(
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
        return PdfViewEvent::create([
            'firm_id' => $firm->id,
            'viewer_type' => $viewerType,
            'viewer_firm_user_id' => $viewerFirmUser?->id,
            'viewer_recipient_id' => $viewerRecipient?->id,
            'source_document_type' => $sourceType,
            'document_id' => $document?->id,
            'generated_document_id' => $generatedDocument?->id,
            'action' => PdfViewEventAction::AnnotationAdded,
            'annotation_type' => $annotationType,
            'annotation_page_number' => $pageNumber,
            'annotation_content' => $content,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'occurred_at' => now(),
        ]);
    }

    private function write(
        Firm $firm,
        PdfViewerViewerType $viewerType,
        ?FirmUser $viewerFirmUser,
        ?SignatureRequestRecipient $viewerRecipient,
        SignatureSourceDocumentType $sourceType,
        ?Document $document,
        ?GeneratedDocument $generatedDocument,
        PdfViewEventAction $action,
        string $ipAddress,
        string $userAgent,
    ): PdfViewEvent {
        return PdfViewEvent::create([
            'firm_id' => $firm->id,
            'viewer_type' => $viewerType,
            'viewer_firm_user_id' => $viewerFirmUser?->id,
            'viewer_recipient_id' => $viewerRecipient?->id,
            'source_document_type' => $sourceType,
            'document_id' => $document?->id,
            'generated_document_id' => $generatedDocument?->id,
            'action' => $action,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'occurred_at' => now(),
        ]);
    }
}
