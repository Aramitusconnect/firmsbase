<?php

namespace App\Services;

use App\Models\Firm;
use App\ValueObjects\IntegrationAccessDecision;

/**
 * PlaidEntitlementPolicyService — FirmsVault Live Integrations,
 * Checkpoint 4 (checkpoint4-design-cost-control.md §2 step 3;
 * checkpoint4-combined-design.md §8.3). Byte-for-byte structural copy of
 * `App\Services\IntegrationEntitlementPolicyService`, gating on a NEW
 * `module_code = 'plaid'` (seeded by this checkpoint's own
 * `2026_09_24_500011_seed_plaid_module_catalog_entry.php` migration) —
 * deliberately SEPARATE from the existing `'integration'` code, since
 * Plaid is a genuinely add-on/paid-tier entitlement distinct from the
 * base Microsoft/Google `'integration'` entitlement. No new entitlement
 * system is introduced — this reuses the EXISTING
 * `EntitlementService`/`module_catalog`/`firm_entitlements` mechanism
 * as-is, matching `PlanModule.is_addon`'s existing "add-ons are
 * ordinary plan_modules rows flagged is_addon=true" convention.
 *
 * Pipeline step 3 ("verify entitlement") calls `assertEnabled()`
 * unconditionally for every Plaid billable call — this is the
 * commercial gate, checked before rate/policy resolution so an
 * un-entitled firm's call fails as cheaply as possible.
 */
class PlaidEntitlementPolicyService
{
    private const MODULE_CODE = 'plaid';

    public function __construct(private readonly EntitlementService $entitlementService) {}

    public function isEnabled(Firm $firm): bool
    {
        return $this->entitlementService->isEnabled($firm->id, self::MODULE_CODE);
    }

    public function evaluate(Firm $firm): IntegrationAccessDecision
    {
        if (! $this->isEnabled($firm)) {
            return IntegrationAccessDecision::deny('The Plaid entitlement is not enabled for this firm.');
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
