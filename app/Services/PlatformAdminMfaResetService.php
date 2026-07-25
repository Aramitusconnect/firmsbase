<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\MultiFactor\AuditedAppAuthentication;
use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\DB;

/**
 * PlatformAdminMfaResetService — FirmsVault Admin Control Center MFA
 * design proposal §8. The sole authorized path for clearing another
 * PlatformAdmin's MFA state — used by both
 * App\Filament\Actions\Platform\ResetPlatformAdminMfaAction (the
 * in-panel action) and the
 * platform-admin:emergency-mfa-reset Artisan command, so both call
 * sites share one code path, one audit event shape, and one
 * "re-enrollment falls out for free" guarantee.
 *
 * Clears MFA state via the EXACT SAME single code path Filament's own
 * DisableAppAuthenticationAction uses
 * (AppAuthentication::saveSecret()/saveRecoveryCodes()) — never writes
 * the two_factor_secret/two_factor_recovery_codes columns directly —
 * so AuditedAppAuthentication's own mfa_disabled/
 * mfa_recovery_codes_cleared audit hooks fire exactly as they would for
 * a genuine self-service disable. This method then additionally bumps
 * two_factor_reset_at and writes its own distinct mfa_reset_by_admin
 * event, attributed to the ACTING admin (not the target) — the
 * meaningful distinction between "this admin disabled their own MFA"
 * and "another SuperAdmin reset this admin's MFA for them".
 *
 * Deliberately does NOT require the target to currently be enrolled —
 * calling saveSecret()/saveRecoveryCodes() with null on an
 * already-null column is a harmless no-op at the model layer (and
 * still stamps two_factor_reset_at + writes the audit event), which
 * matters for the emergency-recovery use case: a SuperAdmin who lost
 * their device AND recovery codes may already be functionally
 * unenrolled from Filament's point of view by the time this runs.
 *
 * Last-SuperAdmin protection (PlatformRoleService::
 * wouldLeaveNoActiveSuperAdmin()) deliberately does NOT gate this
 * method — see ResetPlatformAdminMfaAction's own docblock for why: an
 * MFA reset never revokes a role or deactivates an account, so it must
 * remain unconditionally available even when the target is the sole
 * active SuperAdmin (per the design proposal's explicit §6 finding).
 */
class PlatformAdminMfaResetService
{
    private const CATEGORY = 'platform_admin_mfa';

    public function __construct(
        private readonly AuditedAppAuthentication $appAuthentication,
        private readonly PlatformAdminAuditEventRecorder $auditRecorder,
    ) {}

    public function reset(PlatformAdmin $actingSuperAdmin, PlatformAdmin $target, string $reason): void
    {
        DB::transaction(function () use ($actingSuperAdmin, $target, $reason): void {
            $this->appAuthentication->saveSecret($target, null);
            $this->appAuthentication->saveRecoveryCodes($target, null);

            $target->forceFill(['two_factor_reset_at' => now()])->save();

            $this->auditRecorder->recordPlatformEvent(
                $actingSuperAdmin,
                'mfa_reset_by_admin',
                self::CATEGORY,
                [
                    'target_platform_admin_id' => $target->id,
                    'target_platform_admin_uuid' => $target->uuid,
                    'reason' => $reason,
                ],
            );
        });
    }
}
