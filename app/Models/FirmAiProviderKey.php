<?php

namespace App\Models;

use App\Enums\AiProvider;
use App\Enums\AiProviderKeyStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FirmAiProviderKey — envelope-encrypted per-firm, per-provider API
 * key. `encrypted_key_ciphertext` is HIDDEN so raw/ciphertext key
 * material never leaks via ->toArray()/->toJson() (project rule 4 +
 * approved decision #4's "do not expose encrypted snapshot or key
 * ciphertext in model serialization" — this model applies that same
 * discipline to itself). The raw key is returned exactly once, at
 * AiProviderKeyService::generate()/rotate() call time, never persisted
 * or logged in plaintext (project rule 5). No uuid: internal secret
 * material, never externally addressed, same reasoning as
 * WebhookSecret/TenantEncryptionKey.
 */
class FirmAiProviderKey extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'firm_ai_provider_keys';

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'provider',
        'encrypted_key_ciphertext',
        'encryption_key_id',
        'status',
        'label',
        'last_used_at',
        'created_by',
        'rotated_at',
    ];

    protected $hidden = [
        'encrypted_key_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'status' => AiProviderKeyStatus::class,
            'last_used_at' => 'datetime',
            'rotated_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function encryptionKey(): BelongsTo
    {
        return $this->belongsTo(TenantEncryptionKey::class, 'encryption_key_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === AiProviderKeyStatus::Active;
    }
}
