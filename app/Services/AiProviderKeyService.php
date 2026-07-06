<?php

namespace App\Services;

use App\Enums\AiProvider;
use App\Enums\AiProviderKeyStatus;
use App\Models\Firm;
use App\Models\FirmAiProviderKey;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * AiProviderKeyService — the only writer of firm_ai_provider_keys.
 * Reuses EmailBodyEncryptionService EXACTLY as-is (same chain
 * WebhookSecretService already reuses for webhook secrets) — no
 * second encryption system. generate()/rotate() return the raw key
 * ONCE, in the method's return value, never persisted anywhere in
 * plaintext and never logged (project rule 5). rotate() marks the
 * existing Active row Rotated (never deleted — project rule 6) and
 * creates a new Active row — the database's partial unique index
 * (firm_ai_provider_keys_one_active_per_firm_provider) enforces "one
 * active key per firm per provider" (project rule 7) even if
 * application logic were ever bypassed.
 */
class AiProviderKeyService
{
    public function __construct(
        private readonly EmailBodyEncryptionService $encryption,
        private readonly AiEntitlementPolicyService $entitlementPolicy,
    ) {
    }

    /**
     * @return array{key: FirmAiProviderKey, rawKey: string}
     */
    public function generate(Firm $firm, AiProvider $provider, ?User $actor = null, ?string $label = null): array
    {
        $this->entitlementPolicy->assertEnabled($firm);

        $rawKey = 'aikey_'.Str::random(48);

        $result = $this->encryption->encrypt($firm, $rawKey);

        if (! $result->succeeded) {
            throw new \RuntimeException("Cannot generate AI provider key: {$result->reason}");
        }

        $key = FirmAiProviderKey::create([
            'firm_id' => $firm->id,
            'provider' => $provider,
            'encrypted_key_ciphertext' => $result->ciphertext,
            'encryption_key_id' => $result->encryptionKeyId,
            'status' => AiProviderKeyStatus::Active,
            'label' => $label,
            'created_by' => $actor?->id,
        ]);

        return ['key' => $key, 'rawKey' => $rawKey];
    }

    /**
     * @return array{key: FirmAiProviderKey, rawKey: string}
     */
    public function rotate(Firm $firm, FirmAiProviderKey $existing, ?User $actor = null): array
    {
        if ($existing->firm_id !== $firm->id) {
            throw new \RuntimeException('This provider key does not belong to this firm.');
        }

        if ($existing->status !== AiProviderKeyStatus::Active) {
            throw new \RuntimeException('Only an Active key can be rotated.');
        }

        $provider = $existing->provider;
        $label = $existing->label;

        // Mark the OLD row Rotated FIRST — the partial unique index
        // only allows one 'active' row per (firm, provider), so the
        // new Active row cannot be inserted while the old one is
        // still Active.
        $existing->update([
            'status' => AiProviderKeyStatus::Rotated,
            'rotated_at' => now(),
        ]);

        return $this->generate($firm, $provider, $actor, $label);
    }

    /**
     * The ONLY path back to plaintext key material. Callers must keep
     * the return value in memory only — never log it, never persist
     * it anywhere other than this table's ciphertext column. No
     * route/controller/UI calls this in Phase 15 (none exist); it
     * exists for internal service-to-service use by a future
     * provider-integration phase.
     */
    public function keyMaterialFor(Firm $firm, FirmAiProviderKey $key): string
    {
        if ($key->firm_id !== $firm->id) {
            throw new \RuntimeException('This provider key does not belong to this firm.');
        }

        return $this->encryption->decrypt($firm, $key->encrypted_key_ciphertext, $key->encryption_key_id);
    }
}
