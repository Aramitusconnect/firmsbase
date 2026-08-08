<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmUserRole;

/**
 * FirmSecurityActivityAccessPolicyService — role ceiling for the
 * firm-facing Security Activity page (Firm Feature Manifest §10/§11:
 * `SecurityEvent`-backed login history / support-access transparency).
 *
 * DELIBERATELY NOT a standard Laravel Policy class registered against
 * `App\Models\SecurityEvent` — mirrors `FirmSettingsAccessPolicyService`'s/
 * `FirmMembershipAccessPolicyService`'s own established precedent of
 * avoiding `Gate::authorize()`/`$user->can()` entirely for firm-panel
 * concerns that have no natural single-owner model instance to check
 * against (a firm's security event STREAM, not one record).
 *
 * VIEW — FirmOwner ONLY. Documented decision: this is the narrowest
 * ceiling available in this mission (matching
 * `FirmMembershipAccessPolicyService::MANAGE_ROLES`'s "who else can see/
 * change something this consequential" reasoning), chosen deliberately
 * over the broader "every active role" ceiling `FirmSettingsAccessPolicyService::
 * VIEW_ROLES` uses for ordinary firm configuration. Security posture
 * information — failed login attempts (which can reveal targeted
 * credential-stuffing against specific staff accounts), and even a
 * heavily summarized support-access/high-risk-change notice — is
 * meaningfully more sensitive than "what is our default currency", and
 * is the same class of "consequential visibility" this mission has
 * already gated FirmOwner-only elsewhere (Firm Team management, Firm
 * Settings MANAGE). A Paralegal/Receptionist/BillingStaff/LegalAssistant
 * has no operational need to see the firm's own login-failure/
 * support-access history; an Attorney was considered and deliberately
 * excluded too — unlike Firm Settings VIEW (pure informational
 * configuration every role benefits from knowing), security activity
 * is closer in kind to "who has access to the whole practice" than to
 * "what jurisdiction are we in", so it stays behind the same ceiling as
 * firm-wide membership management, not the broader settings-view
 * ceiling.
 *
 * No separate MANAGE method — this page is read-only by construction
 * (an audit trail is never edited from the firm panel; `SecurityEvent`
 * itself enforces append-only at the model/RLS layer regardless).
 */
class FirmSecurityActivityAccessPolicyService
{
    private const VIEW_ROLES = [
        FirmUserRole::FirmOwner,
    ];

    public function canView(FirmUserRole $role): bool
    {
        return in_array($role, self::VIEW_ROLES, true);
    }
}
