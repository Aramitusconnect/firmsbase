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
 *
 * Phase 17 addition: destructionRequest() links this row to the
 * key_destruction_requests row that (irreversibly) destroyed it, via
 * the existing, previously-unused destruction_request_id column (Phase
 * 1 pre-provisioned this exact column and the Destroyed status case for
 * this purpose — see EncryptionKeyService::destroy()). No FK constraint
 * exists on destruction_request_id at the database layer (documented in
 * the tenant_encryption_keys migration, unchanged in Phase 17) since
 * key_destruction_requests did not exist until now and adding one would
 * require altering a protected existing migration; the relation below
 * is an application-layer convenience only.
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

    public function destructionRequest(): BelongsTo
    {
        return $this->belongsTo(KeyDestructionRequest::class, 'destruction_request_id');
    }

    public function isActive(): bool
    {
        return $this->status === TenantEncryptionKeyStatus::Active;
    }

    public function isDestroyed(): bool
    {
        return $this->status === TenantEncryptionKeyStatus::Destroyed;
    }
}
