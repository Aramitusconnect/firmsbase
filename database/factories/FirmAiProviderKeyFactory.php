<?php

namespace Database\Factories;

use App\Enums\AiProvider;
use App\Enums\AiProviderKeyStatus;
use App\Models\Firm;
use App\Models\FirmAiProviderKey;
use App\Models\TenantEncryptionKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FirmAiProviderKey>
 *
 * NOTE: definition() below does NOT produce a genuinely decryptable
 * ciphertext (not routed through EmailBodyEncryptionService) — usable
 * only for tests that don't need to decrypt. Tests exercising real
 * encrypt/decrypt round-trips must go through
 * AiProviderKeyService::generate()/rotate() against a firm with an
 * active TenantEncryptionKey, matching WebhookSecretFactory's exact
 * convention.
 */
class FirmAiProviderKeyFactory extends Factory
{
    protected $model = FirmAiProviderKey::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'provider' => AiProvider::OpenAi,
            'encrypted_key_ciphertext' => 'placeholder-ciphertext-not-real',
            'encryption_key_id' => TenantEncryptionKey::factory(),
            'status' => AiProviderKeyStatus::Active,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function rotated(): static
    {
        return $this->state(fn () => [
            'status' => AiProviderKeyStatus::Rotated,
            'rotated_at' => now(),
        ]);
    }
}
