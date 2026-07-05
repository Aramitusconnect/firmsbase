<?php

namespace App\Models;

use App\Enums\ApiKeyStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ApiKey — covers both platform-internal and firm/customer API keys
 * via key_type (platform|firm). firm_id is nullable (null for
 * platform-type keys), which is exactly why this model does NOT use
 * BelongsToTenant (approved correction #10) — every other Phase 1-7
 * model carrying that trait has a non-nullable firm_id.
 *
 * hashed_secret is the ONLY persisted form of the secret. The raw
 * secret is generated and returned once by ApiKeyService::create()/
 * rotate() and is never stored on this model or anywhere else.
 */
class ApiKey extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'firm_id',
        'key_type',
        'name',
        'hashed_secret',
        'last_four',
        'status',
        'rate_limit_per_minute',
        'expires_at',
        'last_used_at',
        'rotated_from_id',
        'revoked_at',
        'revoked_reason',
        'created_by_firm_user_id',
        'created_by_platform_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApiKeyStatus::class,
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function rotatedFrom(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'rotated_from_id');
    }

    public function createdByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'created_by_firm_user_id');
    }

    public function createdByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by_platform_admin_id');
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(ApiKeyScope::class);
    }

    public function apiRequests(): HasMany
    {
        return $this->hasMany(ApiRequest::class);
    }

    public function isPlatformKey(): bool
    {
        return $this->key_type === 'platform';
    }

    public function isFirmKey(): bool
    {
        return $this->key_type === 'firm';
    }
}
