<?php

namespace App\Services;

use App\Enums\FirmUserRole;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Models\User;

/**
 * MatterAccessPolicyService — NEW in Phase 15. No existing service in
 * the codebase enforced matter-level (as opposed to firm-level) user
 * authorization before this phase; MatterAssignment (Phase 2) existed
 * as a data model but was never queried by any access check. Built on
 * approved decision #3:
 *
 *   - FirmOwner and Attorney: access every matter within their own
 *     firm, regardless of MatterAssignment.
 *   - Paralegal, LegalAssistant, Receptionist, BillingStaff: access
 *     only matters where they hold an ACTIVE MatterAssignment
 *     (removed_at IS NULL). A removed assignment does not count.
 *   - No user may access another firm's matter, regardless of role.
 *
 * This service is the single place AiRetrievalIsolationService (and
 * any future retrieval/AI caller) asks "can this user open this
 * matter" — project rules 9/15: matter-level permission must be
 * enforced at retrieval time.
 */
class MatterAccessPolicyService
{
    private const BLANKET_ACCESS_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
    ];

    public function canAccessMatter(User $user, Matter $matter): bool
    {
        $firmUser = (new TenantContextService())->runWithFirmContext(
            $matter->firm_id,
            fn () => FirmUser::query()
                ->where('user_id', $user->id)
                ->where('firm_id', $matter->firm_id)
                ->first(),
        );

        if (! $firmUser) {
            // No firm_users row for this user in the matter's firm —
            // either a different firm's user, or not staff at all.
            return false;
        }

        if (in_array($firmUser->role, self::BLANKET_ACCESS_ROLES, true)) {
            return true;
        }

        return MatterAssignment::query()
            ->where('matter_id', $matter->id)
            ->where('user_id', $user->id)
            ->whereNull('removed_at')
            ->exists();
    }

    /**
     * Cross-matter retrieval requires access to EVERY matter involved
     * (project rule 16) — not just one of them.
     *
     * @param  array<Matter>  $matters
     */
    public function canAccessAllMatters(User $user, array $matters): bool
    {
        foreach ($matters as $matter) {
            if (! $this->canAccessMatter($user, $matter)) {
                return false;
            }
        }

        return true;
    }
}
