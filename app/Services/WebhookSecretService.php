<?php

namespace App\Services;

use App\Enums\WebhookSecretStatus;
use App\Models\Firm;
use App\Models\WebhookSecret;
use App\Models\WebhookSubscription;
use Illuminate\Support\Str;

/**
 * WebhookSecretService — the only writer of webhook_secrets. Reuses
 * EmailBodyEncryptionService EXACTLY as-is (correction #8) — no second
 * encryption system. generate()/rotate() return the raw secret ONCE, in
 * the method's return value, never persisted anywhere in plaintext and
 * never logged. rotate() marks the existing Active row Rotated (never
 * deleted) and creates a new Active row — the database's partial unique
 * index (webhook_secrets_one_active_per_subscription) enforces "one
 * active secret per subscription" even if application logic were ever
 * bypassed.
 */
class WebhookSecretService
{
    public function __construct(
        private readonly EmailBodyEncryptionService $encryption,
        private readonly TenantSafeWebhookPolicyService $tenantSafePolicy,
    ) {
    }

    /**
     * Relies entirely on ambient caller-supplied tenant context — no
     * runWithFirmContext() wrap of its own. No production caller exists
     * today; a future caller must supply context explicitly.
     *
     * @return array{secret: WebhookSecret, rawSecret: string}
     */
    public function generate(Firm $firm, WebhookSubscription $subscription): array
    {
        $this->tenantSafePolicy->assertWebhookSubscriptionBelongsToFirm($subscription, $firm);

        $rawSecret = 'whsec_'.Str::random(48);

        $result = $this->encryption->encrypt($firm, $rawSecret);

        if (! $result->succeeded) {
            throw new \RuntimeException("Cannot generate webhook secret: {$result->reason}");
        }

        $secret = WebhookSecret::create([
            'firm_id' => $firm->id,
            'webhook_subscription_id' => $subscription->id,
            'encrypted_secret_ciphertext' => $result->ciphertext,
            'encryption_key_id' => $result->encryptionKeyId,
            'status' => WebhookSecretStatus::Active,
        ]);

        return ['secret' => $secret, 'rawSecret' => $rawSecret];
    }

    /**
     * Relies entirely on ambient caller-supplied tenant context — no
     * runWithFirmContext() wrap of its own. No production caller exists
     * today; a future caller must supply context explicitly.
     *
     * @return array{secret: WebhookSecret, rawSecret: string}
     */
    public function rotate(Firm $firm, WebhookSecret $existing): array
    {
        $this->tenantSafePolicy->assertWebhookSecretBelongsToFirm($existing, $firm);

        if ($existing->status !== WebhookSecretStatus::Active) {
            throw new \RuntimeException('Only an Active secret can be rotated.');
        }

        $subscription = $existing->subscription;

        // Mark the OLD row Rotated FIRST — the partial unique index
        // only allows one 'active' row per subscription, so the new
        // Active row cannot be inserted while the old one is still
        // Active.
        $existing->update([
            'status' => WebhookSecretStatus::Rotated,
            'rotated_at' => now(),
        ]);

        return $this->generate($firm, $subscription);
    }

    /**
     * The ONLY path back to plaintext secret material. Callers must
     * keep the return value in memory only — never log it, never
     * persist it anywhere other than this table's ciphertext column.
     */
    public function signingSecretFor(Firm $firm, WebhookSecret $secret): string
    {
        $this->tenantSafePolicy->assertWebhookSecretBelongsToFirm($secret, $firm);

        return $this->encryption->decrypt($firm, $secret->encrypted_secret_ciphertext, $secret->encryption_key_id);
    }
}
