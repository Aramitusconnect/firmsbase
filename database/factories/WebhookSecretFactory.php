<?php

namespace Database\Factories;

use App\Enums\WebhookSecretStatus;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Models\WebhookSecret;
use App\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookSecret>
 *
 * NOTE: definition() below does NOT produce a genuinely decryptable
 * ciphertext (it is not routed through EmailBodyEncryptionService) —
 * it is only usable for tests that don't need to decrypt the secret.
 * Tests exercising real encrypt/decrypt round-trips must go through
 * WebhookSecretService::generate()/rotate() against a firm with an
 * active TenantEncryptionKey, exactly like EmailOAuthToken's factory
 * convention.
 */
class WebhookSecretFactory extends Factory
{
    protected $model = WebhookSecret::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'webhook_subscription_id' => WebhookSubscription::factory(),
            'encrypted_secret_ciphertext' => 'placeholder-ciphertext-not-real',
            'encryption_key_id' => TenantEncryptionKey::factory(),
            'status' => WebhookSecretStatus::Active,
        ];
    }

    public function forSubscription(WebhookSubscription $subscription): static
    {
        return $this->state(fn () => [
            'firm_id' => $subscription->firm_id,
            'webhook_subscription_id' => $subscription->id,
        ]);
    }

    public function rotated(): static
    {
        return $this->state(fn () => [
            'status' => WebhookSecretStatus::Rotated,
            'rotated_at' => now(),
        ]);
    }
}
