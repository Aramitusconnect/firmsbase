<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FirmLead;
use App\Models\User;
use App\Services\ClientCrmAccessPolicyService;

/**
 * FirmLeadPolicy — mirrors ClientPolicy/ContactPolicy's shape.
 * `create()`/`update()` govern ONLY the plain intake form
 * (name/email/phone/lead_source_id/practice_area_interest_id/
 * assigned_to) — status and converted_client_id/converted_at are not
 * present as editable fields on either form (see FirmLeadResource's
 * own docblock), so this policy has no bearing on the conversion
 * transition at all. `convert()` is a distinct ability delegating to
 * ClientCrmAccessPolicyService::canConvertLead() — the ceiling
 * ConvertLeadToClientAction actually checks before ever calling
 * LeadConversionService::convert().
 */
class FirmLeadPolicy
{
    public function __construct(
        private readonly ClientCrmAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canView($firmUser->role);
    }

    public function view(User $user, FirmLead $firmLead): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $firmLead->firm_id
            && $this->accessPolicy->canView($firmUser->role);
    }

    public function create(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canManageLead($firmUser->role);
    }

    public function update(User $user, FirmLead $firmLead): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $firmLead->firm_id
            && $this->accessPolicy->canManageLead($firmUser->role)
            && ! $firmLead->isConverted();
    }

    public function convert(User $user, FirmLead $firmLead): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $firmLead->firm_id
            && $this->accessPolicy->canConvertLead($firmUser->role)
            && ! $firmLead->isConverted();
    }
}
