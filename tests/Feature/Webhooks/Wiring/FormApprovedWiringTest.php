<?php

namespace Tests\Feature\Webhooks\Wiring;

use App\Enums\FirmUserRole;
use App\Enums\FormDraftStatus;
use App\Enums\WebhookEventType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\FormDraft;
use App\Services\FormAndDocumentAccessPolicyService;
use App\Services\FormMissingDataDetectionService;
use App\Services\FormReviewChecklistService;
use App\Services\FormReviewService;
use App\Services\ReviewWorkflowTransitionService;
use App\Services\WebhookEventRecorderService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Feature\Webhooks\Concerns\SetsUpWebhookEntitledFirm;
use Tests\TestCase;

/**
 * form.approved is wired at the single real owner (Phase 14b decision
 * I): FormReviewService::approve(), confirmed via grep to be the only
 * place FormDraftStatus::Approved is ever set. The pre-approval setup
 * below mirrors the proven happy-path sequence from the existing
 * FormReviewServiceTest (moveToReadyForReview -> submitForAttorneyReview
 * -> approve), rather than fabricating a shortcut status, so this test
 * exercises the real transition graph.
 */
class FormApprovedWiringTest extends TestCase
{
    use DatabaseMigrations, SetsUpWebhookEntitledFirm;

    private FormReviewChecklistService $checklistService;
    private FormReviewService $service;

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

    private function completeDraft(Firm $firm): FormDraft
    {
        $draft = FormDraft::factory()->create(['firm_id' => $firm->id, 'status' => FormDraftStatus::Draft->value]);
        // No required fields on the version -> missing-data scan is vacuously complete.
        $this->checklistService->seedDefaults($draft);
        foreach ($draft->fresh()->checklistItems as $item) {
            $this->checklistService->check($item, FirmUser::factory()->create(['firm_id' => $draft->firm_id]));
        }

        return $draft->fresh();
    }

    private function submittedForAttorneyReview(Firm $firm): array
    {
        $draft = $this->completeDraft($firm);
        $attorney = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);

        $draft = $this->service->moveToReadyForReview($draft);
        $draft = $this->service->submitForAttorneyReview($draft, $attorney);

        return [$draft, $attorney];
    }

    public function test_form_approved_fires_exactly_once_on_successful_approval(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        [$draft, $attorney] = $this->submittedForAttorneyReview($firm);

        $approved = $this->service->approve($draft, $attorney);

        $this->assertSame(FormDraftStatus::Approved, $approved->status);
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', [
            'event_type' => WebhookEventType::FormApproved->value,
            'subject_type' => FormDraft::class,
            'subject_id' => $draft->id,
        ]);
    }

    public function test_form_approved_does_not_fire_on_reject(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        [$draft, $attorney] = $this->submittedForAttorneyReview($firm);

        $this->service->reject($draft, $attorney, 'needs more evidence');

        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertSame(FormDraftStatus::Rejected, $draft->fresh()->status);
    }

    public function test_form_approved_does_not_fire_when_checklist_is_incomplete(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $draft = FormDraft::factory()->create(['firm_id' => $firm->id, 'status' => FormDraftStatus::Draft->value]);
        $this->checklistService->seedDefaults($draft);
        // Deliberately left unchecked.
        $attorney = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);

        $draft = $this->service->moveToReadyForReview($draft->fresh());
        $draft = $this->service->submitForAttorneyReview($draft, $attorney);

        try {
            $this->service->approve($draft->fresh(), $attorney);
            $this->fail('Expected a RuntimeException for an incomplete checklist.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_recorder_exception_does_not_break_form_approval(): void
    {
        $this->mock(WebhookEventRecorderService::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('simulated recorder failure'));
        });

        $firm = $this->makeWebhookEntitledFirm();
        [$draft, $attorney] = $this->submittedForAttorneyReview($firm);

        $this->service->approve($draft, $attorney);

        $this->assertSame(FormDraftStatus::Approved, $draft->fresh()->status);
    }
}
