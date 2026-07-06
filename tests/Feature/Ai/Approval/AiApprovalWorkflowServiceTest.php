<?php

namespace Tests\Feature\Ai\Approval;

use App\Enums\AiApprovalCategory;
use App\Enums\AiApprovalRequestStatus;
use App\Enums\AiMode;
use App\Enums\AiProvider;
use App\Enums\AiUsageActionType;
use App\Models\User;
use App\Services\AiApprovalWorkflowService;
use App\Services\AiUsageRecorderService;
use App\ValueObjects\AiPromptRequest;
use App\ValueObjects\AiProviderResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\Concerns\SetsUpAiEntitledFirm;
use Tests\TestCase;

/**
 * Project rules 15/19/20/21: high-risk outputs require approval before
 * use; client-facing AI output requires approval before sending; AI
 * outputs must be labeled AI-generated draft. Approved decision #4:
 * encrypted content snapshot stored regardless of full_content_logging
 * policy; approve/reject restricted to FirmOwner/Attorney.
 */
class AiApprovalWorkflowServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpAiEntitledFirm;

    private function highRiskRequest(AiUsageActionType $type): AiPromptRequest
    {
        return new AiPromptRequest(
            provider: AiProvider::OpenAi,
            model: 'fake-model-1',
            actionType: $type,
            instructionText: 'Draft a demand letter for a breach of contract matter.',
            documentDerivedText: null,
            matterIds: [],
        );
    }

    #[DataProvider('highRiskCategoryProvider')]
    public function test_high_risk_categories_automatically_create_an_approval_request(AiUsageActionType $type): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();

        $event = app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            $this->highRiskRequest($type),
            new AiProviderResponse(outputText: 'Draft content here.', tokensIn: 20, tokensOut: 30),
        );

        $this->assertTrue($event->approval_required);
        $this->assertDatabaseHas('ai_approval_requests', [
            'ai_usage_event_id' => $event->id,
            'status' => AiApprovalRequestStatus::Pending->value,
        ]);
    }

    public static function highRiskCategoryProvider(): array
    {
        return [
            'legal research memo' => [AiUsageActionType::LegalResearchMemo],
            'legal citation' => [AiUsageActionType::LegalCitation],
            'demand letter' => [AiUsageActionType::DemandLetter],
            'court filing draft' => [AiUsageActionType::CourtFilingDraft],
            'client legal advice' => [AiUsageActionType::ClientLegalAdvice],
            'client facing content' => [AiUsageActionType::ClientFacingContent],
        ];
    }

    public function test_client_facing_content_requires_approval(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();

        $event = app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            $this->highRiskRequest(AiUsageActionType::ClientFacingContent),
            new AiProviderResponse(outputText: 'Client-facing draft.', tokensIn: 20, tokensOut: 30),
        );

        $this->assertTrue($event->approval_required);
        $this->assertDatabaseHas('ai_approval_requests', ['ai_usage_event_id' => $event->id, 'category' => AiApprovalCategory::ClientFacingContent->value]);
    }

    public function test_non_high_risk_action_does_not_create_an_approval_request(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();

        $event = app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            new AiPromptRequest(AiProvider::OpenAi, 'fake-model-1', AiUsageActionType::Summarization, 'Summarize this.', null, []),
            new AiProviderResponse(outputText: 'Summary.', tokensIn: 5, tokensOut: 5),
        );

        $this->assertFalse($event->approval_required);
        $this->assertDatabaseCount('ai_approval_requests', 0);
    }

    public function test_ai_generated_draft_label_is_always_set(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();

        app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            $this->highRiskRequest(AiUsageActionType::DemandLetter),
            new AiProviderResponse(outputText: 'Demand letter draft.', tokensIn: 20, tokensOut: 30),
        );

        $request = \App\Models\AiApprovalRequest::query()->where('firm_id', $firm->id)->firstOrFail();

        $this->assertSame('ai_generated_draft', $request->draft_label);
    }

    public function test_full_content_logging_is_off_by_default(): void
    {
        $firm = $this->makeAiEntitledFirm();

        $this->assertFalse($firm->aiSettings->full_content_logging_enabled);
    }

    public function test_approval_encrypted_snapshot_is_stored_even_when_full_content_logging_is_disabled(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $this->assertFalse($firm->aiSettings->full_content_logging_enabled);

        $user = User::factory()->create();

        app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            $this->highRiskRequest(AiUsageActionType::LegalCitation),
            new AiProviderResponse(outputText: 'Citation draft content.', tokensIn: 20, tokensOut: 30),
        );

        $request = \App\Models\AiApprovalRequest::query()->where('firm_id', $firm->id)->firstOrFail();

        $this->assertNotEmpty($request->encrypted_snapshot_ciphertext);

        $decrypted = app(AiApprovalWorkflowService::class)->decryptSnapshot($firm, $request);
        $this->assertSame('Citation draft content.', $decrypted);
    }

    public function test_encrypted_snapshot_is_hidden_from_serialization(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();

        app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            $this->highRiskRequest(AiUsageActionType::LegalResearchMemo),
            new AiProviderResponse(outputText: 'Memo draft.', tokensIn: 20, tokensOut: 30),
        );

        $request = \App\Models\AiApprovalRequest::query()->where('firm_id', $firm->id)->firstOrFail();

        $this->assertArrayNotHasKey('encrypted_snapshot_ciphertext', $request->toArray());
        $this->assertStringNotContainsString('encrypted_snapshot_ciphertext', $request->toJson());
    }

    public function test_approve_is_restricted_to_firm_owner_and_attorney(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();
        $paralegal = $this->makeParalegal($firm);

        app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            $this->highRiskRequest(AiUsageActionType::DemandLetter),
            new AiProviderResponse(outputText: 'Demand letter draft.', tokensIn: 20, tokensOut: 30),
        );

        $request = \App\Models\AiApprovalRequest::query()->where('firm_id', $firm->id)->firstOrFail();

        $this->expectException(\RuntimeException::class);
        app(AiApprovalWorkflowService::class)->approve($request, $paralegal);
    }

    public function test_attorney_can_approve_a_high_risk_request(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();
        $attorney = $this->makeAttorney($firm);

        app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            $this->highRiskRequest(AiUsageActionType::DemandLetter),
            new AiProviderResponse(outputText: 'Demand letter draft.', tokensIn: 20, tokensOut: 30),
        );

        $request = \App\Models\AiApprovalRequest::query()->where('firm_id', $firm->id)->firstOrFail();

        $approved = app(AiApprovalWorkflowService::class)->approve($request, $attorney);

        $this->assertSame(AiApprovalRequestStatus::Approved, $approved->status);
        $this->assertDatabaseHas('ai_approval_events', [
            'ai_approval_request_id' => $request->id,
            'event_type' => \App\Enums\AiApprovalEventType::Approved->value,
        ]);
    }

    public function test_firm_owner_can_reject_a_high_risk_request(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();
        $owner = $this->makeFirmOwner($firm);

        app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            $this->highRiskRequest(AiUsageActionType::ClientLegalAdvice),
            new AiProviderResponse(outputText: 'Advice draft.', tokensIn: 20, tokensOut: 30),
        );

        $request = \App\Models\AiApprovalRequest::query()->where('firm_id', $firm->id)->firstOrFail();

        $rejected = app(AiApprovalWorkflowService::class)->reject($request, $owner, 'needs revision');

        $this->assertSame(AiApprovalRequestStatus::Rejected, $rejected->status);
    }

    public function test_cannot_resolve_an_already_resolved_request(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();
        $attorney = $this->makeAttorney($firm);

        app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            $this->highRiskRequest(AiUsageActionType::DemandLetter),
            new AiProviderResponse(outputText: 'Demand letter draft.', tokensIn: 20, tokensOut: 30),
        );

        $request = \App\Models\AiApprovalRequest::query()->where('firm_id', $firm->id)->firstOrFail();
        app(AiApprovalWorkflowService::class)->approve($request, $attorney);

        $this->expectException(\RuntimeException::class);
        app(AiApprovalWorkflowService::class)->approve($request->fresh(), $attorney);
    }
}
