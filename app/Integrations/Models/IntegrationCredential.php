<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\IntegrationCredentialStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use Database\Factories\IntegrationCredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * IntegrationCredential — the actual credential material (OAuth tokens,
 * API keys) for a `firm_integrations` connection (Checkpoint 4,
 * checkpoint-00-final-specification.md §5/§10;
 * frozen-design-post-review.md). Direct firm-owned, split-table
 * convention proven by EmailAccount/EmailOAuthToken and
 * WebhookSubscription/WebhookSecret — `firm_integrations` itself carries
 * no credential-shaped column at all.
 *
 * No HasPublicUuid: internal secret material, never externally
 * addressed — matches EmailOAuthToken/WebhookSecret exactly (no `uuid`
 * column on this table).
 *
 * newFactory() override is REQUIRED (STANDING CONVENTION,
 * checkpoint-00-final-specification.md §6): the default
 * Model::resolveFactoryName() only special-cases the literal
 * `App\Models\` prefix, so a model under `App\Integrations\Models\`
 * would otherwise look for a nonexistent
 * `Database\Factories\Integrations\Models\IntegrationCredentialFactory`.
 *
 * `encrypted_payload_ciphertext` is $fillable directly (never a cast) —
 * encryption/decryption happens exclusively in
 * IntegrationCredentialService, never on this model. It is also
 * $hidden, so plaintext-shaped ciphertext never leaks via
 * ->toArray()/->toJson() (matching WebhookSecret/TenantEncryptionKey's
 * identical convention).
 *
 * Immutability guard (mirrors WebhookSecret::booted() exactly): once
 * created, `encrypted_payload_ciphertext`/`encryption_key_id` may never
 * be mutated on an existing row — rotation always creates a NEW row via
 * IntegrationCredentialService::store()/replace()/rotate(), never
 * mutates ciphertext in place. `status`/`rotated_at`/`revoked_at`/
 * `last_refreshed_at`/`refresh_failure_reason` remain freely updatable
 * (that is exactly what replace()/rotate()/revoke() do to the OLD row).
 *
 * The ONE deliberate, narrowly-scoped exception: WebhookSecret never
 * needed an escape hatch from its own identical guard because nothing
 * in its design ever legitimately mutates ciphertext on an existing row.
 * IntegrationCredentialService::reEncrypt() does need to — it re-wraps a
 * credential's SAME plaintext under whatever TenantEncryptionKey is
 * CURRENTLY active (a key-rotation maintenance operation on the SAME
 * logical credential, not a credential rotation), and must update the
 * SAME row in place rather than creating a new one (there is no new
 * "generation" of the credential to create — only its encryption
 * envelope changes). applyReEncryption() below is that narrow escape
 * hatch: it flips a private, instance-scoped flag for the duration of
 * exactly one update() call (always cleared in a finally block, even if
 * that update throws), so it can never mask a genuine attempt to mutate
 * ciphertext through any other path.
 */
class IntegrationCredential extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'integration_credentials';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'credential_type',
        'encrypted_payload_ciphertext',
        'encryption_key_id',
        'status',
        'granted_scopes_json',
        'expires_at',
        'masked_display_metadata',
        'webhook_routing_token',
        'rotated_at',
        'revoked_at',
        'last_refreshed_at',
        'refresh_failure_reason',
    ];

    protected $hidden = [
        'encrypted_payload_ciphertext',
    ];

    /**
     * Set ONLY by applyReEncryption() below, for the duration of exactly
     * one update() call. Never persisted, never part of $fillable/casts —
     * a purely in-memory, per-instance escape-hatch flag for the
     * immutability guard in booted().
     */
    private bool $allowCiphertextReEncryption = false;

    protected static function booted(): void
    {
        static::updating(function (self $credential): void {
            if ($credential->allowCiphertextReEncryption) {
                return;
            }

            if ($credential->isDirty(['encrypted_payload_ciphertext', 'encryption_key_id'])) {
                throw new LogicException(
                    'integration_credentials.encrypted_payload_ciphertext and encryption_key_id are immutable '.
                    'after creation. Rotate via IntegrationCredentialService::store()/replace()/rotate() instead, '.
                    'which always create a NEW row rather than mutating this one. The one deliberate exception is '.
                    'IntegrationCredentialService::reEncrypt(), a key-rotation maintenance operation that re-wraps '.
                    "this row's existing plaintext under the firm's currently active encryption key via ".
                    'IntegrationCredential::applyReEncryption() — the narrow, explicit escape hatch this guard '.
                    'permits, and the ONLY legitimate way to change these two columns on an existing row.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'credential_type' => CredentialType::class,
            'status' => IntegrationCredentialStatus::class,
            'granted_scopes_json' => 'array',
            'masked_display_metadata' => 'array',
            'expires_at' => 'datetime',
            'rotated_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): IntegrationCredentialFactory
    {
        return IntegrationCredentialFactory::new();
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function encryptionKey(): BelongsTo
    {
        return $this->belongsTo(TenantEncryptionKey::class, 'encryption_key_id');
    }

    public function isActive(): bool
    {
        return $this->status === IntegrationCredentialStatus::Active;
    }

    /**
     * The ONE deliberate, explicit exception to the immutability guard
     * in booted() above. Called ONLY by
     * IntegrationCredentialService::reEncrypt() — never by
     * store()/replace()/rotate()/revoke(), which must never mutate
     * ciphertext on an existing row. The flag is set only for the
     * duration of this single update() call and is always cleared
     * afterward (even if the update throws), so it can never leak into,
     * or mask, an update performed through any other path.
     */
    public function applyReEncryption(string $ciphertext, int $encryptionKeyId): bool
    {
        $this->allowCiphertextReEncryption = true;

        try {
            return $this->update([
                'encrypted_payload_ciphertext' => $ciphertext,
                'encryption_key_id' => $encryptionKeyId,
            ]);
        } finally {
            $this->allowCiphertextReEncryption = false;
        }
    }
}
