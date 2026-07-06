<?php

namespace App\Services;

use App\Models\Firm;
use App\ValueObjects\AiAccessDecision;

/**
 * AiEntitlementPolicyService — gates on the 'ai' module_catalog code
 * (seeded by the Phase 15 module_catalog data migration). Mirrors
 * WebhookEntitlementPolicyService's exact shape. No new entitlement
 * system is introduced — this is the EXISTING EntitlementService/
 * module_catalog/firm_entitlements mechanism, reused as-is (project
 * rules: no second entitlement system; if a firm is not entitled to
 * AI, AI services must be blocked).
 */
class AiEntitlementPolicyService
{
    private const MODULE_CODE = 'ai';

    public function __construct(private readonly EntitlementService $entitlementService)
    {
    }

    public function isEnabled(Firm $firm): bool
    {
        return $this->entitlementService->isEnabled($firm->id, self::MODULE_CODE);
    }

    public function evaluate(Firm $firm): AiAccessDecision
    {
        if (! $this->isEnabled($firm)) {
            return AiAccessDecision::deny('The ai entitlement is not enabled for this firm.');
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
}
