<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;

/**
 * FirmPolicy — Phase 1 FirmsVault Admin Control Center. The second
 * standard Laravel Policy class in this codebase (the first,
 * App\Integrations\Policies\FirmIntegrationPolicy, targets the
 * firm-panel `User` model — see that class's own docblock). Deliberately
 * thin, mirroring FirmIntegrationPolicy's exact shape: every actual
 * role-check is delegated to PlatformStaffAccessPolicyService, never
 * inlined here.
 *
 * Guard-resolution note (flagged explicitly per this checkpoint's own
 * instruction not to silently guess on this point): this policy's
 * methods are typed against App\Models\PlatformAdmin — the correct actor
 * for the `platform_admin` guard, the ONLY guard that currently
 * authorizes against Firm/FirmUser instances anywhere in this codebase
 * (confirmed by search: no firm-panel/`web`-guard code path calls
 * Gate::authorize()/$user->can() against a Firm or FirmUser instance
 * today). However, Laravel's Gate::policy() registration
 * (PlatformAdminPolicyServiceProvider, alongside this class) is a
 * single GLOBAL mapping from model class to policy class — it is NOT
 * guard-scoped. Laravel's own default auto-discovery convention would
 * ALSO resolve `App\Models\Firm` -> `App\Policies\FirmPolicy` (and
 * `App\Models\FirmUser` -> `App\Policies\FirmUserPolicy`) automatically,
 * exactly like IntegrationServiceProvider's docblock describes for the
 * `App\Models\` case — so this registration is explicit for
 * documentation/consistency with that existing precedent, not because
 * auto-discovery would otherwise fail here.
 *
 * The open architectural question this leaves (deliberately NOT resolved
 * here, per the explicit instruction to document rather than guess): if
 * a FUTURE firm-panel (`web` guard, `App\Models\User` actor) code path
 * ever needs to authorize against a Firm or FirmUser instance, it would
 * resolve to THIS policy — whose methods are strictly typed to
 * PlatformAdmin — and raise a TypeError rather than silently
 * mis-authorizing (a fail-closed failure mode, not a fail-open one, but
 * still a real integration hazard worth a reviewer's attention before
 * any such firm-panel usage is added). Laravel/Filament have no
 * per-guard policy-registration mechanism to route around this
 * structurally; resolving it (e.g. a guard-instanceof discriminator
 * inside each method, or moving this policy under a
 * `Platform`-namespaced model wrapper) is left for whoever builds that
 * future firm-panel use case, since no such use case exists today to
 * design against.
 */
class FirmPolicy
{
    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
    ) {}

    /**
     * List-visibility gate for the new cross-firm Firms oversight list
     * (FirmResource). Same role ceiling as view() — there is no
     * per-instance distinction: a PlatformAdmin who may see the list may
     * see any individual firm in it (firms are not further scoped by
     * actor the way a firm-panel User's access to their OWN firm is).
     */
    public function viewAny(PlatformAdmin $admin): bool
    {
        return $this->accessPolicy->canAccessPlatformAdministration($admin)->allowed;
    }

    public function view(PlatformAdmin $admin, Firm $firm): bool
    {
        return $this->accessPolicy->canAccessPlatformAdministration($admin)->allowed;
    }

    /**
     * Reserved for a future mutating Action (e.g. suspend/reactivate a
     * firm) — FirmResource itself is List+View only in this checkpoint
     * (no Filament Edit/Create page, no mutating Action registered
     * against it yet), so this method has no live caller today. Included
     * now so that future Action has a ready-made, correctly-scoped
     * check to call rather than a new one being invented ad hoc at that
     * time, matching FirmIntegrationPolicy's own fuller (update/delete)
     * shape.
     */
    public function update(PlatformAdmin $admin, Firm $firm): bool
    {
        return $this->accessPolicy->canManageFirms($admin)->allowed;
    }
}
