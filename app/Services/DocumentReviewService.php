<?php

namespace App\Services;

use App\Enums\DocumentTemplateContentStatus;
use App\Enums\GeneratedDocumentEventType;
use App\Enums\GeneratedDocumentStatus;
use App\Models\FirmUser;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentEvent;

/**
 * DocumentReviewService — mirrors FormReviewService's transition
 * handling via the shared ReviewWorkflowTransitionService.
 *
 * approve() is the final-corrected, LIVE gate: it re-checks the
 * CURRENT document_template_version.content_status at the moment of
 * approval (not the used_sample_content snapshot taken at generation
 * time). If still SampleOnly, approval throws — a generated document
 * created from SampleOnly content may be reviewed, revised, rejected,
 * or archived, but cannot become Approved until the template version
 * is ReviewedApproved. On success, used_sample_content is refreshed
 * to false.
 */
class DocumentReviewService
{
    public function __construct(
        private readonly ReviewWorkflowTransitionService $transitions,
        private readonly FormAndDocumentAccessPolicyService $accessPolicy,
    ) {
    }

    public function markNeedsData(GeneratedDocument $document): GeneratedDocument
    {
        $this->transitions->assertTransitionAllowed($document->status->value, GeneratedDocumentStatus::NeedsData->value);
        $document->update(['status' => GeneratedDocumentStatus::NeedsData]);

        return $document->fresh();
    }

    public function moveToReadyForReview(GeneratedDocument $document): GeneratedDocument
    {
        $this->transitions->assertTransitionAllowed($document->status->value, GeneratedDocumentStatus::ReadyForReview->value);
        $document->update(['status' => GeneratedDocumentStatus::ReadyForReview]);

        $this->recordEvent($document, GeneratedDocumentEventType::MarkedReadyForReview, $document->generatedByFirmUser);

        return $document->fresh();
    }

    public function submitForAttorneyReview(GeneratedDocument $document, FirmUser $actor): GeneratedDocument
    {
        $this->transitions->assertTransitionAllowed($document->status->value, GeneratedDocumentStatus::AttorneyReview->value);
        $document->update(['status' => GeneratedDocumentStatus::AttorneyReview]);

        $this->recordEvent($document, GeneratedDocumentEventType::SubmittedForAttorneyReview, $actor);

        return $document->fresh();
    }

    /**
     * The final-corrected, live content-status approval gate.
     */
    public function approve(GeneratedDocument $document, FirmUser $actor): GeneratedDocument
    {
        if (! $this->accessPolicy->canApprove($actor)) {
            throw new \RuntimeException('Actor role is not permitted to approve a generated document.');
        }

        $this->transitions->assertTransitionAllowed($document->status->value, GeneratedDocumentStatus::Approved->value);

        $currentContentStatus = $document->documentTemplateVersion->content_status;

        if ($currentContentStatus === DocumentTemplateContentStatus::SampleOnly) {
            $document->update(['used_sample_content' => true]);

            throw new \RuntimeException(
                'Cannot approve: the document_template_version used to generate this document is still SampleOnly. '.
                'It must be ReviewedApproved before this document can be approved.'
            );
        }

        $document->update([
            'status' => GeneratedDocumentStatus::Approved,
            'used_sample_content' => false,
            'reviewed_by_firm_user_id' => $actor->id,
            'reviewed_at' => now(),
            'approved_at' => now(),
        ]);

        $this->recordEvent($document, GeneratedDocumentEventType::Approved, $actor);

        return $document->fresh();
    }

    public function reject(GeneratedDocument $document, FirmUser $actor, string $reason): GeneratedDocument
    {
        if (! $this->accessPolicy->canApprove($actor)) {
            throw new \RuntimeException('Actor role is not permitted to reject a generated document.');
        }

        $this->transitions->assertTransitionAllowed($document->status->value, GeneratedDocumentStatus::Rejected->value);
        $document->update(['status' => GeneratedDocumentStatus::Rejected, 'reviewed_by_firm_user_id' => $actor->id, 'reviewed_at' => now()]);

        $this->recordEvent($document, GeneratedDocumentEventType::Rejected, $actor, $reason);

        return $document->fresh();
    }

    public function requestRevision(GeneratedDocument $document, FirmUser $actor, string $notes): GeneratedDocument
    {
        $this->transitions->assertTransitionAllowed($document->status->value, GeneratedDocumentStatus::Revised->value);
        $document->update(['status' => GeneratedDocumentStatus::Revised]);

        $this->recordEvent($document, GeneratedDocumentEventType::RequestedRevision, $actor, $notes);

        return $document->fresh();
    }

    public function resubmitAfterRevision(GeneratedDocument $document, FirmUser $actor): GeneratedDocument
    {
        $result = $this->moveToReadyForReview($document);

        $this->recordEvent($result, GeneratedDocumentEventType::ResubmittedAfterRevision, $actor);

        return $result;
    }

    public function archive(GeneratedDocument $document, FirmUser $actor): GeneratedDocument
    {
        $this->transitions->assertTransitionAllowed($document->status->value, GeneratedDocumentStatus::Archived->value);
        $document->update(['status' => GeneratedDocumentStatus::Archived]);

        $this->recordEvent($document, GeneratedDocumentEventType::Archived, $actor);

        return $document->fresh();
    }

    private function recordEvent(GeneratedDocument $document, GeneratedDocumentEventType $type, FirmUser $actor, ?string $notes = null): void
    {
        GeneratedDocumentEvent::create([
            'firm_id' => $document->firm_id,
            'generated_document_id' => $document->id,
            'event_type' => $type,
            'actor_firm_user_id' => $actor->id,
            'notes' => $notes,
        ]);
    }
}
