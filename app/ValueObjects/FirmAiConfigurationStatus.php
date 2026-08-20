<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\AiMode;
use App\Enums\AiProviderKeyStatus;

/**
 * Everything the firm-facing AI settings page needs to tell a firm the truth
 * about its own AI configuration, gathered in one read.
 *
 * The page shows several things that can each independently prevent AI from
 * running — entitlement, platform kill switch, mode, credential, budget — and
 * the failure a firm actually hits is whichever one comes first. Assembling
 * them into one object means the page reports the same facts the runtime path
 * enforces, instead of re-deriving them per row and drifting.
 *
 * Contains no secret material: a credential is represented by its status and
 * its label, never by ciphertext or plaintext.
 */
final readonly class FirmAiConfigurationStatus
{
    public function __construct(
        public bool $entitlementEnabled,
        public bool $platformKillSwitchEngaged,
        public AiMode $configuredMode,
        public AiMode $effectiveMode,
        public bool $intakeAssistEnabled,
        public ?string $providerLabel,
        public ?string $model,
        public ?AiProviderKeyStatus $credentialStatus,
        public ?string $credentialLabel,
        public ?\DateTimeImmutable $credentialAddedAt,
        public ?int $tokenLimitPerPeriod,
        public int $tokensUsedThisPeriod,
    ) {}

    /**
     * Whether an intake turn would actually reach OpenAI right now. Every gate,
     * in the order the runtime applies them.
     */
    public function aiWouldRun(): bool
    {
        return $this->entitlementEnabled
            && ! $this->platformKillSwitchEngaged
            && $this->effectiveMode === AiMode::FirmOwned
            && $this->credentialStatus === AiProviderKeyStatus::Active
            && $this->intakeAssistEnabled
            && ! $this->budgetExhausted();
    }

    public function budgetExhausted(): bool
    {
        return $this->tokenLimitPerPeriod !== null
            && $this->tokensUsedThisPeriod >= $this->tokenLimitPerPeriod;
    }

    /**
     * The single most useful sentence for a firm whose AI is not running: which
     * gate is stopping it, in the order the runtime hits them.
     */
    public function blockingReason(): ?string
    {
        return match (true) {
            ! $this->entitlementEnabled => 'The AI entitlement is not enabled for this firm.',
            $this->platformKillSwitchEngaged => 'AI is currently paused platform-wide by FirmsVault.',
            $this->effectiveMode !== AiMode::FirmOwned => 'AI mode is not set to Firm Owned.',
            $this->credentialStatus !== AiProviderKeyStatus::Active => 'No active API key is stored for this firm.',
            ! $this->intakeAssistEnabled => 'AI-assisted intake is switched off.',
            $this->budgetExhausted() => 'This firm has reached its token budget for the current period.',
            default => null,
        };
    }
}
