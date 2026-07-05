<?php

namespace App\Services;

use App\Enums\EmailVisibilityScope;
use App\Models\EmailMessage;
use App\Models\EmailVisibilityRule;
use App\Models\FirmUser;

/**
 * EmailVisibilityPolicyService — resolves who besides the connecting
 * firm user may see a captured message. A small, fixed-scope resolver,
 * not a generic per-user ACL/grant system (project rule). Resolution
 * order:
 *   1. A matter-specific email_visibility_rules row for a matter the
 *      message is linked to (via email_message_links), if one exists.
 *   2. The account-level default rule (matter_id null), if one exists.
 *   3. Hard-default OwnerOnly if no rule row exists at all — fail
 *      closed to the most restrictive option, never open.
 */
class EmailVisibilityPolicyService
{
    public function resolveScope(EmailMessage $message): EmailVisibilityScope
    {
        $linkedMatterIds = $message->links()->whereNotNull('matter_id')->pluck('matter_id');

        if ($linkedMatterIds->isNotEmpty()) {
            $matterRule = EmailVisibilityRule::query()
                ->where('email_account_id', $message->email_account_id)
                ->whereIn('matter_id', $linkedMatterIds)
                ->first();

            if ($matterRule) {
                return $matterRule->visibility_scope;
            }
        }

        $defaultRule = EmailVisibilityRule::query()
            ->where('email_account_id', $message->email_account_id)
            ->whereNull('matter_id')
            ->first();

        return $defaultRule?->visibility_scope ?? EmailVisibilityScope::OwnerOnly;
    }

    /**
     * Known limitation, documented rather than silently approximated:
     * MatterTeam currently resolves to "any firm user in the same
     * firm," the same as FirmWide, because no matter-team-membership
     * table exists yet in Phases 1-8 to check against. A stricter
     * "only attorneys/staff actually assigned to this matter" check is
     * a real gap this phase does not close — flagged for a later,
     * separately-approved phase once matter-team membership exists.
     */
    public function canView(EmailMessage $message, FirmUser $viewer): bool
    {
        if ($viewer->id === $message->emailAccount->connected_by_firm_user_id) {
            return true;
        }

        $scope = $this->resolveScope($message);

        return match ($scope) {
            EmailVisibilityScope::OwnerOnly => false,
            EmailVisibilityScope::MatterTeam, EmailVisibilityScope::FirmWide => $viewer->firm_id === $message->firm_id,
        };
    }
}
