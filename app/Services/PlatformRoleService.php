<?php

namespace App\Services;

use App\Enums\PlatformRoleCode;
use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use Illuminate\Support\Facades\DB;

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

    /**
     * Platform Administrators resource addition. Last-SuperAdmin
     * protection — confirmed absent anywhere in this repo before this
     * change. Answers: "if $admin's SuperAdmin role were revoked (or,
     * when $roleBeingRevoked is null, if $admin were deactivated
     * outright) right now, would zero PlatformAdmins remain with an
     * active SuperAdmin role AND is_active = true?"
     *
     * $roleBeingRevoked distinguishes the two call shapes this guards:
     *  - null — a deactivation check (ResetPlatformAdminMfaAction is
     *    explicitly NOT one of these callers — see its own docblock;
     *    an MFA reset revokes no role and deactivates no account, so it
     *    never asks this question at all).
     *  - PlatformRoleCode::SuperAdmin — a role-revocation check
     *    specifically for the SuperAdmin role.
     *  - any OTHER PlatformRoleCode — short-circuits to false
     *    immediately: revoking a non-SuperAdmin role can never affect
     *    SuperAdmin coverage, so this is never a real risk and the
     *    caller should never block on it.
     *
     * $admin is excluded from the "remaining" count whenever the
     * question is actually evaluated — but the question is only
     * evaluated at all when $admin CURRENTLY counts as an active
     * SuperAdmin themselves (is_active = true AND an unrevoked
     * SuperAdmin grant). This second short-circuit matters just as much
     * as the role-mismatch one above: without it, deactivating an
     * admin who never held SuperAdmin in a system that ALREADY happens
     * to have zero active SuperAdmins (a pre-existing broken state,
     * not caused by this action) would incorrectly be reported as
     * "would leave no active SuperAdmin" — this method must answer
     * whether THIS action is what causes that outcome, not merely
     * whether that outcome would be true afterward regardless of cause.
     */
    public function wouldLeaveNoActiveSuperAdmin(PlatformAdmin $admin, ?PlatformRoleCode $roleBeingRevoked = null): bool
    {
        if ($roleBeingRevoked !== null && $roleBeingRevoked !== PlatformRoleCode::SuperAdmin) {
            return false;
        }

        $adminCurrentlyCountsAsActiveSuperAdmin = $admin->is_active
            && PlatformRole::query()
                ->where('platform_admin_id', $admin->id)
                ->where('role_code', PlatformRoleCode::SuperAdmin->value)
                ->whereNull('revoked_at')
                ->exists();

        if (! $adminCurrentlyCountsAsActiveSuperAdmin) {
            return false;
        }

        $hasRemainingActiveSuperAdmin = PlatformAdmin::query()
            ->where('platform_admins.id', '!=', $admin->id)
            ->where('platform_admins.is_active', true)
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('platform_roles')
                    ->whereColumn('platform_roles.platform_admin_id', 'platform_admins.id')
                    ->where('platform_roles.role_code', PlatformRoleCode::SuperAdmin->value)
                    ->whereNull('platform_roles.revoked_at');
            })
            ->exists();

        return ! $hasRemainingActiveSuperAdmin;
    }
}
