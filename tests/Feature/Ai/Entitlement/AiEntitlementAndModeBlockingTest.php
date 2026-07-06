<?php

namespace Tests\Feature\Ai\Entitlement;

use App\Enums\AiMode;
use App\Enums\AiProvider;
use App\Enums\AiUsageActionType;
use App\Models\Firm;
use App\Models\User;
use App\Services\AiUsageRecorderService;
use App\ValueObjects\AiPromptRequest;
use App\ValueObjects\AiProviderResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ai\Concerns\SetsUpAiEntitledFirm;
use Tests\TestCase;

/**
 * Project rules 1/2/3: AI disabled and missing entitlement must block
 * every AI service; platform_managed/firm_owned modes must be
 * enforceable. AiUsageRecorderService::record() is the single
 * orchestration entry point every AI action flows through, so
 * asserting it throws is the correct way to prove "every AI service"
 * is blocked — no other entry point exists in Phase 15 (no routes,
 * jobs, or API endpoints were added).
 */
class AiEntitlementAndModeBlockingTest extends TestCase
{
    use RefreshDatabase, SetsUpAiEntitledFirm;

    private function samplePromptRequest(): AiPromptRequest
    {
        return new AiPromptRequest(
            provider: AiProvider::OpenAi,
            model: 'fake-model-1',
            actionType: AiUsageActionType::Summarization,
            instructionText: 'Summarize the attached notes.',
            documentDerivedText: null,
            matterIds: [],
        );
    }

    private function sampleResponse(): AiProviderResponse
    {
        return new AiProviderResponse(outputText: 'fake output', tokensIn: 10, tokensOut: 5);
    }

    public function test_ai_entitlement_disabled_blocks_all_ai_services(): void
    {
        // Firm with NO 'ai' entitlement at all — a plain firm, not
        // routed through makeAiEntitledFirm().
        $firm = Firm::factory()->create();
        $firm->firmSettings()->create([
            'payment_mode' => \App\Enums\PaymentMode::OperatingPaymentsOnly,
            'ai_mode' => AiMode::PlatformManaged,
        ]);
        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ai entitlement is not enabled');

        app(AiUsageRecorderService::class)->record($firm, $user, $this->samplePromptRequest(), $this->sampleResponse());
    }

    public function test_ai_mode_disabled_blocks_all_ai_services_even_when_entitled(): void
    {
        $firm = $this->makeAiEntitledFirm(AiMode::Disabled);
        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI mode is disabled');

        app(AiUsageRecorderService::class)->record($firm, $user, $this->samplePromptRequest(), $this->sampleResponse());
    }

    public function test_platform_managed_mode_works_with_fake_adapter_only(): void
    {
        $firm = $this->makeAiEntitledFirm(AiMode::PlatformManaged);
        $user = User::factory()->create();

        $event = app(AiUsageRecorderService::class)->record($firm, $user, $this->samplePromptRequest(), $this->sampleResponse());

        $this->assertNotNull($event);
        $this->assertSame(AiMode::PlatformManaged, $event->ai_mode);
        $this->assertDatabaseHas('ai_usage_events', ['id' => $event->id, 'ai_mode' => AiMode::PlatformManaged->value]);
    }

    public function test_firm_owned_mode_without_an_active_key_blocks_the_request(): void
    {
        $firm = $this->makeAiEntitledFirm(AiMode::FirmOwned);
        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('firm_owned mode requires an active');

        app(AiUsageRecorderService::class)->record($firm, $user, $this->samplePromptRequest(), $this->sampleResponse());
    }

    public function test_firm_owned_mode_with_an_active_key_is_enforceable(): void
    {
        $firm = $this->makeAiEntitledFirm(AiMode::FirmOwned);
        $user = User::factory()->create();

        app(\App\Services\AiProviderKeyService::class)->generate($firm, AiProvider::OpenAi, $user);

        $event = app(AiUsageRecorderService::class)->record($firm, $user, $this->samplePromptRequest(), $this->sampleResponse());

        $this->assertNotNull($event);
        $this->assertSame(AiMode::FirmOwned, $event->ai_mode);
    }
}
