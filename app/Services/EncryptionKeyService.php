<?php

namespace App\Services;

use App\Enums\TenantEncryptionKeyStatus;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use Illuminate\Support\Facades\Crypt;

/**
 * EncryptionKeyService — envelope encryption for per-firm data keys.
 * Inner key: a random 32-byte value per firm, per version. Outer
 * layer: the inner key encrypted at rest via Laravel's Crypt facade
 * (APP_KEY), stored in tenant_encryption_keys.encrypted_key.
 *
 * decrypt() is the only method that ever returns plaintext key
 * material, and never persists or logs it. Rotation creates a new
 * active key version and demotes the previous one to `rotated` — it
 * never deletes key rows.
 *
 * Phase 17 addition: destroy() is the governed final step of
 * cryptographic offboarding (crypto-shredding), gated entirely by the
 * key_destruction_requests/key_destruction_approvals workflow
 * (KeyDestructionRequestService/KeyDestructionApprovalService/
 * KeyDestructionExecutionService) — this method itself performs no
 * clearance or approval checks; it is the LAST, irreversible action
 * those services call only after every check has already passed. It
 * destroys EVERY non-already-destroyed key version for the firm (not
 * only the active one), because a rotated key's encrypted_key value can
 * still decrypt historical ciphertext (e.g. old WebhookSecret/
 * AiApprovalRequest rows) — "renders residual encrypted data
 * unreadable" only holds if every version is destroyed. It never
 * deletes the tenant_encryption_keys rows themselves (governance
 * evidence and FK targets survive); it only irreversibly overwrites
 * encrypted_key with a non-decryptable tombstone value and flips status
 * to Destroyed. A firm is never bricked by this: provision() only
 * refuses when an Active key already exists, so a firm with zero Active
 * keys (post-destruction) can always be issued a fresh key later if a
 * future governed reason required it.
 */
class EncryptionKeyService
{
    private const KEY_BYTES = 32;

    /**
     * Section 39A-3L, Checkpoint 16: every method below wraps its own
     * DB access in runWithFirmContext($firm->id, ...), replacing the
     * plain DB::transaction() calls that used to establish only an
     * atomicity boundary, not a tenant-context one — under FORCE RLS,
     * tenant_encryption_keys reads/writes fail closed without it.
     * runWithFirmContext() already opens its own internal
     * DB::transaction(), so no method here wraps in both.
     */
    public function provision(Firm $firm): TenantEncryptionKey
    {
        return (new TenantContextService())->runWithFirmContext($firm->id, function () use ($firm) {
            $existingActive = TenantEncryptionKey::query()
                ->where('firm_id', $firm->id)
                ->where('status', TenantEncryptionKeyStatus::Active)
                ->exists();

            if ($existingActive) {
                throw new \RuntimeException('Firm already has an active tenant encryption key. Use rotate() instead.');
            }

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
        return (new TenantContextService())->runWithFirmContext($firm->id, function () use ($firm) {
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
     * memory only. Throws if no active key exists. Only the DB lookup
     * is wrapped — Crypt::decryptString() touches no database table, so
     * it deliberately runs outside the wrap (nothing further to protect
     * once the row is already in hand).
     */
    public function decryptActiveKey(Firm $firm): string
    {
        $active = (new TenantContextService())->runWithFirmContext(
            $firm->id,
            fn () => TenantEncryptionKey::query()
                ->where('firm_id', $firm->id)
                ->where('status', TenantEncryptionKeyStatus::Active)
                ->first()
        );

        if (! $active) {
            throw new \RuntimeException("Firm {$firm->id} has no active tenant encryption key.");
        }

        return Crypt::decryptString($active->encrypted_key);
    }

    /**
     * Governed, irreversible crypto-shredding step. Callers are
     * KeyDestructionExecutionService only — this method assumes every
     * clearance/approval check has already passed and performs none
     * itself. Only affects the target firm's own key rows (scoped by
     * firm_id); never touches another firm's keys.
     *
     * @return int number of key versions destroyed
     */
    public function destroy(Firm $firm, ?int $keyDestructionRequestId = null): int
    {
        return (new TenantContextService())->runWithFirmContext($firm->id, function () use ($firm, $keyDestructionRequestId) {
            $keys = TenantEncryptionKey::query()
                ->where('firm_id', $firm->id)
                ->where('status', '!=', TenantEncryptionKeyStatus::Destroyed->value)
                ->get();

            if ($keys->isEmpty()) {
                throw new \RuntimeException("Firm {$firm->id} has no non-destroyed encryption keys to destroy.");
            }

            foreach ($keys as $key) {
                $key->update([
                    'status' => TenantEncryptionKeyStatus::Destroyed,
                    'destroyed_at' => now(),
                    'destruction_request_id' => $keyDestructionRequestId,
                    'encrypted_key' => $this->tombstoneValue(),
                ]);
            }

            return $keys->count();
        });
    }

    private function encryptNewKeyMaterial(): string
    {
        $innerKey = base64_encode(random_bytes(self::KEY_BYTES));

        return Crypt::encryptString($innerKey);
    }

    /**
     * A fixed-format, deliberately NOT-a-valid-Laravel-encrypted-payload
     * string. Crypt::decryptString() throws a DecryptException against
     * it unconditionally — this is what makes destruction irreversible
     * and what proves "sampled encrypted data becomes unreadable" in
     * tests: any ciphertext that depended on the destroyed inner key can
     * no longer be recovered, because the inner key itself is gone.
     */
    private function tombstoneValue(): string
    {
        return 'destroyed::'.bin2hex(random_bytes(16));
    }
}
