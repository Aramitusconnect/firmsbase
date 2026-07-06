<?php

namespace App\Services;

use App\Enums\FormDraftStatus;
use App\Enums\FormDraftValueSource;
use App\Enums\FormMappingContentStatus;
use App\Enums\FormReviewEventType;
use App\Enums\WebhookEventType;
use App\Models\FirmUser;
use App\Models\FormDraft;
use App\Models\FormReviewEvent;
use Illuminate\Support\Facades\DB;

/**
 * FormReviewService — every status transition on a FormDraft goes
 * through here, delegating transition legality to
 * ReviewWorkflowTransitionService first. No AI approval path exists —
 * every method requires an explicit FirmUser actor.
 *
 * moveToReadyForReview() is the single, always-fresh gate for
 * "required data is complete": it re-runs FormMissingDataDetectionService
 * every time, from draft/needs_data/revised, rather than trusting a
 * flag set once.
 *
 * approve() is the corrected, LIVE gate: it re-derives eligibility
 * from form_draft_values.form_mapping_rule_id -> content_status at the
 * moment of approval (not the stale used_sample_mapping snapshot from
 * generation time), and refreshes used_sample_mapping as a side
 * effect. Approval additionally requires the review checklist to be
 * complete.
 *
 * Phase 14b addition: approve() fires form.approved exactly once, only
 * after every precondition above has already passed and the status
 * update + review event have both been written — never on the
 * role-check throw, the transition-not-allowed throw, the incomplete-
 * checklist throw, or the used-sample-mapping throw, all of which
 * happen before this line is ever reached. Not wrapped in an explicit
 * DB::transaction(); DB::afterCommit() runs the closure immediately
 * since there is no active transaction to defer past.
 */
class FormReviewService
{
    public function __construct(
        private readonly ReviewWorkflowTransitionService $transitions,
        private readonly FormMissingDataDetectionService $missingDataDetectionService,
        private readonly FormReviewChecklistService $checklistService,
        private readonly FormAndDocumentAccessPolicyService $accessPolicy,
    ) {
    }

    public function moveToReadyForReview(FormDraft $draft): FormDraft
    {
        $missingResult = $this->missingDataDetectionService->scan($draft);

        if (! $missingResult->isComplete()) {
            $this->transitions->assertTransitionAllowed($draft->status->value, 'needs_data');
            $draft->update(['status' => FormDraftStatus::NeedsData]);

            throw new \RuntimeException(
                'Cannot move to ready_for_review: required data is still missing for field(s): '.implode(', ', $missingResult->missingFieldCodes)
            );
        }

        $this->transitions->assertTransitionAllowed($draft->status->value, FormDraftStatus::ReadyForReview->value);
        $draft->update(['status' => FormDraftStatus::ReadyForReview]);

        $this->recordEvent($draft, FormReviewEventType::MarkedReadyForReview, $draft->generatedByFirmUser);

        return $draft->fresh();
    }

    public function submitForAttorneyReview(FormDraft $draft, FirmUser $actor): FormDraft
    {
        $this->transitions->assertTransitionAllowed($draft->status->value, FormDraftStatus::AttorneyReview->value);
        $draft->update(['status' => FormDraftStatus::AttorneyReview]);

        $this->recordEvent($draft, FormReviewEventType::SubmittedForAttorneyReview, $actor);

        return $draft->fresh();
    }

    /**
     * The corrected, live approval gate.
     */
    public function approve(FormDraft $draft, FirmUser $actor): FormDraft
    {
        if (! $this->accessPolicy->canApprove($actor)) {
            throw new \RuntimeException('Actor role is not permitted to approve a form draft.');
        }

        $this->transitions->assertTransitionAllowed($draft->status->value, FormDraftStatus::Approved->value);

        if (! $this->checklistService->isComplete($draft)) {
            throw new \RuntimeException('Cannot approve: the review checklist is not complete.');
        }

        $usedSampleMapping = $draft->values()
            ->whereNotNull('form_mapping_rule_id')
            ->with('formMappingRule')
            ->get()
            ->contains(fn ($value) => $value->formMappingRule?->content_status === FormMappingContentStatus::SampleOnly);

        if ($usedSampleMapping) {
            $draft->update(['used_sample_mapping' => true]);

            throw new \RuntimeException(
                'Cannot approve: this draft used at least one mapping rule that is still SampleOnly. '.
                'All used mapping rules must be ReviewedApproved before approval.'
            );
        }

        $draft->update([
            'status' => FormDraftStatus::Approved,
            'used_sample_mapping' => false,
            'reviewed_by_firm_user_id' => $actor->id,
            'reviewed_at' => now(),
            'approved_at' => now(),
        ]);

        $this->recordEvent($draft, FormReviewEventType::Approved, $actor);

        $draft = $draft->fresh();

        DB::afterCommit(function () use ($draft) {
            try {
                app(WebhookEventRecorderService::class)->record($draft->firm, WebhookEventType::FormApproved, $draft);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $draft;
    }

    public function reject(FormDraft $draft, FirmUser $actor, string $reason): FormDraft
    {
        if (! $this->accessPolicy->canApprove($actor)) {
            throw new \RuntimeException('Actor role is not permitted to reject a form draft.');
        }

        $this->transitions->assertTransitionAllowed($draft->status->value, FormDraftStatus::Rejected->value);
        $draft->update(['status' => FormDraftStatus::Rejected, 'reviewed_by_firm_user_id' => $actor->id, 'reviewed_at' => now()]);

        $this->recordEvent($draft, FormReviewEventType::Rejected, $actor, $reason);

        return $draft->fresh();
    }

    public function requestRevision(FormDraft $draft, FirmUser $actor, string $notes): FormDraft
    {
        $this->transitions->assertTransitionAllowed($draft->status->value, FormDraftStatus::Revised->value);
        $draft->update(['status' => FormDraftStatus::Revised]);

        $this->recordEvent($draft, FormReviewEventType::RequestedRevision, $actor, $notes);

        return $draft->fresh();
    }

    public function resubmitAfterRevision(FormDraft $draft, FirmUser $actor): FormDraft
    {
        $result = $this->moveToReadyForReview($draft);

        $this->recordEvent($result, FormReviewEventType::ResubmittedAfterRevision, $actor);

        return $result;
    }

    public function archive(FormDraft $draft, FirmUser $actor): FormDraft
    {
        $this->transitions->assertTransitionAllowed($draft->status->value, FormDraftStatus::Archived->value);
        $draft->update(['status' => FormDraftStatus::Archived]);

        $this->recordEvent($draft, FormReviewEventType::Archived, $actor);

        return $draft->fresh();
    }

    private function recordEvent(FormDraft $draft, FormReviewEventType $type, FirmUser $actor, ?string $notes = null): void
    {
        FormReviewEvent::create([
            'firm_id' => $draft->firm_id,
            'form_draft_id' => $draft->id,
            'event_type' => $type,
            'actor_firm_user_id' => $actor->id,
            'notes' => $notes,
        ]);
    }
}
