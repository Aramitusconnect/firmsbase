<?php

namespace App\Services;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\OrgLicense;
use App\Models\Plan;
use App\Models\User;

/**
 * EntitlementPlanSyncService — writes the Plan and OrgInherited rows
 * into the EXISTING firm_entitlements table by calling the EXISTING
 * EntitlementService::setForSource(). Does NOT patch EntitlementService
 * itself (approved decision: only add helper methods there if
 * absolutely unavoidable, and it was avoidable — setForSource() already
 * accepts any EntitlementSource). Does NOT change entitlement
 * precedence in any way; EntitlementService::resolve() is completely
 * untouched.
 *
 * Only enabled plan_modules rows are synced as enabled=true; a module
 * NOT present on the plan is synced as enabled=false so a firm never
 * silently keeps an old Plan-source grant after switching to a plan
 * that no longer includes that module.
 */
class EntitlementPlanSyncService
{
    public function __construct(private EntitlementService $entitlementService)
    {
    }

    /**
     * Syncs every module_catalog module referenced by the plan's
     * plan_modules rows into firm_entitlements as EntitlementSource::Plan,
     * for the given firm. Call this whenever a FirmLicense's plan_id is
     * assigned or changed (FirmLicenseCommercialService's job to call
     * it, not this service's job to detect the change).
     *
     * @return array<string> the module_codes written
     */
    public function syncPlanEntitlements(Firm $firm, Plan $plan, ?User $actor = null): array
    {
        $plan->loadMissing('modules');

        $written = [];

        foreach ($plan->modules as $planModule) {
            $this->entitlementService->setForSource(
                firm: $firm,
                moduleCode: $planModule->module_code,
                source: EntitlementSource::Plan,
                enabled: $planModule->enabled,
                actor: $actor,
                reason: "plan sync: {$plan->name}",
            );

            $written[] = $planModule->module_code;
        }

        return $written;
    }

    /**
     * Syncs an organization's master license's plan modules into
     * firm_entitlements as EntitlementSource::OrgInherited, for one
     * member firm. Call this when a firm is attached to an organization
     * that already has an active OrgLicense, or when the organization's
     * OrgLicense plan changes.
     *
     * @return array<string> the module_codes written
     */
    public function syncOrgInheritedEntitlements(Firm $firm, OrgLicense $orgLicense, ?User $actor = null): array
    {
        $orgLicense->loadMissing('plan.modules');

        $written = [];

        foreach ($orgLicense->plan->modules as $planModule) {
            $this->entitlementService->setForSource(
                firm: $firm,
                moduleCode: $planModule->module_code,
                source: EntitlementSource::OrgInherited,
                enabled: $planModule->enabled,
                actor: $actor,
                reason: "org-inherited sync: org_license #{$orgLicense->id}",
            );

            $written[] = $planModule->module_code;
        }

        return $written;
    }
}
