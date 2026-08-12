<?php

declare(strict_types=1);

namespace App\Policies;

use App\Marketplace\Models\MarketplaceIntake;
use App\Models\User;
use App\Services\ClientCrmAccessPolicyService;

/**
 * MarketplaceIntakePolicy — Mission 3 (MyAttorney Conversion + AI
 * Intake), checkpoint 9. No dedicated MarketplaceIntake-specific
 * access-policy service exists (or is needed): a MarketplaceIntake is
 * the pre-lead-pipeline predecessor of a FirmLead — the same staff who
 * already triage/manage leads are the correct audience — so this
 * reuses ClientCrmAccessPolicyService's existing role ceilings
 * verbatim (mirrors FirmLeadPolicy's own exact shape) rather than
 * fragmenting authorization logic into a second, parallel policy
 * service for an adjacent domain.
 *
 * `manage()` covers every Firm-triggered transition/action on an
 * intake (mark under review, run/clear conflict review, generate AI
 * summary) — reuses canManageLead()'s ceiling (FirmOwner, Attorney,
 * Paralegal, LegalAssistant, Receptionist), the same "routine intake
 * triage work" tier FirmLead's own create/edit already uses.
 */
class MarketplaceIntakePolicy
{
    public function __construct(
        private readonly ClientCrmAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canView($firmUser->role);
    }

    public function view(User $user, MarketplaceIntake $marketplaceIntake): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $marketplaceIntake->firm_id
            && $this->accessPolicy->canView($firmUser->role);
    }

    public function manage(User $user, MarketplaceIntake $marketplaceIntake): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $marketplaceIntake->firm_id
            && $this->accessPolicy->canManageLead($firmUser->role);
    }
}
