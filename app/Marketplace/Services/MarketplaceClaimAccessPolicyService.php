<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Enums\FirmUserRole;
use App\Marketplace\Models\DirectoryClaim;
use App\Models\FirmUser;

/**
 * MarketplaceClaimAccessPolicyService — role ceiling and ownership
 * check for the claim workflow's firm-facing side, mirroring
 * FirmSettingsAccessPolicyService's established shape (a plain service
 * rather than a standard Laravel Policy class, for the same
 * "App\Models\User actor / no Filament-panel-collision" reasoning that
 * class's own docblock documents).
 *
 * MANAGE (initiate a claim, view own claim status): FirmOwner only —
 * claiming a marketplace listing is firm-wide, not a single record an
 * Attorney/Paralegal owns, matching FirmSettingsAccessPolicyService's
 * own "firm-wide configuration" reasoning for the analogous question.
 *
 * ownsClaim() is section 59's explicit requirement in code form: a
 * FirmUser may only act on a claim whose real, database-persisted
 * firm_id matches their OWN authenticated tenant firm_id — never a
 * client-submitted value, never inferred from anything the request
 * itself supplies.
 */
class MarketplaceClaimAccessPolicyService
{
    public function canManageClaims(FirmUserRole $role): bool
    {
        return $role === FirmUserRole::FirmOwner;
    }

    public function ownsClaim(FirmUser $firmUser, DirectoryClaim $claim): bool
    {
        return (int) $claim->firm_id === (int) $firmUser->firm_id;
    }
}
