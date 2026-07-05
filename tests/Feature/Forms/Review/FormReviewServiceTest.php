<?php

namespace Tests\Feature\Forms\Review;

use App\Enums\FirmUserRole;
use App\Enums\FormDraftStatus;
use App\Enums\FormDraftValueSource;
use App\Models\FirmUser;
use App\Models\FormDraft;
use App\Models\FormDraftValue;
use App\Models\FormField;
use App\Services\FormAndDocumentAccessPolicyService;
use App\Services\FormMissingDataDetectionService;
use App\Services\FormReviewChecklistService;
use App\Services\FormReviewService;
use App\Services\ReviewWorkflowTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private FormReviewService $service;
    private FormReviewChecklistService $checklistService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checklistService = new FormReviewChecklistService();
        $this->service = new FormReviewService(
            new ReviewWorkflowTransitionService(),
            new FormMissingDataDetectionService(),
            $this->checklistService,
            new FormAndDocumentAccessPolicyService(app(\App\Services\EntitlementService::class)),
        );
    }

    private function completeDraft(): FormDraft
    {
        $draft = FormDraft::factory()->create(['status' => FormDraftStatus::Draft->value]);
        // No required fields on the version -> missing-data scan is vacuously complete.
        $this->checklistService->seedDefaults($draft);
        foreach ($draft->fresh()->checklistItems as $item) {
            $this->checklistService->check($item, FirmUser::factory()->create(['firm_id' => $draft->firm_id]));
        }

        return $draft->fresh();
    }

    public function test_move_to_ready_for_review_blocks_when_required_data_is_missing(): void
    {
        $draft = FormDraft::factory()->create(['status' => FormDraftStatus::Draft->value]);
        $field = FormField::factory()->required()->forVersion($draft->formTemplateVersion)->create();
        FormDraftValue::factory()->forDraft($draft)->create([
            'form_field_id' => $field->id,
            'value' => null,
            'source' => FormDraftValueSource::Missing->value,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->moveToReadyForReview($draft);

        $this->assertSame(FormDraftStatus::NeedsData, $draft->fresh()->status);
    }

    public function test_move_to_ready_for_review_succeeds_when_complete(): void
    {
        $draft = FormDraft::factory()->create(['status' => FormDraftStatus::Draft->value]);

        $updated = $this->service->moveToReadyForReview($draft);

        $this->assertSame(FormDraftStatus::ReadyForReview, $updated->status);
        $this->assertDatabaseHas('form_review_events', ['form_draft_id' => $draft->id, 'event_type' => 'marked_ready_for_review']);
    }

    public function test_reject_requires_approval_role(): void
    {
        $draft = $this->completeDraft();
        $draft->update(['status' => FormDraftStatus::ReadyForReview->value]);
        $this->service->submitForAttorneyReview($draft, FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $draft->firm_id]));

        $nonApprover = FirmUser::factory()->role(FirmUserRole::Paralegal)->create(['firm_id' => $draft->firm_id]);

        $this->expectException(\RuntimeException::class);
        $this->service->reject($draft->fresh(), $nonApprover, 'not permitted');
    }

    public function test_full_happy_path_reaches_approved(): void
    {
        $draft = $this->completeDraft();
        $attorney = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $draft->firm_id]);

        $draft = $this->service->moveToReadyForReview($draft);
        $draft = $this->service->submitForAttorneyReview($draft, $attorney);
        $draft = $this->service->approve($draft, $attorney);

        $this->assertSame(FormDraftStatus::Approved, $draft->status);
        $this->assertNotNull($draft->approved_at);
    }

    public function test_request_revision_then_resubmit_cycle(): void
    {
        $draft = $this->completeDraft();
        $attorney = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $draft->firm_id]);

        $draft = $this->service->moveToReadyForReview($draft);
        $draft = $this->service->submitForAttorneyReview($draft, $attorney);
        $draft = $this->service->requestRevision($draft, $attorney, 'fix the DOB');
        $this->assertSame(FormDraftStatus::Revised, $draft->status);

        $draft = $this->service->resubmitAfterRevision($draft, $attorney);
        $this->assertSame(FormDraftStatus::ReadyForReview, $draft->status);
    }

    public function test_archive_only_allowed_from_terminal_states(): void
    {
        $draft = FormDraft::factory()->create(['status' => FormDraftStatus::Draft->value]);
        $actor = FirmUser::factory()->create(['firm_id' => $draft->firm_id]);

        $this->expectException(\RuntimeException::class);
        $this->service->archive($draft, $actor);
    }
}
