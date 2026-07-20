<?php

declare(strict_types=1);

namespace App\Integrations\Policies;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Models\User;

/**
 * FirmIntegrationPolicy — the FIRST standard Laravel Policy class
 * introduced anywhere in this codebase (resolution of Stage A's open
 * question #6, checkpoint-00-final-specification.md §17: this
 * codebase has zero `app/Policies` usage anywhere prior to this
 * checkpoint). Deliberately thin — every actual role-check is
 * delegated to IntegrationAccessPolicyService (the non-financial
 * tier), mirroring this codebase's existing convention of keeping
 * authorization logic in dedicated service classes
 * (TrustAccessPolicyService, WebhookAccessPolicyService) rather than
 * inline in the policy/controller layer.
 *
 * Laravel resolves policies against the authenticated Authenticatable
 * (App\Models\User for the `web`/firm-panel guard here), never
 * directly against App\Models\FirmUser (which carries the role but is
 * a firm-membership row, not the guard's user model) — so every
 * method here first bridges User -> its active FirmUser via
 * User::activeFirmUser() (the existing, real bridge already used by
 * User::canAccessPanel() and AppServiceProvider's login-audit
 * listener), then delegates the actual role check to
 * IntegrationAccessPolicyService.
 *
 * Every method also independently re-confirms the resolved FirmUser's
 * firm_id matches the target FirmIntegration row's firm_id before
 * authorizing anything model-instance-scoped — this is a defense-in-
 * depth authorization-layer check, NOT a substitute for
 * firm_integrations' own FORCE RLS policy (which remains the actual
 * tenant-isolation boundary regardless of what this policy decides).
 *
 * Standard Laravel method names (view/create/update/delete) are
 * implemented as the primary methods Filament/Gate::authorize() will
 * resolve by convention; connect/configure/disconnect are explicit,
 * semantically-named aliases for the exact same checks, matching the
 * vocabulary used throughout checkpoint-00-final-specification.md §6
 * and architecture.md §4 ("connect/configure/disconnect/sync/view").
 *
 * This checkpoint covers only the non-financial tier. No financial
 * provider is registered in this mission (see
 * FinancialIntegrationAccessPolicyService's own docblock) — a future,
 * separately-authorized checkpoint would introduce a distinct
 * financial-tier policy class rather than branching this one.
 */
class FirmIntegrationPolicy
{
    public function __construct(
        private readonly IntegrationAccessPolicyService $accessPolicy,
    ) {
    }

    /**
     * List-visibility gate (e.g. a future Firm panel "Integrations"
     * index) — same role ceiling as view(), since there is no
     * meaningful distinction between "may see the list" and "may see a
     * given row" for this table.
     */
    public function viewAny(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canView($firmUser->role);
    }

    public function view(User $user, FirmIntegration $firmIntegration): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && $firmUser->firm_id === $firmIntegration->firm_id
            && $this->accessPolicy->canView($firmUser->role);
    }

    /**
     * Standard Laravel name for the "connect a new provider" action.
     */
    public function create(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canConnect($firmUser->role);
    }

    public function connect(User $user): bool
    {
        return $this->create($user);
    }

    /**
     * Standard Laravel name for the "configure an existing connection"
     * action (e.g. editing display_label, scopes, or re-authorizing).
     */
    public function update(User $user, FirmIntegration $firmIntegration): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && $firmUser->firm_id === $firmIntegration->firm_id
            && $this->accessPolicy->canConfigure($firmUser->role);
    }

    public function configure(User $user, FirmIntegration $firmIntegration): bool
    {
        return $this->update($user, $firmIntegration);
    }

    /**
     * Standard Laravel name for the "disconnect" action. Deliberately
     * never a hard delete of the row (retention indefinite per
     * domain-model-and-rls-classification.md §2 — "status-flip, never
     * delete") — this method only governs whether the actor MAY
     * initiate a disconnect; the disconnect operation itself belongs to
     * a future connection-lifecycle service (Checkpoint 4+), not this
     * checkpoint.
     */
    public function delete(User $user, FirmIntegration $firmIntegration): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && $firmUser->firm_id === $firmIntegration->firm_id
            && $this->accessPolicy->canDisconnect($firmUser->role);
    }

    public function disconnect(User $user, FirmIntegration $firmIntegration): bool
    {
        return $this->delete($user, $firmIntegration);
    }
}
