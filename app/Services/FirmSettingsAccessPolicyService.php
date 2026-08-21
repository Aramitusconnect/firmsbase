<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmUserRole;

/**
 * FirmSettingsAccessPolicyService — role ceiling for the firm-facing
 * "Firm Settings" cluster (Firm Feature Manifest §13: the firm's own
 * profile/address/phone fields on `Firm`, plus `FirmSettings.
 * default_language`/`state_jurisdiction`).
 *
 * DELIBERATELY NOT a standard Laravel Policy class registered against
 * `App\Models\Firm`/`App\Models\FirmSettings`: `Firm` already has an
 * explicit global `Gate::policy(Firm::class, FirmPolicy::class)`
 * registration (`PlatformAdminPolicyServiceProvider`), strictly typed to
 * `App\Models\PlatformAdmin` — exactly the same "future firm-panel
 * (`web` guard, `App\Models\User` actor) collision" hazard
 * `FirmMembershipAccessPolicyService`'s own docblock already documents
 * and resolves for `FirmUser`/`FirmUserPolicy`. This service exists so
 * `FirmSettingsPage` never calls `Gate::authorize()`/`$user->can()`
 * against a `Firm` instance at all, mirroring that same precedent.
 *
 * Role ceilings, and the reasoning behind each:
 *
 *   - VIEW (see the firm's own profile/address/phone/settings, plus the
 *     read-only display of payment_mode/trust_iolta_protection/
 *     ai_mode): every active staff role, all 6 `FirmUserRole` cases —
 *     matches `FirmMembershipAccessPolicyService::VIEW_ROLES`'s "nothing
 *     here is confidential-by-role" reasoning. Every firm user
 *     reasonably needs to know the firm's own configured jurisdiction,
 *     language, and payment/trust/AI posture to do their job; none of
 *     it is a per-person secret.
 *
 *   - MANAGE (edit and save any field on this page): FirmOwner ONLY.
 *     Documented decision: this page is firm-WIDE configuration — legal
 *     name, registered address, default currency/timezone/jurisdiction
 *     — not a single record an Attorney or Paralegal owns. Matches
 *     `FirmMembershipAccessPolicyService::MANAGE_ROLES`'s "only the
 *     firm's own Owner(s)" reasoning for the analogous "who else can
 *     change firm-wide state" question in the Firm Team cluster.
 *
 * Deliberately has NO method for payment_mode/trust_iolta_protection/
 * ai_mode/client_2fa_mode — none of those four columns is ever
 * submittable through this page (see `FirmSettingsPage`'s own
 * docblock): payment_mode/trust_iolta_protection/ai_mode are READ-ONLY
 * display text here (manifest §13: "have real downstream effects on
 * other gated services... likely belong behind a stricter gate" —
 * changing them is a platform-support operation, not a self-service
 * one, and is out of this task's scope entirely); client_2fa_mode is
 * excluded from this page ENTIRELY (Client Portal has no equivalent
 * enrollment-safety work yet — toggling it to Required today, with no
 * enrollment/recovery UI, would permanently lock clients out).
 *
 * firm_user_2fa_mode (SET-002, Non-Payment Completion Program) IS now
 * submittable through this page, gated by the same MANAGE ceiling as
 * every other field here — no dedicated method was added for it,
 * since the enrollment-safety concern that justified excluding it no
 * longer applies (Mission 1C's safe redirect-not-lockout enforcement,
 * plus the platform-minimum MFA floor FirmUser2faPolicyService now
 * applies to FirmOwner/Attorney regardless of this setting).
 */
class FirmSettingsAccessPolicyService
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

    public function canManage(FirmUserRole $role): bool
    {
        return in_array($role, self::MANAGE_ROLES, true);
    }
}
