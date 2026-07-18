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
 *
 * Section 39A-6 Wave 6: form_drafts/form_review_events are now FORCE
 * RLS protected. Every transition method wraps its own parent-row
 * update() + paired recordEvent() call + trailing ->fresh() as ONE
 * shared runWithFirmContext() unit (mirroring
 * EmailMessageLinkingService::link()'s "one wrap for a fixed
 * 2-statement unit" shape — these methods always write exactly one
 * parent row plus one paired audit event, never a variable-length
 * loop). Pure in-memory checks (assertTransitionAllowed(), canApprove(),
 * checklistService->isComplete()) stay OUTSIDE the wrap. approve() is
 * the one exception: its wrap ends immediately after $draft->fresh()
 * inside the closure — the DB::afterCommit() registration and final
 * return stay textually outside/after the wrap, because wrapping them
 * too would defer the webhook-recording callback to fire synchronously
 * inside runWithFirmContext()'s own DB::transaction() commit, i.e.
 * before this call's own finally block restores prior context (a
 * genuine DB-session/PHP-memory layer mismatch once webhook tables are
 * themselves ever FORCE RLS'd in a future wave). resubmitAfterRevision()
 * is a second exception: it does NOT wrap its call into
 * moveToReadyForReview() (which already self-wraps), since nesting would
 * risk that method's needs_data throw-after-commit path being rolled
 * back by an outer wrap's transaction boundary once the exception
 * propagates — it wraps only its own recordEvent() call, separately and
 * non-nested.
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

            (new TenantContextService())->runWithFirmContext($draft->firm_id, function () use ($draft) {
                $draft->update(['status' => FormDraftStatus::NeedsData]);
            });

            throw new \RuntimeException(
                'Cannot move to ready_for_review: required data is still missing for field(s): '.implode(', ', $missingResult->missingFieldCodes)
            );
        }

        $this->transitions->assertTransitionAllowed($draft->status->value, FormDraftStatus::ReadyForReview->value);

        return (new TenantContextService())->runWithFirmContext($draft->firm_id, function () use ($draft) {
            $draft->update(['status' => FormDraftStatus::ReadyForReview]);
            $this->recordEvent($draft, FormReviewEventType::MarkedReadyForReview, $draft->generatedByFirmUser);

            return $draft->fresh();
        });
    }

    public function submitForAttorneyReview(FormDraft $draft, FirmUser $actor): FormDraft
    {
        $this->transitions->assertTransitionAllowed($draft->status->value, FormDraftStatus::AttorneyReview->value);

        return (new TenantContextService())->runWithFirmContext($draft->firm_id, function () use ($draft, $actor) {
            $draft->update(['status' => FormDraftStatus::AttorneyReview]);
            $this->recordEvent($draft, FormReviewEventType::SubmittedForAttorneyReview, $actor);

            return $draft->fresh();
        });
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
            (new TenantContextService())->runWithFirmContext($draft->firm_id, function () use ($draft) {
                $draft->update(['used_sample_mapping' => true]);
            });

            throw new \RuntimeException(
                'Cannot approve: this draft used at least one mapping rule that is still SampleOnly. '.
                'All used mapping rules must be ReviewedApproved before approval.'
            );
        }

        $draft = (new TenantContextService())->runWithFirmContext($draft->firm_id, function () use ($draft, $actor) {
            $draft->update([
                'status' => FormDraftStatus::Approved,
                'used_sample_mapping' => false,
                'reviewed_by_firm_user_id' => $actor->id,
                'reviewed_at' => now(),
                'approved_at' => now(),
            ]);

            $this->recordEvent($draft, FormReviewEventType::Approved, $actor);

            return $draft->fresh();
        });

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

        return (new TenantContextService())->runWithFirmContext($draft->firm_id, function () use ($draft, $actor, $reason) {
            $draft->update(['status' => FormDraftStatus::Rejected, 'reviewed_by_firm_user_id' => $actor->id, 'reviewed_at' => now()]);
            $this->recordEvent($draft, FormReviewEventType::Rejected, $actor, $reason);

            return $draft->fresh();
        });
    }

    public function requestRevision(FormDraft $draft, FirmUser $actor, string $notes): FormDraft
    {
        $this->transitions->assertTransitionAllowed($draft->status->value, FormDraftStatus::Revised->value);

        return (new TenantContextService())->runWithFirmContext($draft->firm_id, function () use ($draft, $actor, $notes) {
            $draft->update(['status' => FormDraftStatus::Revised]);
            $this->recordEvent($draft, FormReviewEventType::RequestedRevision, $actor, $notes);

            return $draft->fresh();
        });
    }

    public function resubmitAfterRevision(FormDraft $draft, FirmUser $actor): FormDraft
    {
        // moveToReadyForReview() already wraps its own write(s) in
        // runWithFirmContext() — including a throw-after-commit path on
        // the needs_data branch, where the update must survive even
        // though the method then throws. Calling it from inside a
        // SECOND, outer wrap here would nest the transactions: the
        // needs_data update would commit only to an inner savepoint,
        // then get rolled back when the propagating exception reaches
        // the outer wrap's own DB::transaction() boundary. So this call
        // is deliberately left UNWRAPPED at this call site — it runs at
        // the top level exactly as if called directly — and only the
        // event this method itself records gets its own, separate,
        // non-nested wrap.
        $result = $this->moveToReadyForReview($draft);

        (new TenantContextService())->runWithFirmContext($draft->firm_id, function () use ($result, $actor) {
            $this->recordEvent($result, FormReviewEventType::ResubmittedAfterRevision, $actor);
        });

        return $result;
    }

    public function archive(FormDraft $draft, FirmUser $actor): FormDraft
    {
        $this->transitions->assertTransitionAllowed($draft->status->value, FormDraftStatus::Archived->value);

        return (new TenantContextService())->runWithFirmContext($draft->firm_id, function () use ($draft, $actor) {
            $draft->update(['status' => FormDraftStatus::Archived]);
            $this->recordEvent($draft, FormReviewEventType::Archived, $actor);

            return $draft->fresh();
        });
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
