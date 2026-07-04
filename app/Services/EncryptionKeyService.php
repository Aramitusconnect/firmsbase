<?php

namespace App\Services;

use App\Enums\TenantEncryptionKeyStatus;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * EncryptionKeyService — envelope encryption for per-firm data keys.
 * Inner key: a random 32-byte value per firm, per version. Outer
 * layer: the inner key encrypted at rest via Laravel's Crypt facade
 * (APP_KEY), stored in tenant_encryption_keys.encrypted_key.
 *
 * decrypt() is the only method that ever returns plaintext key
 * material, and never persists or logs it. Rotation creates a new
 * active key version and demotes the previous one to `rotated` — it
 * never deletes key rows. Destruction is a distinct, later operation
 * gated by a key_destruction_requests workflow that does not exist yet.
 */
class EncryptionKeyService
{
    private const KEY_BYTES = 32;

    public function provision(Firm $firm): TenantEncryptionKey
    {
        $existingActive = TenantEncryptionKey::query()
            ->where('firm_id', $firm->id)
            ->where('status', TenantEncryptionKeyStatus::Active)
            ->exists();

        if ($existingActive) {
            throw new \RuntimeException('Firm already has an active tenant encryption key. Use rotate() instead.');
        }

        return DB::transaction(function () use ($firm) {
            $nextVersion = ((int) TenantEncryptionKey::query()
                ->where('firm_id', $firm->id)
                ->max('key_version')) + 1;

            return TenantEncryptionKey::create([
                'firm_id' => $firm->id,
                'key_version' => $nextVersion,
                'status' => TenantEncryptionKeyStatus::Active,
                'encrypted_key' => $this->encryptNewKeyMaterial(),
            ]);
        });
    }

    /**
     * Rotate: demote the current active key to `rotated`, provision a
     * new active key at the next version, both in one transaction so a
     * firm is never left with zero active keys.
     */
    public function rotate(Firm $firm): TenantEncryptionKey
    {
        return DB::transaction(function () use ($firm) {
            $current = TenantEncryptionKey::query()
                ->where('firm_id', $firm->id)
                ->where('status', TenantEncryptionKeyStatus::Active)
                ->first();

            $current?->update(['status' => TenantEncryptionKeyStatus::Rotated]);

            $nextVersion = ((int) TenantEncryptionKey::query()
                ->where('firm_id', $firm->id)
                ->max('key_version')) + 1;

            return TenantEncryptionKey::create([
                'firm_id' => $firm->id,
                'key_version' => $nextVersion,
                'status' => TenantEncryptionKeyStatus::Active,
                'encrypted_key' => $this->encryptNewKeyMaterial(),
            ]);
        });
    }

    /**
     * Decrypt and return the firm's current active inner key, in
     * memory only. Throws if no active key exists.
     */
    public function decryptActiveKey(Firm $firm): string
    {
        $active = TenantEncryptionKey::query()
            ->where('firm_id', $firm->id)
            ->where('status', TenantEncryptionKeyStatus::Active)
            ->first();

        if (! $active) {
            throw new \RuntimeException("Firm {$firm->id} has no active tenant encryption key.");
        }

        return Crypt::decryptString($active->encrypted_key);
    }

    private function encryptNewKeyMaterial(): string
    {
        $innerKey = base64_encode(random_bytes(self::KEY_BYTES));

        return Crypt::encryptString($innerKey);
    }
}
