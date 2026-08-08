<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmUserRole;

/**
 * FirmMembershipAccessPolicyService — role ceiling for the firm-facing
 * "Firm Team / Access" cluster (Firm Feature Manifest §12: invite/
 * suspend/reactivate/remove another team member).
 *
 * DELIBERATELY NOT a standard Laravel Policy class registered against
 * `App\Models\FirmUser`: that model class already has an explicit
 * global `Gate::policy(FirmUser::class, FirmUserPolicy::class)`
 * registration (`PlatformAdminPolicyServiceProvider`), whose methods are
 * strictly typed to `App\Models\PlatformAdmin`. `Gate::policy()` is a
 * single mapping per model class, not scoped by auth guard — see that
 * policy's own docblock, and `FirmPolicy`'s own docblock, both of which
 * explicitly flag this exact scenario ("if a FUTURE firm-panel (`web`
 * guard, `App\Models\User` actor) code path ever needs to authorize
 * against a Firm or FirmUser instance, it would resolve to THIS policy
 * — whose methods are strictly typed to PlatformAdmin — and raise a
 * TypeError rather than silently mis-authorizing"). Confirmed directly
 * against Laravel's own Gate source
 * (`Illuminate\Auth\Access\Gate::canBeCalledWithUser()`): it only
 * short-circuits for a NULL user (guest), never for a type mismatch — a
 * non-null `App\Models\User` actor would genuinely reach
 * `FirmUserPolicy::viewAny(PlatformAdmin $admin)` and fatal with a
 * TypeError. This service exists specifically so the new firm-panel
 * `FirmUserResource` never calls `Gate::authorize()`/`$user->can()`
 * against a `FirmUser` instance at all — every authorization check for
 * that resource goes through this service directly instead, mirroring
 * the established `*AccessPolicyService` convention used throughout
 * this mission (e.g. `ClientCrmAccessPolicyService`,
 * `DocumentRequestAccessPolicyService`) for exactly this "thin,
 * role-ceiling-only, no Eloquent Policy class" shape.
 *
 * Role ceilings, and the reasoning behind each:
 *
 *   - VIEW (list/view the firm's own team roster): every active staff
 *     role, all 6 `FirmUserRole` cases — matches
 *     `ClientCrmAccessPolicyService::VIEW_ROLES`'s "nothing here is
 *     confidential-by-role" reasoning. Knowing who else works at the
 *     firm, and their role/status, is not sensitive the way actually
 *     changing someone's access is.
 *
 *   - MANAGE (invite a new team member; suspend/reactivate/remove an
 *     existing one): FirmOwner ONLY. Documented decision (per this
 *     mission's own instruction not to silently assume): granting or
 *     revoking another person's access to the firm's entire practice —
 *     including, at the extreme, another Attorney's or even another
 *     FirmOwner's own membership — is the single most consequential
 *     action in this cluster, more so than any Trust/Billing action
 *     this codebase already gates narrowly (TrustAccessPolicyService's
 *     "Approve: FirmOwner, Attorney only"). An Attorney is not given
 *     this ability precisely because they should never be able to
 *     suspend or remove another Attorney (or a FirmOwner) unilaterally
 *     — only the firm's own Owner(s) manage who is on the team. The
 *     `LastFirmOwnerRemovalException` guard in
 *     `FirmUserInvitationService` (never allow removing/suspending the
 *     LAST active FirmOwner) is a second, independent safety layer on
 *     top of this role ceiling, not a substitute for it.
 */
class FirmMembershipAccessPolicyService
{
    private const VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
        FirmUserRole::Receptionist,
        FirmUserRole::BillingStaff,
    ];

    private const MANAGE_ROLES = [
        FirmUserRole::FirmOwner,
    ];

    public function canView(FirmUserRole $role): bool
    {
        return in_array($role, self::VIEW_ROLES, true);
    }

    public function canManageMembers(FirmUserRole $role): bool
    {
        return in_array($role, self::MANAGE_ROLES, true);
    }
}
