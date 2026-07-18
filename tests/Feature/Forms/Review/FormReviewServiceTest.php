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
use App\Services\TenantContextService;
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

    /**
     * CRITICAL regression test — the nested-wrap fix (Section 39A-6
     * Wave 6). resubmitAfterRevision() deliberately does NOT wrap its
     * own call into moveToReadyForReview() (which already self-wraps
     * via runWithFirmContext()) — see both methods' own docblocks. This
     * is the ONLY way moveToReadyForReview()'s own needs_data branch can
     * be reached from resubmitAfterRevision() at all, since the
     * transition graph only permits 'draft' -> 'needs_data' (never
     * 'revised' -> 'needs_data') — so this test deliberately starts the
     * draft in 'draft' status to exercise that exact branch through
     * resubmitAfterRevision()'s own delegation, not to claim this is the
     * typical real-world call sequence for "resubmit after revision".
     *
     * If resubmitAfterRevision() wrapped its call to
     * moveToReadyForReview() in a SECOND, outer runWithFirmContext(),
     * the inner wrap's update to NeedsData would only commit to a
     * nested savepoint, which would then be rolled back when the
     * propagating RuntimeException reached the outer wrap's own
     * DB::transaction() boundary — silently losing the NeedsData write
     * even though the exception message would still look correct. This
     * test proves, against a REAL database (not a code trace), that the
     * write survives: the exception propagates out of
     * resubmitAfterRevision() (exactly as the two independent code
     * reviews concluded), AND the draft's status was actually persisted
     * as NeedsData — queried FRESH from the database, not read from the
     * in-memory $draft object, which could otherwise mask a silent
     * rollback.
     */
    public function test_resubmit_after_revision_needs_data_throw_persists_status_despite_propagating_exception(): void
    {
        $draft = FormDraft::factory()->create(['status' => FormDraftStatus::Draft->value]);
        $field = FormField::factory()->required()->forVersion($draft->formTemplateVersion)->create();
        FormDraftValue::factory()->forDraft($draft)->create([
            'form_field_id' => $field->id,
            'value' => null,
            'source' => FormDraftValueSource::Missing->value,
        ]);
        $actor = FirmUser::factory()->create(['firm_id' => $draft->firm_id]);

        // Every factory call above (FormDraft/FormField/FormDraftValue/
        // FirmUser) uses the context-hold create() override, which
        // deliberately LEAVES the database session's app.current_firm_id
        // set after each create() call (see MatterExpenseFactory's own
        // docblock for the full rationale) — that is pre-existing setup
        // noise, not something resubmitAfterRevision() itself leaked.
        // Clearing it here isolates the assertion below to what THIS
        // service call actually does to context, matching every other
        // FORCE-RLS-era test's own "clear before the operation under
        // test" convention.
        (new TenantContextService())->clearDatabaseTenantContext();

        try {
            $this->service->resubmitAfterRevision($draft, $actor);
            $this->fail('Expected a RuntimeException to propagate out of resubmitAfterRevision() via the needs_data branch.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('required data is still missing', $e->getMessage());
        }

        // Both resubmitAfterRevision() and moveToReadyForReview() clear
        // their own runWithFirmContext() wrap's context in a finally
        // block on the way out, so this verification read must
        // establish its own context — form_drafts is FORCE RLS'd and
        // FormDraft uses BelongsToTenant, so a contextless read here
        // would otherwise fail closed (0 rows / null), not merely read
        // stale data.
        $freshFromDatabase = $this->runWithFirmContext($draft->firm_id, fn () => FormDraft::query()->find($draft->id));

        $this->assertNotNull($freshFromDatabase, 'The draft must still exist and be readable under its own firm\'s context.');
        $this->assertSame(
            FormDraftStatus::NeedsData,
            $freshFromDatabase->status,
            'The NeedsData status update inside moveToReadyForReview()\'s own runWithFirmContext() wrap must survive even though resubmitAfterRevision() itself deliberately does not wrap that call — proving the nested-wrap bug fix actually works against a real database, not merely by code trace.'
        );

        $this->assertNoDatabaseTenantContext();

        // No form_review_events row (e.g. resubmitted_after_revision)
        // should have been recorded — resubmitAfterRevision()'s own
        // recordEvent() call is reached only after moveToReadyForReview()
        // returns successfully, which never happened here. Checked under
        // the draft's own firm context (form_review_events is FORCE
        // RLS'd) so this is a genuine "zero rows" proof, not merely an
        // artifact of a context-free query being unable to see any rows
        // at all regardless of what actually exists.
        $this->runWithFirmContext($draft->firm_id, function () use ($draft) {
            $this->assertDatabaseMissing('form_review_events', [
                'form_draft_id' => $draft->id,
                'event_type' => 'resubmitted_after_revision',
            ]);
        });
    }
}
