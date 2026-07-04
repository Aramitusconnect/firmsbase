<?php

namespace App\Services;

/**
 * FeatureGateService — feature flags may only restrict what an
 * entitlement already grants, never widen it. isAllowed() returns false
 * immediately if the entitlement is disabled; otherwise it currently
 * always returns true, because no flags table exists yet. This is a
 * living contract: when a flags mechanism is built, it can only ever
 * turn a true into a false here, never a false into a true.
 */
class FeatureGateService
{
    public function __construct(private EntitlementService $entitlementService)
    {
    }

    public function isAllowed(int $firmId, string $moduleCode): bool
    {
        if (! $this->entitlementService->isEnabled($firmId, $moduleCode)) {
            return false;
        }

        // No feature-flag table exists yet. Once one does, add a
        // narrowing check here — it may only turn this true into
        // false, never override a false entitlement into true.
        return true;
    }
}
