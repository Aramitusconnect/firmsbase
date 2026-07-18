<?php

namespace Tests\Feature\Ai\Usage;

use App\Enums\AiMode;
use App\Enums\AiProvider;
use App\Enums\AiUsageActionType;
use App\Enums\EntitlementSource;
use App\Enums\PaymentMode;
use App\Enums\UsageRollupMetric;
use App\Models\Firm;
use App\Models\User;
use App\Services\AiUsageRecorderService;
use App\Services\EncryptionKeyService;
use App\Services\EntitlementService;
use App\Services\UsageRollupService;
use App\ValueObjects\AiPromptRequest;
use App\ValueObjects\AiProviderResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ai\Concerns\SetsUpAiEntitledFirm;
use Tests\TestCase;

/**
 * Budget/token-limit enforcement (firm-level) and organization-level
 * usage rollup (existing UsageRollupService/UsageRollupMetric::AiTokens
 * pattern, project rule: account for organization-level budgets using
 * existing usage rollup patterns).
 */
class AiUsageRecorderServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpAiEntitledFirm;

    private function requestFor(AiUsageActionType $type = AiUsageActionType::Summarization): AiPromptRequest
    {
        return new AiPromptRequest(
            provider: AiProvider::OpenAi,
            model: 'fake-model-1',
            actionType: $type,
            instructionText: 'Summarize the attached notes.',
            documentDerivedText: null,
            matterIds: [],
        );
    }

    public function test_token_limit_is_enforced_at_firm_level(): void
    {
        $firm = $this->makeAiEntitledFirm();
        // firm_ai_settings has FORCE ROW LEVEL SECURITY (Section
        // 39A-5, Wave 1 firm_ai_settings checkpoint) — wrap the update
        // in runWithFirmContext() rather than relying on any
        // incidental leftover session context.
        $this->runWithFirmContext($firm, fn () => $firm->aiSettings->update(['token_limit_per_period' => 10]));
        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('token limit');

        app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            $this->requestFor(),
            new AiProviderResponse(outputText: 'x', tokensIn: 100, tokensOut: 100),
        );
    }

    public function test_budget_limit_is_enforced_at_firm_level(): void
    {
        $firm = $this->makeAiEntitledFirm();
        // firm_ai_settings has FORCE ROW LEVEL SECURITY (Section
        // 39A-5, Wave 1 firm_ai_settings checkpoint) — wrap the update
        // in runWithFirmContext() rather than relying on any
        // incidental leftover session context.
        $this->runWithFirmContext($firm, fn () => $firm->aiSettings->update(['budget_limit_cents_per_period' => 0]));
        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('budget limit');

        app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            $this->requestFor(),
            new AiProviderResponse(outputText: 'x', tokensIn: 1000, tokensOut: 1000),
        );
    }

    public function test_within_limits_the_request_succeeds_and_records_usage(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();

        $event = app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            $this->requestFor(),
            new AiProviderResponse(outputText: 'x', tokensIn: 10, tokensOut: 5),
        );

        $this->assertSame(10, $event->tokens_in);
        $this->assertSame(5, $event->tokens_out);
    }

    public function test_organization_level_usage_budget_uses_existing_usage_rollup_pattern(): void
    {
        $firm = Firm::factory()->withBillingAccount()->create();
        // Re-use the entitlement helper's setup manually since
        // makeAiEntitledFirm() creates its own firm.
        app(EntitlementService::class)->setForSource($firm, 'ai', EntitlementSource::AdminOverride, true);
        app(EncryptionKeyService::class)->provision($firm);
        // firm_settings has FORCE ROW LEVEL SECURITY (Section 39A-3L,
        // Checkpoint 18) — a direct relation create runs with no
        // tenant context active and is rejected by the policy. The
        // subsequent refresh()/relation load also needs context active
        // so AiUsageRecorderService::record() below finds firmSettings
        // already cached on $firm rather than lazy-loading it later
        // with no context (which would return null under FORCE RLS,
        // not an exception, and read as "AI mode is disabled").
        $this->runWithFirmContext($firm, function () use ($firm) {
            $firm->firmSettings()->create(['payment_mode' => PaymentMode::OperatingPaymentsOnly, 'ai_mode' => AiMode::PlatformManaged]);
            $firm->aiSettings()->create(['usage_markup_basis_points' => 0]);
            $firm->refresh();
            $firm->load('firmSettings', 'aiSettings');
        });

        $user = User::factory()->create();

        app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            $this->requestFor(),
            new AiProviderResponse(outputText: 'x', tokensIn: 40, tokensOut: 10),
        );

        $total = app(UsageRollupService::class)->totalForMetric(
            $firm->billingAccount,
            UsageRollupMetric::AiTokens,
            new \DateTimeImmutable('first day of this month 00:00:00'),
            new \DateTimeImmutable('last day of this month 23:59:59'),
        );

        $this->assertSame(50, $total);
    }

    public function test_usage_cost_is_metadata_only_and_never_writes_platform_billing_records(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();

        app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            $this->requestFor(),
            new AiProviderResponse(outputText: 'x', tokensIn: 100, tokensOut: 100),
        );

        $this->assertDatabaseCount('platform_invoices', 0);
        $this->assertDatabaseCount('platform_payments', 0);
    }
}
