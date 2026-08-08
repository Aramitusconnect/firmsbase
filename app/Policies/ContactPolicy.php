<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;
use App\Services\ClientCrmAccessPolicyService;

/**
 * ContactPolicy — mirrors ClientPolicy/FirmIntegrationPolicy's shape.
 * Contact has no creation restriction (confirmed by direct source
 * read — see Contact's own model docblock and the Firm Feature
 * Manifest §1: "No creation restriction — safe for a normal Filament
 * resource"), so unlike ClientPolicy this declares a real `create()`.
 */
class ContactPolicy
{
    public function __construct(
        private readonly ClientCrmAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canView($firmUser->role);
    }

    public function view(User $user, Contact $contact): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $contact->firm_id
            && $this->accessPolicy->canView($firmUser->role);
    }

    public function create(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canManageContact($firmUser->role);
    }

    public function update(User $user, Contact $contact): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $contact->firm_id
            && $this->accessPolicy->canManageContact($firmUser->role);
    }
}
