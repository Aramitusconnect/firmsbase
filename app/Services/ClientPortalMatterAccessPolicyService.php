<?php

namespace App\Services;

use App\Models\ClientPortalMatterGrant;
use App\Models\ClientPortalUser;
use App\Models\Matter;

/**
 * ClientPortalMatterAccessPolicyService — Checkpoint 4 ("Plaid
 * financial evidence add-on"), Client Portal authentication foundation
 * (checkpoint4-combined-design.md §5;
 * checkpoint4-design-matter-and-client-portal.md §2.5). Modeled
 * directly on `MatterAccessPolicyService`'s own shape (a single
 * `canAccessMatter()` boundary method, called from both a list-page UX
 * filter and a per-record resolve-step boundary) but keyed on explicit
 * `client_portal_matter_grants` rows instead of `MatterAssignment` —
 * the Client Portal's own, narrower, "must be explicitly assigned"
 * authorization concept (§2.6 point 3 of the source design doc; §2.7.f
 * flags this as the one place the design adds a genuinely new
 * authorization concept rather than purely reusing an existing one).
 *
 * This is the "direct-route authorization" boundary the directive
 * requires: every Client Portal page's record resolution re-checks this
 * service — never trusts a list-page query filter alone as the real
 * boundary, matching the identical "list is UX filter, resolve step is
 * the boundary" split `MatterResource`/`ViewMatter` already establish
 * for the Firm panel.
 */
class ClientPortalMatterAccessPolicyService
{
    public function canAccessMatter(ClientPortalUser $portalUser, Matter $matter): bool
    {
        if ((int) $portalUser->client_id === 0) {
            return false;
        }

        return ClientPortalMatterGrant::query()
            ->where('client_id', $portalUser->client_id)
            ->where('matter_id', $matter->id)
            ->where('firm_id', $matter->firm_id)
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * Query-level UX filter for a Client Portal matter list — the same
     * non-boundary/boundary split `MatterResource::getEloquentQuery()`
     * draws relative to `ViewMatter::resolveRecord()`'s real check
     * above. Not itself the security boundary.
     */
    public function grantedMatterIds(ClientPortalUser $portalUser): array
    {
        return ClientPortalMatterGrant::query()
            ->where('client_id', $portalUser->client_id)
            ->whereNull('revoked_at')
            ->pluck('matter_id')
            ->all();
    }
}
