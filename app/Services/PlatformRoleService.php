<?php

namespace App\Services;

use App\Enums\PlatformRoleCode;
use App\Models\PlatformAdmin;
use App\Models\PlatformRole;

/**
 * PlatformRoleService — the only writer of platform_roles. platform_admins
 * remains the sole platform-staff identity table (Phase 1); this service
 * never creates a second identity table. Grants are idempotent: granting
 * an already-active role returns the existing row rather than creating a
 * duplicate active grant.
 */
class PlatformRoleService
{
    public function grant(PlatformAdmin $admin, PlatformRoleCode $role, ?PlatformAdmin $grantedBy = null): PlatformRole
    {
        $existing = PlatformRole::query()
            ->where('platform_admin_id', $admin->id)
            ->where('role_code', $role->value)
            ->whereNull('revoked_at')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return PlatformRole::create([
            'platform_admin_id' => $admin->id,
            'role_code' => $role,
            'granted_by' => $grantedBy?->id,
            'granted_at' => now(),
        ]);
    }

    public function revoke(PlatformAdmin $admin, PlatformRoleCode $role): void
    {
        PlatformRole::query()
            ->where('platform_admin_id', $admin->id)
            ->where('role_code', $role->value)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function hasRole(PlatformAdmin $admin, PlatformRoleCode $role): bool
    {
        return PlatformRole::query()
            ->where('platform_admin_id', $admin->id)
            ->where('role_code', $role->value)
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * @return PlatformRoleCode[]
     */
    public function activeRolesFor(PlatformAdmin $admin): array
    {
        return PlatformRole::query()
            ->where('platform_admin_id', $admin->id)
            ->whereNull('revoked_at')
            ->get()
            ->map(fn (PlatformRole $role) => $role->role_code)
            ->all();
    }
}
