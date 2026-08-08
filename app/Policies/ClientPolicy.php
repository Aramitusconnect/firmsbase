<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Services\ClientCrmAccessPolicyService;

/**
 * ClientPolicy — mirrors FirmIntegrationPolicy's shape (the established
 * "thin policy delegates to a dedicated *AccessPolicyService" pattern
 * in this codebase). Standard `App\Policies\{Model}Policy` naming, so
 * Laravel resolves it automatically for `App\Models\Client` — no
 * explicit registration needed (same as FirmPolicy/FirmUserPolicy).
 *
 * No `create()` method: Client rows are never created via a policy-
 * gated Filament CreateRecord page — see ClientResource's own
 * docblock. The "+ Add Client" action is gated directly by
 * ClientCrmAccessPolicyService::canConvertLead() at the Action level
 * (matching FirmIntegrationResource's "Connect" action, which is also
 * not a policy-gated Create page), not by this policy's `create()`,
 * which does not exist here.
 *
 * Every instance-scoped method re-confirms firm_id match as
 * defense-in-depth — never a substitute for `clients`' own FORCE ROW
 * LEVEL SECURITY, which remains the real tenant-isolation boundary.
 */
class ClientPolicy
{
    public function __construct(
        private readonly ClientCrmAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canView($firmUser->role);
    }

    public function view(User $user, Client $client): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $client->firm_id
            && $this->accessPolicy->canView($firmUser->role);
    }

    /**
     * Governs EditClient's safe-field-only edit form. Never governs
     * portal_status/portal_invitation_* (wildcard)/communication_preferences_id/
     * created_by — those are simply not present as editable fields on
     * the form (see EditClient), independent of this authorization
     * check.
     */
    public function update(User $user, Client $client): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $client->firm_id
            && $this->accessPolicy->canEditClient($firmUser->role);
    }
}
