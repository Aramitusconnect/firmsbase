<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmUserRole;

/**
 * FirmSupportAccessPolicyService — role ceiling for the firm-facing
 * Support Access page (Prompt 6): reviewing, approving and denying
 * platform support access requests into this firm, and revoking an
 * active support session.
 *
 * DELIBERATELY NOT a standard Laravel Policy class registered against
 * `App\Models\SupportAccessRequest` — mirrors
 * `FirmSecurityActivityAccessPolicyService`'s /
 * `FirmSettingsAccessPolicyService`'s / `FirmMembershipAccessPolicyService`'s
 * own established precedent of avoiding `Gate::authorize()`/`$user->can()`
 * entirely for firm-panel concerns whose subject is a firm-wide STREAM of
 * records rather than one naturally-owned model instance.
 *
 * FirmOwner ONLY — the same ceiling `FirmSecurityActivityAccessPolicyService`
 * already uses, and for the same reason, applied to the strictly more
 * consequential action. That page merely SHOWS a summarized notice that
 * support accessed the firm's data; this one decides whether platform
 * staff may enter the firm's data at all. Granting an outside party
 * time-limited access to the practice is the same class of "who has
 * access to the whole practice" decision this codebase already gates
 * FirmOwner-only for firm-wide membership management and firm security
 * activity — it is categorically not the broader "informational
 * configuration every role benefits from" ceiling
 * `FirmSettingsAccessPolicyService::VIEW_ROLES` uses.
 *
 * Attorney was considered and deliberately excluded, consistent with
 * `FirmSecurityActivityAccessPolicyService`'s own documented exclusion of
 * that role: consenting to platform access into client and matter data on
 * the firm's behalf is a firm-principal decision, not a practitioner one.
 *
 * VIEW and DECIDE deliberately share one ceiling rather than splitting
 * into a broader read gate and a narrower write gate (the read/manage
 * split `FirmSettingsAccessPolicyService` uses): the review screen's whole
 * purpose is to inform the approval decision, and it necessarily discloses
 * which platform staff member is asking and their operational reason —
 * information with no audience other than the person empowered to decide.
 */
class FirmSupportAccessPolicyService
{
    private const REVIEW_ROLES = [
        FirmUserRole::FirmOwner,
    ];

    /**
     * Whether this role may see the firm's support access requests,
     * active sessions and support history.
     */
    public function canReview(FirmUserRole $role): bool
    {
        return in_array($role, self::REVIEW_ROLES, true);
    }

    /**
     * Whether this role may approve or deny a pending request. Identical
     * ceiling to canReview() by design — see this class's own docblock.
     * Kept as a separate method so a future narrowing of either side does
     * not silently move the other.
     */
    public function canDecide(FirmUserRole $role): bool
    {
        return in_array($role, self::REVIEW_ROLES, true);
    }

    /**
     * Whether this role may revoke an active support session into this
     * firm before it expires on its own.
     */
    public function canRevoke(FirmUserRole $role): bool
    {
        return in_array($role, self::REVIEW_ROLES, true);
    }
}
