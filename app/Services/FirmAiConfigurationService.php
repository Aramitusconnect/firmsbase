<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\OpenAi\OpenAiProviderAdapter;
use App\Enums\AiMode;
use App\Enums\AiProvider;
use App\Models\Firm;
use App\Models\FirmAiProviderKey;
use App\Models\FirmSettings;
use App\ValueObjects\FirmAiConfigurationStatus;

/**
 * The firm-facing AI configuration surface: what a firm may change about its
 * own AI setup, and how to read the current state honestly.
 *
 * Deliberately narrower than the domain. firm_settings.ai_mode has three
 * values, but only two are offerable to a firm:
 *
 *   Disabled    no AI runs for this firm
 *   FirmOwned   AI runs on the firm's own OpenAI credential
 *
 * PlatformManaged is refused here rather than merely hidden in the UI. It
 * would mean "FirmsVault supplies the credential", FirmsVault holds none, and
 * a firm that reached that state through a forged form submission would see a
 * settings page claiming AI is on while every intake silently degraded. Hiding
 * an option in a form is a presentation choice; refusing it in the service is
 * the actual guarantee.
 *
 * All writes are wrapped in tenant context: firm_settings and firm_ai_settings
 * both carry permanent FORCE ROW LEVEL SECURITY, and a Livewire submit handler
 * runs with no ambient app.current_firm_id.
 */
final readonly class FirmAiConfigurationService
{
    /**
     * The only modes a firm may select for itself.
     */
    public const SELECTABLE_MODES = [AiMode::Disabled, AiMode::FirmOwned];

    public function __construct(
        private AiModeResolutionService $modeResolution,
        private AiEntitlementPolicyService $entitlementPolicy,
        private AiProviderResolver $resolver,
        private AiProviderKeyService $keys,
        private AiBudgetEnforcementService $budgetEnforcement,
        private TenantContextService $tenantContext,
    ) {}

    public function setMode(Firm $firm, AiMode $mode): void
    {
        if (! in_array($mode, self::SELECTABLE_MODES, true)) {
            throw new \InvalidArgumentException("AI mode {$mode->value} cannot be selected by a firm.");
        }

        $this->tenantContext->runWithFirmContext($firm, function () use ($firm, $mode): void {
            FirmSettings::query()
                ->where('firm_id', $firm->id)
                ->firstOrFail()
                ->update(['ai_mode' => $mode]);
        });
    }

    public function setIntakeAssistEnabled(Firm $firm, bool $enabled): void
    {
        $this->tenantContext->runWithFirmContext($firm, function () use ($firm, $enabled): void {
            $settings = $firm->aiSettings()->first();

            if ($settings === null) {
                throw new \RuntimeException('This firm has no AI settings row.');
            }

            $settings->update(['intake_ai_assist_enabled' => $enabled]);
        });
    }

    /**
     * Point this firm at a specific model.
     *
     * Stored in firm_ai_settings.allowed_models_json, which AiProviderResolver
     * already reads in preference to the configured default — so a firm whose
     * OpenAI project is granted a different model than the platform default
     * can say so without a config change or a deployment.
     */
    public function setModel(Firm $firm, string $model): void
    {
        $model = trim($model);

        if ($model === '') {
            throw new \InvalidArgumentException('A model is required.');
        }

        $this->tenantContext->runWithFirmContext($firm, function () use ($firm, $model): void {
            $settings = $firm->aiSettings()->first();

            if ($settings === null) {
                throw new \RuntimeException('This firm has no AI settings row.');
            }

            $settings->update(['allowed_models_json' => [$model]]);
        });
    }

    /**
     * The models this firm's own credential may use, for the settings page's
     * model selector.
     *
     * Returns an empty array rather than throwing when the credential is
     * missing, revoked, or OpenAI cannot be reached: a settings page must still
     * render when the provider is having a bad day. The caller falls back to
     * whatever the firm already has configured.
     *
     * @return array<int, string>
     */
    public function availableModels(Firm $firm): array
    {
        $adapter = $this->resolver->adapterFor($firm);

        if (! $adapter instanceof OpenAiProviderAdapter) {
            return [];
        }

        try {
            return $adapter->availableModels();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * One read of everything the settings page reports.
     */
    public function status(Firm $firm): FirmAiConfigurationStatus
    {
        $firm = $firm->fresh(['firmSettings', 'aiSettings']) ?? $firm;

        $configuredMode = $firm->firmSettings?->ai_mode ?? AiMode::Disabled;
        $provider = $this->resolver->providerFor($firm);

        $key = $provider === null
            ? $this->latestKey($firm, AiProvider::OpenAi)
            : ($this->keys->activeKeyFor($firm, $provider) ?? $this->latestKey($firm, $provider));

        [$periodStartsAt, $periodEndsAt] = $this->currentPeriod();

        $tokensUsed = $this->tenantContext->runWithFirmContextWithoutTransaction(
            $firm,
            fn (): int => $this->budgetEnforcement->usedTokens($firm, $periodStartsAt, $periodEndsAt),
        );

        return new FirmAiConfigurationStatus(
            entitlementEnabled: $this->entitlementPolicy->isEnabled($firm),
            platformKillSwitchEngaged: $this->modeResolution->platformKillSwitchEngaged(),
            configuredMode: $configuredMode,
            effectiveMode: $this->modeResolution->resolve($firm),
            intakeAssistEnabled: (bool) ($firm->aiSettings?->intake_ai_assist_enabled ?? false),
            // The provider is named only when it is genuinely in use. Showing
            // "OpenAI" for a Disabled firm would misdescribe its configuration.
            providerLabel: $provider === null ? null : 'OpenAI',
            model: $provider === null ? null : $this->resolver->modelFor($firm),
            credentialStatus: $key?->status,
            credentialLabel: $key?->label,
            credentialAddedAt: $key?->created_at?->toDateTimeImmutable(),
            tokenLimitPerPeriod: $firm->aiSettings?->token_limit_per_period,
            tokensUsedThisPeriod: $tokensUsed,
        );
    }

    /**
     * The most recent credential of any status, so a firm that revoked or
     * rotated its key still sees what happened to it rather than an empty
     * "no credential" row that looks like it was never added.
     */
    private function latestKey(Firm $firm, AiProvider $provider): ?FirmAiProviderKey
    {
        return $this->tenantContext->runWithFirmContext($firm, fn (): ?FirmAiProviderKey => FirmAiProviderKey::query()
            ->where('firm_id', $firm->id)
            ->where('provider', $provider)
            ->latest('id')
            ->first());
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function currentPeriod(): array
    {
        return [
            new \DateTimeImmutable('first day of this month 00:00:00'),
            new \DateTimeImmutable('last day of this month 23:59:59'),
        ];
    }
}
