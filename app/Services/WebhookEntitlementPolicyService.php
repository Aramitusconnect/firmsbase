<?php

namespace App\Services;

use App\Models\Firm;
use App\ValueObjects\WebhookAccessDecision;

/**
 * WebhookEntitlementPolicyService — gates on the 'webhook' module_catalog
 * code (approved correction #2), which is deliberately SEPARATE from the
 * existing 'api' code — a firm can have API access without webhook
 * access, or vice versa. Mirrors ApiAccessPolicyService's entitlement
 * check exactly, just against a different module_code. No new
 * entitlement system is introduced — this is the EXISTING
 * EntitlementService/module_catalog/firm_entitlements mechanism, reused
 * as-is.
 */
class WebhookEntitlementPolicyService
{
    private const MODULE_CODE = 'webhook';

    public function __construct(private readonly EntitlementService $entitlementService)
    {
    }

    public function isEnabled(Firm $firm): bool
    {
        return $this->entitlementService->isEnabled($firm->id, self::MODULE_CODE);
    }

    public function evaluate(Firm $firm): WebhookAccessDecision
    {
        if (! $this->isEnabled($firm)) {
            return WebhookAccessDecision::deny('The webhook entitlement is not enabled for this firm.');
        }

        return WebhookAccessDecision::allow();
    }

    public function assertEnabled(Firm $firm): void
    {
        $decision = $this->evaluate($firm);

        if (! $decision->allowed) {
            throw new \RuntimeException($decision->reason);
        }
    }
}
