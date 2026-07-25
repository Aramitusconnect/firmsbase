<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;

/**
 * FirmUserPolicy — Phase 1 FirmsVault Admin Control Center. Same shape
 * and same guard-resolution caveat as its sibling FirmPolicy (see that
 * class's own docblock for the full reasoning — not repeated here).
 *
 * Read-only by design: unlike FirmPolicy, this class declares NO
 * mutating method. This checkpoint's new gate methods on
 * PlatformStaffAccessPolicyService (canAccessPlatformAdministration/
 * canManageFirms/canManagePlatformAdministrators/canManageRoles) do not
 * include one specific to "manage firm users" — mutating a firm's own
 * membership rows (role/status/seat_class) is a firm-membership
 * operation that, if ever exposed to platform staff at all, deserves
 * its own deliberately-scoped gate rather than borrowing
 * canManageFirms()'s firm-status-mutation semantics for a materially
 * different action. FirmUserResource itself is List+View only in this
 * checkpoint, so no mutating method is needed to satisfy any current
 * caller either.
 */
class FirmUserPolicy
{
    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(PlatformAdmin $admin): bool
    {
        return $this->accessPolicy->canAccessPlatformAdministration($admin)->allowed;
    }

    public function view(PlatformAdmin $admin, FirmUser $firmUser): bool
    {
        return $this->accessPolicy->canAccessPlatformAdministration($admin)->allowed;
    }
}
