<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Services\BillingAccessPolicyService;

/**
 * InvoicePolicy — mirrors PaymentPolicy's shape exactly, for the same
 * reason: this module declares ONLY viewAny()/view(). There is no
 * generic Create/Edit page for InvoiceResource (Firm Feature Manifest
 * §6: "Totals are always derived/cached — never expose status/totals
 * as editable form fields; every mutation must be a named Action
 * calling the service") — every mutation is one of the dedicated
 * InvoiceResource\Actions\* Actions, each checking
 * BillingAccessPolicyService directly inside its own visible()/
 * action() closures (matching RecordPaymentAction's established
 * convention for an Action with no corresponding CRUD policy method).
 */
class InvoicePolicy
{
    public function __construct(
        private readonly BillingAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canViewBilling($firmUser->role);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $invoice->firm_id
            && $this->accessPolicy->canViewBilling($firmUser->role);
    }
}
