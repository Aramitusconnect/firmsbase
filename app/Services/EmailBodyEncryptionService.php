<?php

namespace App\Services;

use App\Enums\TenantEncryptionKeyStatus;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\ValueObjects\EmailBodyEncryptionResult;
use Illuminate\Encryption\Encrypter;

/**
 * EmailBodyEncryptionService — the ONLY place arbitrary per-firm
 * sensitive strings (email bodies AND OAuth token material — this
 * service is used by both EmailSyncService and EmailOAuthTokenService)
 * are encrypted or decrypted for this module. Wraps the EXISTING Phase
 * 1 EncryptionKeyService/TenantEncryptionKey envelope-encryption
 * primitive — no second encryption system is introduced. That service
 * exposes decryptActiveKey() to obtain the firm's inner data-key in
 * memory; this class then uses Laravel's own Encrypter (keyed with
 * that per-firm inner key, not APP_KEY) to actually encrypt/decrypt
 * the string content itself.
 *
 * Fails closed: if the firm has no active TenantEncryptionKey,
 * encrypt() returns a failure result rather than storing plaintext
 * anywhere, and callers MUST NOT write a body/token column when
 * succeeded is false.
 *
 * Known limitation, documented rather than silently worked around:
 * EncryptionKeyService exposes no way to decrypt with a specific,
 * no-longer-active key version — only decryptActiveKey() (the CURRENT
 * active key). If a firm has rotated its key since a message/token was
 * encrypted, decrypt() throws a clear error instead of silently
 * returning wrong bytes. This is a real gap in the existing service,
 * not something this phase is authorized to fix (modifying Phase 1
 * services requires separate approval).
 */
class EmailBodyEncryptionService
{
    private const CIPHER = 'aes-256-cbc';

    public function __construct(private readonly EncryptionKeyService $encryptionKeyService)
    {
    }

    public function encrypt(Firm $firm, string $plaintext): EmailBodyEncryptionResult
    {
        $activeKey = TenantEncryptionKey::query()
            ->where('firm_id', $firm->id)
            ->where('status', TenantEncryptionKeyStatus::Active)
            ->first();

        if (! $activeKey) {
            return EmailBodyEncryptionResult::failure("firm {$firm->id} has no active tenant encryption key");
        }

        $innerKey = base64_decode($this->encryptionKeyService->decryptActiveKey($firm), true);

        if ($innerKey === false || strlen($innerKey) !== 32) {
            return EmailBodyEncryptionResult::failure('tenant encryption key material is invalid');
        }

        $ciphertext = (new Encrypter($innerKey, self::CIPHER))->encryptString($plaintext);

        return EmailBodyEncryptionResult::success($ciphertext, $activeKey->id);
    }

    public function decrypt(Firm $firm, string $ciphertext, int $encryptionKeyId): string
    {
        $key = TenantEncryptionKey::query()
            ->where('id', $encryptionKeyId)
            ->where('firm_id', $firm->id)
            ->first();

        if (! $key) {
            throw new \RuntimeException("Encryption key {$encryptionKeyId} does not belong to firm {$firm->id}.");
        }

        if ($key->status !== TenantEncryptionKeyStatus::Active) {
            throw new \RuntimeException(
                "Cannot decrypt: encryption key version {$encryptionKeyId} is no longer the active key ".
                "(status={$key->status->value}). EncryptionKeyService does not support decrypting a specific ".
                'rotated key version in this phase.'
            );
        }

        $innerKey = base64_decode($this->encryptionKeyService->decryptActiveKey($firm), true);

        if ($innerKey === false || strlen($innerKey) !== 32) {
            throw new \RuntimeException('tenant encryption key material is invalid');
        }

        return (new Encrypter($innerKey, self::CIPHER))->decryptString($ciphertext);
    }
}
