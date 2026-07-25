<?php

namespace App\Models;

use App\Enums\PlatformRoleCode;
use App\Models\Concerns\HasPublicUuid;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use SensitiveParameter;

/**
 * PlatformAdmin — a distinct identity from firm-facing `users`, for
 * platform-operations staff. Extends Authenticatable so it CAN be used
 * as a guardable model. Not tenant-owned — platform admins operate
 * across firms by design, so BelongsToTenant is intentionally not
 * applied here.
 *
 * Phase 7 addition: roles() — the platform_roles grant relation — and
 * hasRole(), a thin convenience wrapper. platform_admins itself gains
 * no new column; role state lives entirely in platform_roles (Phase 7
 * approved decision: platform_admins remains the sole platform-staff
 * identity table, never duplicated).
 *
 * Internal login/panel access wiring: the `platform_admin` guard
 * (config/auth.php) was registered specifically for this model, and
 * canAccessPanel() below is the ONLY gate on platform-admin panel
 * access — it deliberately checks nothing beyond is_active. Real
 * per-resource/per-action gating lives in Policy classes and
 * PlatformStaffAccessPolicyService; MFA enrollment/verification
 * enforcement lives in EnsurePlatformAdminMfaIsEnrolledAndVerified
 * (added to AdminPanelProvider::authMiddleware(), not here — this
 * method intentionally stays narrow so a locked (is_active=false)
 * account is denied panel access with no dependency on MFA state at
 * all).
 *
 * MFA design proposal §2: implements Filament's own
 * HasAppAuthentication/HasAppAuthenticationRecovery contracts (the
 * mechanism AuditedAppAuthentication/Filament's built-in
 * SetUpAppAuthenticationAction/DisableAppAuthenticationAction/etc. all
 * call), mapped directly onto the existing
 * two_factor_secret/two_factor_recovery_codes/two_factor_confirmed_at
 * columns — no new storage. `two_factor_confirmed_at` is stamped by
 * saveAppAuthenticationSecret() itself (now() whenever a non-null
 * secret is saved, null when the secret is cleared on disable) rather
 * than by a separate call site, because Filament's vendor Action
 * classes (SetUpAppAuthenticationAction::action(),
 * DisableAppAuthenticationAction::action()) are the ONLY callers of
 * saveSecret()/saveAppAuthenticationSecret() in the whole enroll/
 * disable lifecycle — hooking the model method itself is the one place
 * guaranteed to run on every real code path (including a future
 * AuditedAppAuthentication override, PlatformAdminMfaResetService, and
 * the emergency Artisan command, all of which also go through this
 * same saveAppAuthenticationSecret() rather than writing the column
 * directly) without needing to duplicate the stamping logic at each
 * call site.
 */
class PlatformAdmin extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use HasFactory, HasPublicUuid;

    protected $table = 'platform_admins';

    protected $fillable = [
        'name',
        'email',
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'two_factor_reset_at',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_reset_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Phase 7 additions below.
     */
    public function roles(): HasMany
    {
        return $this->hasMany(PlatformRole::class);
    }

    public function hasRole(PlatformRoleCode $role): bool
    {
        return $this->roles()
            ->where('role_code', $role->value)
            ->whereNull('revoked_at')
            ->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    /**
     * MFA design proposal §2 — Filament\Auth\MultiFactor\App\Contracts\
     * HasAppAuthentication.
     */
    public function getAppAuthenticationSecret(): ?string
    {
        return $this->two_factor_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => filled($secret) ? now() : null,
        ])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /**
     * MFA design proposal §2 — Filament\Auth\MultiFactor\App\Contracts\
     * HasAppAuthenticationRecovery.
     *
     * @return ?array<string>
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->two_factor_recovery_codes;
    }

    /**
     * @param  ?array<string>  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['two_factor_recovery_codes' => $codes])->save();
    }
}
