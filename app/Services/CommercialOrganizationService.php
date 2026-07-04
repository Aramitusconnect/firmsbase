<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\Organization;
use App\Models\Plan;

/**
 * CommercialOrganizationService — admin-facing organization commercial
 * operations: create an organization, attach/detach member firms, and
 * set its default plan. Does not itself write firm_entitlements or
 * issue licenses — see OrgLicenseService for that.
 */
class CommercialOrganizationService
{
    public function createOrganization(array $attributes): Organization
    {
        return Organization::create($attributes);
    }

    public function attachFirm(Organization $organization, Firm $firm): Firm
    {
        return tap($firm)->update(['organization_id' => $organization->id])->fresh();
    }

    public function detachFirm(Firm $firm): Firm
    {
        return tap($firm)->update(['organization_id' => null])->fresh();
    }

    public function setDefaultPlan(Organization $organization, Plan $plan): Organization
    {
        return tap($organization)->update(['default_plan_id' => $plan->id])->fresh();
    }
}
