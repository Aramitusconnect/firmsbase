<?php

namespace Tests\Feature\Forms\Review;

use App\Enums\FirmUserRole;
use App\Enums\FormDraftStatus;
use App\Enums\FormFieldType;
use App\Enums\FormMappingSourceEntity;
use App\Enums\FormMappingTransform;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\PlatformAdmin;
use App\Services\DeterministicFieldResolutionService;
use App\Services\FormAndDocumentAccessPolicyService;
use App\Services\FormDraftGenerationService;
use App\Services\FormFieldService;
use App\Services\FormMappingRuleService;
use App\Services\FormMissingDataDetectionService;
use App\Services\FormReviewChecklistService;
use App\Services\FormReviewService;
use App\Services\ReviewWorkflowTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Required test (v2 correction): a draft generated using a mapping rule
 * that is still FormMappingContentStatus::SampleOnly can be submitted,
 * moved through attorney_review, revised, rejected, or archived — but
 * can NEVER reach Approved — until every mapping rule it used is
 * upgraded to ReviewedApproved via FormMappingRuleService::approveContent()
 * by a PlatformAdmin. The check is re-derived LIVE at approval time from
 * form_draft_values.form_mapping_rule_id, not trusted from a stale
 * generation-time snapshot.
 */
class FormDraftApprovalRequiresReviewedApprovedMappingTest extends TestCase
{
    use RefreshDatabase;

    private FormDraftGenerationService $generationService;
    private FormReviewService $reviewService;
    private FormReviewChecklistService $checklistService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generationService = new FormDraftGenerationService(
            new DeterministicFieldResolutionService(),
            new FormMissingDataDetectionService(),
        );

        $this->checklistService = new FormReviewChecklistService();

        $this->reviewService = new FormReviewService(
            new ReviewWorkflowTransitionService(),
            new FormMissingDataDetectionService(),
            $this->checklistService,
            new FormAndDocumentAccessPolicyService(app(\App\Services\EntitlementService::class)),
        );
    }

    public function test_draft_generated_from_sample_only_mapping_cannot_be_approved_until_mapping_is_reviewed(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create(['display_name' => 'Carlos Ruiz']);
        $matter = Matter::factory()->forFirm($firm)->create(['client_id' => $client->id]);
        $attorney = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $admin = PlatformAdmin::factory()->create();

        $version = \App\Models\FormTemplateVersion::factory()->create();
        $field = (new FormFieldService())->createField($version, 'client_name', 'Client Name', FormFieldType::Text, true, 1);
        $rule = (new FormMappingRuleService())->createRule(
            $version, $field, FormMappingSourceEntity::Client, 'client.display_name', FormMappingTransform::None, $admin
        );

        $result = $this->generationService->generate($matter, $version, $attorney, $client);
        $draft = \App\Models\FormDraft::find($result->formDraftId)->fresh();
        $this->assertTrue($draft->used_sample_mapping);

        $this->checklistService->seedDefaults($draft);
        foreach ($draft->fresh()->checklistItems as $item) {
            $this->checklistService->check($item, $attorney);
        }

        $draft = $this->reviewService->moveToReadyForReview($draft->fresh());
        $draft = $this->reviewService->submitForAttorneyReview($draft, $attorney);

        // Cannot approve while the mapping rule used is still SampleOnly.
        try {
            $this->reviewService->approve($draft, $attorney);
            $this->fail('Expected approval to be blocked while mapping rule content_status is SampleOnly.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SampleOnly', $e->getMessage());
        }

        $this->assertSame(FormDraftStatus::AttorneyReview, $draft->fresh()->status);

        // Non-approval transitions remain available while sample-only.
        $revised = $this->reviewService->requestRevision($draft->fresh(), $attorney, 'awaiting reviewed mapping');
        $this->assertSame(FormDraftStatus::Revised, $revised->status);
        $draft = $this->reviewService->resubmitAfterRevision($revised, $attorney);
        $draft = $this->reviewService->submitForAttorneyReview($draft, $attorney);

        // PlatformAdmin approves the mapping rule content -> LIVE re-check now passes.
        (new FormMappingRuleService())->approveContent($rule, $admin);

        $approved = $this->reviewService->approve($draft->fresh(), $attorney);

        $this->assertSame(FormDraftStatus::Approved, $approved->status);
        $this->assertFalse($approved->used_sample_mapping);
    }

    public function test_rejection_and_archival_remain_available_while_mapping_is_sample_only(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $attorney = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);
        $admin = PlatformAdmin::factory()->create();

        $version = \App\Models\FormTemplateVersion::factory()->create();
        $field = (new FormFieldService())->createField($version, 'matter_status', 'Matter Status', FormFieldType::Text, false, 1);
        (new FormMappingRuleService())->createRule(
            $version, $field, FormMappingSourceEntity::Matter, 'matter.status', FormMappingTransform::None, $admin
        );

        $result = $this->generationService->generate($matter, $version, $attorney);
        $draft = \App\Models\FormDraft::find($result->formDraftId)->fresh();

        $draft = $this->reviewService->moveToReadyForReview($draft);
        $draft = $this->reviewService->submitForAttorneyReview($draft, $attorney);
        $rejected = $this->reviewService->reject($draft, $attorney, 'client changed mind');

        $this->assertSame(FormDraftStatus::Rejected, $rejected->status);

        $archived = $this->reviewService->archive($rejected, $attorney);
        $this->assertSame(FormDraftStatus::Archived, $archived->status);
    }
}
