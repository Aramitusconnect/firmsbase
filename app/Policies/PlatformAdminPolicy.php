<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;

/**
 * PlatformAdminPolicy — FirmsVault Admin Control Center Platform
 * Administrators resource. Same shape and same guard-resolution caveat
 * as FirmPolicy/FirmUserPolicy (see FirmPolicy's own docblock for the
 * full reasoning — not repeated here).
 *
 * Every method delegates to
 * PlatformStaffAccessPolicyService::canManagePlatformAdministrators()
 * (SuperAdmin-only) — this resource has no separate, broader "view"
 * ceiling the way FirmResource/FirmUserResource do (those are visible
 * to a wide PLATFORM_ADMINISTRATION_ROLES set; managing OTHER platform
 * administrators' identities/roles/MFA state is uniformly the most
 * sensitive action this mission gates, per
 * PLATFORM_ADMINISTRATOR_MANAGEMENT_ROLES' own docblock — so even
 * VIEWING the list is SuperAdmin-only here, not merely mutating it).
 */
class PlatformAdminPolicy
{
    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(PlatformAdmin $admin): bool
    {
        return $this->accessPolicy->canManagePlatformAdministrators($admin)->allowed;
    }

    public function view(PlatformAdmin $admin, PlatformAdmin $record): bool
    {
        return $this->accessPolicy->canManagePlatformAdministrators($admin)->allowed;
    }

    public function update(PlatformAdmin $admin, PlatformAdmin $record): bool
    {
        return $this->accessPolicy->canManagePlatformAdministrators($admin)->allowed;
    }
}
