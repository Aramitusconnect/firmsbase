<?php

namespace App\Models;

use App\Enums\PlatformRoleCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PlatformRole — a grant/assignment row over the fixed PlatformRoleCode
 * enum. No uuid (looked up only via platform_admin_id + role_code, not
 * addressed individually — see migration doc comment). Not tenant-owned,
 * no BelongsToTenant.
 */
class PlatformRole extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_admin_id',
        'role_code',
        'granted_by',
        'granted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'role_code' => PlatformRoleCode::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function platformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'granted_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
