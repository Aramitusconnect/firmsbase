<?php

namespace Tests\Feature\Forms\DocumentGeneration;

use App\Enums\DocumentTemplateCategory;
use App\Enums\DocumentTemplateContentStatus;
use App\Enums\DocumentTemplateVersionStatus;
use App\Enums\FirmUserRole;
use App\Enums\GeneratedDocumentStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Services\DeterministicFieldResolutionService;
use App\Services\DocumentGenerationService;
use App\Services\DocumentReviewService;
use App\Services\DocumentTemplateService;
use App\Services\FormAndDocumentAccessPolicyService;
use App\Services\ReviewWorkflowTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Required test (final correction): a GeneratedDocument created from a
 * SampleOnly document_template_version cannot be approved. used_sample_
 * content must be true at generation and stay true until the template
 * version is upgraded to ReviewedApproved via
 * DocumentTemplateService::approveContent() by the correct actor, at
 * which point approval succeeds and used_sample_content flips to false.
 * The check is re-derived LIVE at approval time, not trusted from the
 * generation-time snapshot.
 */
class GeneratedDocumentApprovalRequiresReviewedApprovedTemplateTest extends TestCase
{
    use RefreshDatabase;

    private DocumentTemplateService $templateService;
    private DocumentGenerationService $generationService;
    private DocumentReviewService $reviewService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->templateService = new DocumentTemplateService();
        $this->generationService = new DocumentGenerationService(new DeterministicFieldResolutionService());
        $this->reviewService = new DocumentReviewService(
            new ReviewWorkflowTransitionService(),
            new FormAndDocumentAccessPolicyService(app(\App\Services\EntitlementService::class)),
        );
    }

    public function test_document_generated_from_sample_only_template_cannot_be_approved_until_template_is_reviewed(): void
    {
        $firm = Firm::factory()->create();
        $attorney = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $admin = PlatformAdmin::factory()->create();

        $template = $this->templateService->createGlobal('n400_cover_sample', 'N-400 Cover Letter', DocumentTemplateCategory::CoverLetter, $admin);
        $version = $this->templateService->activate(
            $this->templateService->createVersion($template, 'v1', [], 'Dear {{client_name}},')
        );
        $this->assertSame(DocumentTemplateContentStatus::SampleOnly, $version->content_status);

        $result = $this->generationService->generate($version, $attorney, $firm->id);
        $this->assertTrue($result->usedSampleContent);

        $document = \App\Models\GeneratedDocument::find($result->generatedDocumentId)->fresh();
        $document = $this->reviewService->moveToReadyForReview($document);
        $document = $this->reviewService->submitForAttorneyReview($document, $attorney);

        try {
            $this->reviewService->approve($document, $attorney);
            $this->fail('Expected approval to be blocked while document_template_version content_status is SampleOnly.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SampleOnly', $e->getMessage());
        }

        $this->assertSame(GeneratedDocumentStatus::AttorneyReview, $document->fresh()->status);
        $this->assertTrue($document->fresh()->used_sample_content);

        // PlatformAdmin approves the template content -> LIVE re-check now passes.
        $this->templateService->approveContent($version, $admin);

        $approved = $this->reviewService->approve($document->fresh(), $attorney);

        $this->assertSame(GeneratedDocumentStatus::Approved, $approved->status);
        $this->assertFalse($approved->used_sample_content);
    }

    public function test_document_generated_from_already_reviewed_approved_template_can_be_approved_immediately(): void
    {
        $firm = Firm::factory()->create();
        $attorney = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $admin = PlatformAdmin::factory()->create();

        $template = $this->templateService->createGlobal('i130_cover_reviewed', 'I-130 Cover Letter', DocumentTemplateCategory::CoverLetter, $admin);
        $version = $this->templateService->activate(
            $this->templateService->createVersion($template, 'v1', [], 'Dear {{client_name}},')
        );
        $this->templateService->approveContent($version, $admin);

        $result = $this->generationService->generate($version->fresh(), $attorney, $firm->id);
        $this->assertFalse($result->usedSampleContent);

        $document = \App\Models\GeneratedDocument::find($result->generatedDocumentId)->fresh();
        $document = $this->reviewService->moveToReadyForReview($document);
        $document = $this->reviewService->submitForAttorneyReview($document, $attorney);
        $approved = $this->reviewService->approve($document, $attorney);

        $this->assertSame(GeneratedDocumentStatus::Approved, $approved->status);
        $this->assertFalse($approved->used_sample_content);
    }
}
