<?php

namespace App\Models;

use App\Enums\WebhookSecretStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WebhookSecret — no firm_id BelongsToTenant scope needed beyond the
 * direct firm_id column for query defense (scoped transitively through
 * webhook_subscription_id, defended by TenantSafeWebhookPolicyService —
 * same reasoning as EmailOAuthToken). No uuid — secret material, never
 * referenced externally. encrypted_secret_ciphertext is never cast to
 * plaintext by this model — only WebhookSecretService may decrypt it,
 * in memory, for the duration of a single call.
 *
 * Correction #13: old secrets are rotated, never deleted;
 * encrypted_secret_ciphertext/encryption_key_id are immutable after
 * creation — the booted() guard below throws if either is dirty on an
 * update, but permits status/rotated_at to change (that's exactly what
 * WebhookSecretService::rotate() does to the OLD row).
 */
class WebhookSecret extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'webhook_subscription_id',
        'encrypted_secret_ciphertext',
        'encryption_key_id',
        'status',
        'rotated_at',
    ];

    protected $hidden = [
        'encrypted_secret_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'status' => WebhookSecretStatus::class,
            'rotated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $secret) {
            if ($secret->isDirty(['encrypted_secret_ciphertext', 'encryption_key_id'])) {
                throw new \LogicException(
                    'webhook_secrets.encrypted_secret_ciphertext and encryption_key_id are immutable after creation. '.
                    'Rotate via WebhookSecretService::rotate() instead, which creates a new row.'
                );
            }
        });
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'webhook_subscription_id');
    }

    public function encryptionKey(): BelongsTo
    {
        return $this->belongsTo(TenantEncryptionKey::class, 'encryption_key_id');
    }

    public function isActive(): bool
    {
        return $this->status === WebhookSecretStatus::Active;
    }
}
