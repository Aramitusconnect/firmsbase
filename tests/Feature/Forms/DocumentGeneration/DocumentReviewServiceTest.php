<?php

namespace Tests\Feature\Forms\DocumentGeneration;

use App\Enums\DocumentTemplateContentStatus;
use App\Enums\FirmUserRole;
use App\Enums\GeneratedDocumentStatus;
use App\Models\DocumentTemplateVersion;
use App\Models\FirmUser;
use App\Models\GeneratedDocument;
use App\Services\DocumentReviewService;
use App\Services\FormAndDocumentAccessPolicyService;
use App\Services\ReviewWorkflowTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentReviewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DocumentReviewService(
            new ReviewWorkflowTransitionService(),
            new FormAndDocumentAccessPolicyService(app(\App\Services\EntitlementService::class)),
        );
    }

    public function test_reject_requires_approval_role(): void
    {
        $version = DocumentTemplateVersion::factory()->reviewedApproved()->create();
        $document = GeneratedDocument::factory()->withTemplateVersion($version)->create(['status' => GeneratedDocumentStatus::Draft->value]);
        $attorney = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $document->firm_id]);
        $document = $this->service->moveToReadyForReview($document);
        $document = $this->service->submitForAttorneyReview($document, $attorney);

        $nonApprover = FirmUser::factory()->role(FirmUserRole::Paralegal)->create(['firm_id' => $document->firm_id]);

        $this->expectException(\RuntimeException::class);
        $this->service->reject($document->fresh(), $nonApprover, 'not permitted');
    }

    public function test_happy_path_reaches_approved_when_template_is_reviewed_approved(): void
    {
        $version = DocumentTemplateVersion::factory()->reviewedApproved()->create();
        $document = GeneratedDocument::factory()->withTemplateVersion($version)->create(['status' => GeneratedDocumentStatus::Draft->value]);
        $attorney = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $document->firm_id]);

        $document = $this->service->moveToReadyForReview($document);
        $document = $this->service->submitForAttorneyReview($document, $attorney);
        $document = $this->service->approve($document, $attorney);

        $this->assertSame(GeneratedDocumentStatus::Approved, $document->status);
    }

    /**
     * Regression test for the same nested-wrap fix
     * FormReviewServiceTest::test_resubmit_after_revision_needs_data_throw_persists_status_despite_propagating_exception()
     * exercises on FormReviewService — resubmitAfterRevision() here
     * deliberately does NOT wrap its own call into
     * moveToReadyForReview() either (which already self-wraps), for the
     * identical reason stated in both methods' docblocks.
     *
     * IMPORTANT DIFFERENCE FROM FormReviewService, confirmed by direct
     * reading of DocumentReviewService (this class has no
     * FormMissingDataDetectionService dependency at all —
     * see the constructor above): DocumentReviewService::
     * moveToReadyForReview() has NO needs_data branch whatsoever — it
     * only calls assertTransitionAllowed() then updates directly to
     * ReadyForReview. There is therefore no "write, then throw" path
     * inside this method to regression-test the nested-wrap bug
     * against — GeneratedDocumentStatus::NeedsData is only ever reached
     * via the separate, directly-invoked markNeedsData() method, which
     * resubmitAfterRevision() never calls. This test instead proves the
     * happy-path delegation genuinely persists correctly end-to-end
     * (status transitions all the way to ReadyForReview, the paired
     * generated_document_events row is recorded, and the database
     * session's tenant context clears afterward) — the closest
     * equivalent regression coverage this service's actual code shape
     * permits.
     */
    public function test_resubmit_after_revision_persists_ready_for_review_status_and_paired_event(): void
    {
        $version = DocumentTemplateVersion::factory()->reviewedApproved()->create();
        $document = GeneratedDocument::factory()->withTemplateVersion($version)->create(['status' => GeneratedDocumentStatus::Draft->value]);
        $attorney = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $document->firm_id]);

        $document = $this->service->moveToReadyForReview($document);
        $document = $this->service->submitForAttorneyReview($document, $attorney);
        $document = $this->service->requestRevision($document, $attorney, 'please revise the merge fields');
        $this->assertSame(GeneratedDocumentStatus::Revised, $document->status);

        // Every factory call above uses the context-hold create()
        // override, which deliberately LEAVES the database session's
        // app.current_firm_id set after each create() call — pre-existing
        // setup noise, not something resubmitAfterRevision() itself
        // leaks. Clearing it here isolates the context-lifecycle
        // assertion below to what THIS service call actually does.
        (new \App\Services\TenantContextService())->clearDatabaseTenantContext();

        $document = $this->service->resubmitAfterRevision($document, $attorney);

        $this->assertSame(GeneratedDocumentStatus::ReadyForReview, $document->status);

        // Both resubmitAfterRevision() and moveToReadyForReview() clear
        // their own runWithFirmContext() wrap's context on the way out,
        // so this verification read must establish its own context —
        // generated_documents is FORCE RLS'd and GeneratedDocument uses
        // BelongsToTenant, so a contextless read would otherwise fail
        // closed rather than merely read stale data.
        $freshFromDatabase = $this->runWithFirmContext($document->firm_id, fn () => GeneratedDocument::query()->find($document->id));

        $this->assertNotNull($freshFromDatabase, 'The document must still exist and be readable under its own firm\'s context.');
        $this->assertSame(
            GeneratedDocumentStatus::ReadyForReview,
            $freshFromDatabase->status,
            'The status update inside moveToReadyForReview()\'s own runWithFirmContext() wrap must be genuinely persisted, queried fresh from the database.'
        );

        // generated_document_events is also FORCE RLS'd; assertDatabaseHas()
        // issues a context-free raw query, which would otherwise see zero
        // rows regardless of what recordEvent() actually wrote (mirrors
        // AiToolActionsForceRlsActivationTest's own documented fix for the
        // identical class of assertion).
        $this->runWithFirmContext($document->firm_id, function () use ($document) {
            $this->assertDatabaseHas('generated_document_events', [
                'generated_document_id' => $document->id,
                'event_type' => 'resubmitted_after_revision',
            ]);
        });

        $this->assertNoDatabaseTenantContext();
    }
}
