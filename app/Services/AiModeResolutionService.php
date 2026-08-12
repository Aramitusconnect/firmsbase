<?php

namespace App\Services;

use App\Enums\AiMode;
use App\Enums\AiProvider;
use App\Enums\AiProviderKeyStatus;
use App\Models\Firm;
use App\Models\FirmAiProviderKey;
use App\ValueObjects\AiAccessDecision;

/**
 * AiModeResolutionService — the single place that resolves a firm's
 * effective AI posture and is the gate every other AI service calls
 * before doing anything, mirroring how TrustEligibilityService
 * centralizes trust-mode resolution in Phase 13.
 *
 * Mode itself is read from firm_settings.ai_mode (Phase 1 column,
 * approved decision #1 — the single source of truth; firm_ai_settings
 * never duplicates it). Combines that with the 'ai' entitlement gate
 * (project rules 1/2: disabled AI or missing entitlement must block
 * every AI service) and, for firm_owned mode, the presence of an
 * Active firm_ai_provider_keys row for the requested provider (project
 * rule: firm-owned mode requires an active encrypted firm provider
 * key).
 *
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 4 — a
 * genuine PLATFORM-LEVEL kill switch, independent of any single
 * firm's mode/entitlement/keys and independent of MyAttorney itself
 * (this class is the one gate every AI consumer in the whole
 * codebase already goes through, not a MyAttorney-specific check).
 * Backed by the ai_policy_settings row keyed
 * PLATFORM_KILL_SWITCH_KEY, via the already-existing (previously
 * unenforced — confirmed by AiPolicySettingService's own docblock: no
 * service call site read from it before this) AiPolicySettingService.
 * Checked FIRST, before per-firm entitlement/mode, so a platform
 * admin can halt every AI call in the system in one write regardless
 * of any firm's own configuration. Absent row (null) or an explicit
 * true both mean "not killed" — the default, non-breaking state for
 * every firm/test that predates this setting; only an explicit
 * `false` engages the kill switch.
 */
class AiModeResolutionService
{
    public const PLATFORM_KILL_SWITCH_KEY = 'platform_ai_enabled';

    public function __construct(
        private readonly AiEntitlementPolicyService $entitlementPolicy,
        private readonly AiPolicySettingService $policySettings = new AiPolicySettingService,
    ) {}

    public function resolve(Firm $firm): AiMode
    {
        return $firm->firmSettings?->ai_mode ?? AiMode::Disabled;
    }

    public function platformKillSwitchEngaged(): bool
    {
        return $this->policySettings->get(self::PLATFORM_KILL_SWITCH_KEY) === false;
    }

    /**
     * Base gate: platform kill switch not engaged, entitlement
     * enabled, AND mode is not Disabled. Does NOT check
     * provider-key availability — see evaluateProviderAccess() for
     * the firm_owned-specific check.
     */
    public function evaluate(Firm $firm): AiAccessDecision
    {
        if ($this->platformKillSwitchEngaged()) {
            return AiAccessDecision::deny('AI is currently disabled platform-wide.');
        }

        $entitlementDecision = $this->entitlementPolicy->evaluate($firm);

        if (! $entitlementDecision->allowed) {
            return $entitlementDecision;
        }

        if ($this->resolve($firm) === AiMode::Disabled) {
            return AiAccessDecision::deny('AI mode is disabled for this firm.');
        }

        return AiAccessDecision::allow();
    }

    public function assertEnabled(Firm $firm): void
    {
        $decision = $this->evaluate($firm);

        if (! $decision->allowed) {
            throw new \RuntimeException($decision->reason);
        }
    }

    /**
     * Provider-specific gate, layered on top of evaluate(). In
     * platform_managed mode any allowed provider is fine (no firm key
     * needed — Phase 15 only ever reaches the FakeAiProviderAdapter
     * regardless). In firm_owned mode, an Active
     * firm_ai_provider_keys row for this exact (firm, provider) pair
     * is required, or the request must be blocked.
     */
    public function evaluateProviderAccess(Firm $firm, AiProvider $provider): AiAccessDecision
    {
        $baseDecision = $this->evaluate($firm);

        if (! $baseDecision->allowed) {
            return $baseDecision;
        }

        if ($this->resolve($firm) === AiMode::FirmOwned) {
            $hasActiveKey = FirmAiProviderKey::query()
                ->where('firm_id', $firm->id)
                ->where('provider', $provider->value)
                ->where('status', AiProviderKeyStatus::Active->value)
                ->exists();

            if (! $hasActiveKey) {
                return AiAccessDecision::deny(
                    "firm_owned mode requires an active {$provider->value} provider key for this firm."
                );
            }
        }

        return AiAccessDecision::allow();
    }

    public function assertProviderAccess(Firm $firm, AiProvider $provider): void
    {
        $decision = $this->evaluateProviderAccess($firm, $provider);

        if (! $decision->allowed) {
            throw new \RuntimeException($decision->reason);
        }
    }
}
