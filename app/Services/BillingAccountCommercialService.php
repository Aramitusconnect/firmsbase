<?php

namespace App\Services;

use App\Models\BillingAccount;
use App\Models\Organization;

/**
 * BillingAccountCommercialService — admin-facing billing-account
 * commercial operations. A billing account is the bill-to entity
 * (project rule 4) and may optionally belong to an organization for
 * consolidated invoicing.
 */
class BillingAccountCommercialService
{
    public function createBillingAccount(array $attributes, ?Organization $organization = null): BillingAccount
    {
        return BillingAccount::create(array_merge($attributes, [
            'organization_id' => $organization?->id,
        ]));
    }

    public function attachToOrganization(BillingAccount $billingAccount, Organization $organization): BillingAccount
    {
        return tap($billingAccount)->update(['organization_id' => $organization->id])->fresh();
    }

    public function detachFromOrganization(BillingAccount $billingAccount): BillingAccount
    {
        return tap($billingAccount)->update(['organization_id' => null])->fresh();
    }
}
