<?php

namespace App\Models;

use App\Enums\PlatformRoleCode;
use App\Models\Concerns\HasPublicUuid;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

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
 * access — it deliberately checks nothing beyond is_active. No
 * role-based per-resource gating exists yet because no Filament
 * resources exist yet (app/Filament/ has no Resources/Pages beyond the
 * built-in Dashboard) — there is nothing narrower to gate against
 * today.
 */
class PlatformAdmin extends Authenticatable implements FilamentUser
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
}
