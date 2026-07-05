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
}
