<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * PlatformAdmin — a distinct identity from firm-facing `users`, for
 * platform-operations staff. Extends Authenticatable so it CAN be used
 * as a guardable model, but no auth guard is registered for it in
 * Phase 1 (config/auth.php is a frozen file — guard registration is a
 * later, explicitly-approved change). Not tenant-owned — platform
 * admins operate across firms by design, so BelongsToTenant is
 * intentionally not applied here.
 */
class PlatformAdmin extends Authenticatable
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
}
