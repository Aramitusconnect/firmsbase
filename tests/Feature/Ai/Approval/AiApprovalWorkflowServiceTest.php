<?php

namespace Tests\Feature\Ai\Approval;

use App\Enums\AiApprovalCategory;
use App\Enums\AiApprovalEventType;
use App\Enums\AiApprovalRequestStatus;
use App\Enums\AiProvider;
use App\Enums\AiUsageActionType;
use App\Models\AiApprovalEvent;
use App\Models\AiApprovalRequest;
use App\Models\Firm;
use App\Models\User;
use App\Services\AiApprovalWorkflowService;
use App\Services\AiUsageRecorderService;
use App\Services\TenantContextService;
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
            model: 'gpt-5.6-terra',
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
            new AiPromptRequest(AiProvider::OpenAi, 'gpt-5.6-terra', AiUsageActionType::Summarization, 'Summarize this.', null, []),
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

        $request = AiApprovalRequest::query()->where('firm_id', $firm->id)->firstOrFail();

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

        $request = AiApprovalRequest::query()->where('firm_id', $firm->id)->firstOrFail();

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

        $request = AiApprovalRequest::query()->where('firm_id', $firm->id)->firstOrFail();

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

        $request = AiApprovalRequest::query()->where('firm_id', $firm->id)->firstOrFail();

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

        $request = AiApprovalRequest::query()->where('firm_id', $firm->id)->firstOrFail();

        $approved = app(AiApprovalWorkflowService::class)->approve($request, $attorney);

        $this->assertSame(AiApprovalRequestStatus::Approved, $approved->status);
        $this->assertDatabaseHas('ai_approval_events', [
            'ai_approval_request_id' => $request->id,
            'event_type' => AiApprovalEventType::Approved->value,
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

        $request = AiApprovalRequest::query()->where('firm_id', $firm->id)->firstOrFail();

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

        $request = AiApprovalRequest::query()->where('firm_id', $firm->id)->firstOrFail();
        app(AiApprovalWorkflowService::class)->approve($request, $attorney);

        $this->expectException(\RuntimeException::class);
        app(AiApprovalWorkflowService::class)->approve($request->fresh(), $attorney);
    }

    // ---------------------------------------------------------------
    // ai_approval_requests/ai_approval_events FORCE ROW LEVEL SECURITY
    // activation regressions (database/migrations/2026_08_27_950016_..._
    // ai_approval_requests_table.php and .../950017_..._ai_approval_events_
    // table.php). approve()/reject() now check the resolving actor's own
    // firm_id against $request->firm_id BEFORE any tenant context is
    // established or any DB statement runs, then wrap the update()+
    // create() pair together in a single runWithFirmContext() call keyed
    // on $request->firm_id. submit() (exercised via
    // AiUsageRecorderService::record() in every test above this section)
    // is deliberately untouched — see AiApprovalWorkflowService's own
    // docblock for why its existing outer wrap in record() is already
    // sufficient. Every test above this section continues to pass
    // completely unmodified, which is itself part of the proof that the
    // untouched submit()/record() wrap is genuinely sufficient.
    // ---------------------------------------------------------------

    /**
     * Fetches an ai_approval_requests row explicitly under its own
     * firm's context. ai_approval_requests now has FORCE ROW LEVEL
     * SECURITY (this batch's own activation) — an unguarded bare model
     * query is only ever visible while the matching
     * app.current_firm_id session context happens to be active, and
     * several of this trait's own fixtures (FirmSettingsFactory,
     * FirmUserFactory, etc.) deliberately leave a database-only
     * leftover context active afterward (their own established
     * "context-hold" convention) that does NOT necessarily match the
     * firm under test once more than one firm's fixtures have been
     * built. Fetching explicitly here — rather than relying on
     * whatever the ambient leftover happens to be — keeps every test
     * below correct regardless of fixture ordering.
     */
    private function findApprovalRequestForFirm(Firm $firm): AiApprovalRequest
    {
        return $this->runWithFirmContext(
            $firm,
            fn () => AiApprovalRequest::query()->where('firm_id', $firm->id)->firstOrFail(),
        );
    }

    /**
     * Reads back an ai_approval_events row under its owning firm's
     * context — same "ai_approval_events now has FORCE RLS, so an
     * unguarded assertDatabaseHas()/assertDatabaseMissing() call is
     * itself subject to fail-closed RLS visibility" reasoning as
     * findApprovalRequestForFirm() above.
     */
    private function approvalEventExistsForFirm(Firm $firm, int $requestId, AiApprovalEventType $eventType): bool
    {
        return (bool) $this->runWithFirmContext(
            $firm,
            fn () => AiApprovalEvent::withoutGlobalScopes()
                ->where('ai_approval_request_id', $requestId)
                ->where('event_type', $eventType->value)
                ->exists(),
        );
    }

    /**
     * (a) approve() succeeds end-to-end with a matching-firm actor and
     * zero ambient context established beforehand.
     */
    public function test_approve_succeeds_end_to_end_with_matching_firm_actor_and_zero_ambient_context(): void
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

        $request = $this->findApprovalRequestForFirm($firm);

        // Explicitly clear any ambient tenant context before calling
        // approve(), so this test proves approve() establishes its own
        // context correctly rather than merely riding on a leftover
        // context from the fixtures above.
        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $approved = app(AiApprovalWorkflowService::class)->approve($request, $attorney);

        $this->assertSame(AiApprovalRequestStatus::Approved, $approved->status);
        $this->assertNotNull($approved->resolved_at);
        $this->assertTrue(
            $this->approvalEventExistsForFirm($firm, $request->id, AiApprovalEventType::Approved),
            'approve() must persist an "approved" ai_approval_events row.'
        );
    }

    /**
     * (b) reject() succeeds end-to-end the same way.
     */
    public function test_reject_succeeds_end_to_end_with_matching_firm_actor_and_zero_ambient_context(): void
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

        $request = $this->findApprovalRequestForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $rejected = app(AiApprovalWorkflowService::class)->reject($request, $owner, 'needs revision');

        $this->assertSame(AiApprovalRequestStatus::Rejected, $rejected->status);
        $this->assertNotNull($rejected->resolved_at);
        $this->assertTrue(
            $this->approvalEventExistsForFirm($firm, $request->id, AiApprovalEventType::Rejected),
            'reject() must persist a "rejected" ai_approval_events row.'
        );
    }

    /**
     * (c) CRITICAL REGRESSION TEST: approve() called with a
     * MISMATCHED-firm actor (an actor from a different firm than the
     * request) throws the new RuntimeException BEFORE any context is
     * established or any DB statement runs — verified both via
     * assertNoDatabaseTenantContext() (no context was ever set, so none
     * needs to clear) and by re-reading the request's own status
     * afterward (still Pending, completely unchanged) under its own
     * firm's context.
     */
    public function test_approve_with_mismatched_firm_actor_throws_before_any_context_or_write_critical_regression(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $otherFirm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();
        $mismatchedActor = $this->makeAttorney($otherFirm);

        app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            $this->highRiskRequest(AiUsageActionType::DemandLetter),
            new AiProviderResponse(outputText: 'Demand letter draft.', tokensIn: 20, tokensOut: 30),
        );

        $request = $this->findApprovalRequestForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        try {
            app(AiApprovalWorkflowService::class)->approve($request, $mismatchedActor);
            $this->fail('Expected a RuntimeException for a mismatched-firm actor.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Actor does not belong to the same firm as this AI approval request.', $e->getMessage());
        }

        // The mismatch guard is a pure in-memory check that runs before
        // assertPending() and before runWithFirmContext() — no context
        // was ever established, so none needs to clear.
        $this->assertNoDatabaseTenantContext();

        // Re-read the request directly under its OWN firm's context to
        // confirm the mismatched-actor call had ZERO side effects.
        $reRead = $this->runWithFirmContext(
            $firm,
            fn () => AiApprovalRequest::withoutGlobalScopes()->find($request->id),
        );

        $this->assertNotNull($reRead);
        $this->assertSame(AiApprovalRequestStatus::Pending, $reRead->status);
        $this->assertNull($reRead->resolved_at);
        $this->assertFalse(
            $this->approvalEventExistsForFirm($firm, $request->id, AiApprovalEventType::Approved),
            'A mismatched-firm actor call must have ZERO side effects — no ai_approval_events row may exist.'
        );
    }

    /**
     * (d) Same mismatched-actor regression test for reject().
     */
    public function test_reject_with_mismatched_firm_actor_throws_before_any_context_or_write_critical_regression(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $otherFirm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();
        $mismatchedActor = $this->makeFirmOwner($otherFirm);

        app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            $this->highRiskRequest(AiUsageActionType::ClientLegalAdvice),
            new AiProviderResponse(outputText: 'Advice draft.', tokensIn: 20, tokensOut: 30),
        );

        $request = $this->findApprovalRequestForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        try {
            app(AiApprovalWorkflowService::class)->reject($request, $mismatchedActor, 'attempted cross-firm rejection');
            $this->fail('Expected a RuntimeException for a mismatched-firm actor.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Actor does not belong to the same firm as this AI approval request.', $e->getMessage());
        }

        $this->assertNoDatabaseTenantContext();

        $reRead = $this->runWithFirmContext(
            $firm,
            fn () => AiApprovalRequest::withoutGlobalScopes()->find($request->id),
        );

        $this->assertNotNull($reRead);
        $this->assertSame(AiApprovalRequestStatus::Pending, $reRead->status);
        $this->assertNull($reRead->resolved_at);
        $this->assertFalse(
            $this->approvalEventExistsForFirm($firm, $request->id, AiApprovalEventType::Rejected),
            'A mismatched-firm actor call must have ZERO side effects — no ai_approval_events row may exist.'
        );
    }

    /**
     * (e) Tenant context clears after both the success and exception
     * paths through approve(). Ambient tenant context is explicitly
     * cleared before each call (see findApprovalRequestForFirm()'s own
     * docblock above for why the fixtures otherwise leave a
     * database-only leftover context active) so this test proves
     * approve() itself leaves no context behind, rather than merely
     * restoring that pre-existing fixture leftover.
     */
    public function test_tenant_context_clears_after_approve_success_and_after_exception(): void
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

        $request = $this->findApprovalRequestForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();
        app(AiApprovalWorkflowService::class)->approve($request, $attorney);
        $this->assertNoDatabaseTenantContext('Tenant context must clear after a successful approve().');

        $freshRequest = $this->runWithFirmContext($firm, fn () => $request->fresh());

        (new TenantContextService)->clearDatabaseTenantContext();
        try {
            app(AiApprovalWorkflowService::class)->approve($freshRequest, $attorney);
            $this->fail('Expected the already-resolved RuntimeException.');
        } catch (\RuntimeException $e) {
            // expected — request already resolved
        }

        $this->assertNoDatabaseTenantContext('Tenant context must clear after approve() throws on an already-resolved request.');
    }

    /**
     * (e) Tenant context clears after both the success and exception
     * paths through reject().
     */
    public function test_tenant_context_clears_after_reject_success_and_after_exception(): void
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

        $request = $this->findApprovalRequestForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();
        app(AiApprovalWorkflowService::class)->reject($request, $owner, 'needs revision');
        $this->assertNoDatabaseTenantContext('Tenant context must clear after a successful reject().');

        $freshRequest = $this->runWithFirmContext($firm, fn () => $request->fresh());

        (new TenantContextService)->clearDatabaseTenantContext();
        try {
            app(AiApprovalWorkflowService::class)->reject($freshRequest, $owner, 'second attempt');
            $this->fail('Expected the already-resolved RuntimeException.');
        } catch (\RuntimeException $e) {
            // expected — request already resolved
        }

        $this->assertNoDatabaseTenantContext('Tenant context must clear after reject() throws on an already-resolved request.');
    }
}
