<?php

namespace App\Models;

use App\Enums\TenantEncryptionKeyStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenantEncryptionKey — envelope-encryption key material for one firm.
 * `encrypted_key` is the firm's inner data-key, itself encrypted at
 * rest via Laravel's Crypt facade (APP_KEY is the outer layer). This
 * model never decrypts the key itself — only EncryptionKeyService does
 * — so plaintext key material never leaks via ->toArray()/->toJson().
 * No uuid: internal key material, never public-facing.
 */
class TenantEncryptionKey extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'key_version',
        'status',
        'encrypted_key',
        'destroyed_at',
        'destruction_request_id',
    ];

    protected $hidden = [
        'encrypted_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantEncryptionKeyStatus::class,
            'destroyed_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function isActive(): bool
    {
        return $this->status === TenantEncryptionKeyStatus::Active;
    }
}
