<?php

namespace App\Services;

use App\Models\Firm;
use App\ValueObjects\IntegrationAccessDecision;

/**
 * IntegrationEntitlementPolicyService — Checkpoint 9 (frozen design §4,
 * §5). Byte-for-byte structural copy of
 * App\Services\WebhookEntitlementPolicyService, gating on the NEW
 * 'integration' module_catalog code (seeded by this checkpoint's own
 * `2026_09_08_082001_seed_integration_module_catalog_entry.php`
 * migration) — deliberately SEPARATE from the existing 'webhook'/'api'
 * codes, matching this codebase's established "one module_code per
 * independently-entitleable feature" convention. No new entitlement
 * system is introduced — this is the EXISTING
 * EntitlementService/module_catalog/firm_entitlements mechanism, reused
 * as-is.
 *
 * Frozen authorization ordering (frozen design §4): entitlement is
 * checked BEFORE role/permission
 * (App\Integrations\Services\IntegrationAccessPolicyService::assertCan*()),
 * matching the proven, repeated `WebhookSubscriptionService` 5-call-site
 * precedent — supersedes the mission's own originally-proposed
 * permission-then-entitlement text.
 *
 * Scope boundary (frozen design §4): this class and its module_catalog
 * seed row are Checkpoint 9 deliverables (governance infrastructure).
 * Wiring it into an actual manual-sync-dispatch controller/UI action is
 * Checkpoint 10 scope — no controller/UI call site exists yet.
 */
class IntegrationEntitlementPolicyService
{
    private const MODULE_CODE = 'integration';

    public function __construct(private readonly EntitlementService $entitlementService)
    {
    }

    public function isEnabled(Firm $firm): bool
    {
        return $this->entitlementService->isEnabled($firm->id, self::MODULE_CODE);
    }

    public function evaluate(Firm $firm): IntegrationAccessDecision
    {
        if (! $this->isEnabled($firm)) {
            return IntegrationAccessDecision::deny('The integration entitlement is not enabled for this firm.');
        }

        return IntegrationAccessDecision::allow();
    }

    public function assertEnabled(Firm $firm): void
    {
        $decision = $this->evaluate($firm);

        if (! $decision->allowed) {
            throw new \RuntimeException($decision->reason);
        }
    }
}
