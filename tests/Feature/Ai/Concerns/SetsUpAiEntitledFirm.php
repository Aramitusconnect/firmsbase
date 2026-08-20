<?php

namespace Tests\Feature\Ai\Concerns;

use App\Enums\AiMode;
use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Enums\PaymentMode;
use App\Enums\TwoFactorMode;
use App\Models\Firm;
use App\Models\FirmSettings;
use App\Models\FirmUser;
use App\Services\EncryptionKeyService;
use App\Services\EntitlementService;

/**
 * Shared setup for Phase 15 AI governance tests: a firm with the 'ai'
 * entitlement enabled, an active TenantEncryptionKey (needed by
 * AiProviderKeyService/AiApprovalWorkflowService, both of which reuse
 * EmailBodyEncryptionService/EncryptionKeyService exactly as-is), and
 * a firm_ai_settings row with ai_mode set to platform_managed by
 * default (mirrors SetsUpWebhookEntitledFirm's exact shape).
 */
trait SetsUpAiEntitledFirm
{
    protected function makeAiEntitledFirm(AiMode $mode = AiMode::PlatformManaged): Firm
    {
        $firm = Firm::factory()->create();

        app(EntitlementService::class)->setForSource(
            $firm,
            'ai',
            EntitlementSource::AdminOverride,
            true,
        );

        app(EncryptionKeyService::class)->provision($firm);

        // firm_settings has FORCE ROW LEVEL SECURITY (Section 39A-3L,
        // Checkpoint 18) — a direct $firm->firmSettings()->create(...)
        // relation call runs with no tenant context active and is
        // rejected by the policy. FirmSettingsFactory::create() carries
        // the established context-hold fix (sets the matching
        // app.current_firm_id session context before inserting), so
        // route creation through it instead.
        FirmSettings::factory()->forFirm($firm)->create([
            'payment_mode' => PaymentMode::OperatingPaymentsOnly,
            'trust_iolta_protection' => true,
            'ai_mode' => $mode,
            'client_2fa_mode' => TwoFactorMode::Optional,
            'default_language' => 'en',
        ]);

        // firm_ai_settings has FORCE ROW LEVEL SECURITY (Section 39A-5,
        // Wave 1 firm_ai_settings checkpoint) — a direct
        // $firm->aiSettings()->create(...) relation call runs with no
        // tenant context active and is rejected by the policy. Wrap
        // the whole call in runWithFirmContext() rather than relying
        // on any incidental leftover session context from the
        // FirmSettings::factory() call above.
        $this->runWithFirmContext($firm, fn () => $firm->aiSettings()->create([
            'allowed_providers_json' => ['openai'],
            'allowed_models_json' => ['gpt-5.6-terra'],
            'token_limit_per_period' => null,
            'budget_limit_cents_per_period' => null,
            'usage_markup_basis_points' => 0,
            'document_context_enabled' => false,
            'client_data_context_enabled' => false,
            'high_risk_requires_approval' => true,
            'full_content_logging_enabled' => false,
        ]));

        return $firm->fresh(['firmSettings', 'aiSettings']);
    }

    protected function makeFirmOwner(Firm $firm): FirmUser
    {
        return FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
    }

    protected function makeAttorney(Firm $firm): FirmUser
    {
        return FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::Attorney]);
    }

    protected function makeParalegal(Firm $firm): FirmUser
    {
        return FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::Paralegal]);
    }

    protected function makeBillingStaff(Firm $firm): FirmUser
    {
        return FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);
    }
}
