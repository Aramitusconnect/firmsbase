<?php

namespace Tests\Feature\Webhooks\Secrets;

use App\Enums\WebhookSecretStatus;
use App\Services\WebhookSecretService;
use App\Services\WebhookSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Webhooks\Concerns\SetsUpWebhookEntitledFirm;
use Tests\TestCase;

class WebhookSecretServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpWebhookEntitledFirm;

    private WebhookSecretService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WebhookSecretService::class);
    }

    public function test_generate_returns_the_raw_secret_once_and_stores_only_ciphertext(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);
        $subscription = app(WebhookSubscriptionService::class)->create(
            $firm, $owner, ['matter.created'], 'https://example.com/hooks', $owner
        );

        ['secret' => $secret, 'rawSecret' => $rawSecret] = $this->service->generate($firm, $subscription);

        $this->assertNotEmpty($rawSecret);
        $this->assertSame(WebhookSecretStatus::Active, $secret->status);

        $row = DB::table('webhook_secrets')->where('id', $secret->id)->first();
        $this->assertNotSame($rawSecret, $row->encrypted_secret_ciphertext);
        $this->assertStringNotContainsString($rawSecret, $row->encrypted_secret_ciphertext);
    }

    public function test_signing_secret_for_decrypts_back_to_the_original_raw_secret(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);
        $subscription = app(WebhookSubscriptionService::class)->create(
            $firm, $owner, ['matter.created'], 'https://example.com/hooks', $owner
        );

        ['secret' => $secret, 'rawSecret' => $rawSecret] = $this->service->generate($firm, $subscription);

        $decrypted = $this->service->signingSecretFor($firm, $secret);

        $this->assertSame($rawSecret, $decrypted);
    }

    public function test_rotation_marks_the_old_secret_rotated_and_creates_a_new_active_secret(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);
        $subscription = app(WebhookSubscriptionService::class)->create(
            $firm, $owner, ['matter.created'], 'https://example.com/hooks', $owner
        );

        ['secret' => $original] = $this->service->generate($firm, $subscription);
        ['secret' => $rotated, 'rawSecret' => $newRawSecret] = $this->service->rotate($firm, $original);

        $this->assertSame(WebhookSecretStatus::Rotated, $original->fresh()->status);
        $this->assertNotNull($original->fresh()->rotated_at);
        $this->assertSame(WebhookSecretStatus::Active, $rotated->status);
        $this->assertNotSame($original->id, $rotated->id);

        $decrypted = $this->service->signingSecretFor($firm, $rotated);
        $this->assertSame($newRawSecret, $decrypted);
    }

    public function test_only_one_active_secret_per_subscription_is_allowed_at_the_database_level(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);
        $subscription = app(WebhookSubscriptionService::class)->create(
            $firm, $owner, ['matter.created'], 'https://example.com/hooks', $owner
        );

        $this->service->generate($firm, $subscription);

        // Section 39A-3L, Checkpoint 16: tenant_encryption_keys is now
        // FORCE RLS. This bare relationship lookup (outside any
        // context, after generate() already cleared its own) must be
        // explicitly wrapped or it would incorrectly resolve to null.
        $activeEncryptionKeyId = $this->runWithFirmContext(
            $firm,
            fn () => $firm->activeTenantEncryptionKey->id,
        );

        // Attempting to insert a SECOND active row directly (bypassing
        // the service) must be rejected by the partial unique index
        // itself, not merely by application logic.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('webhook_secrets')->insert([
            'firm_id' => $firm->id,
            'webhook_subscription_id' => $subscription->id,
            'encrypted_secret_ciphertext' => 'x',
            'encryption_key_id' => $activeEncryptionKeyId,
            'status' => 'active',
            'created_at' => now(),
        ]);
    }

    public function test_only_an_active_secret_can_be_rotated(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);
        $subscription = app(WebhookSubscriptionService::class)->create(
            $firm, $owner, ['matter.created'], 'https://example.com/hooks', $owner
        );

        ['secret' => $original] = $this->service->generate($firm, $subscription);
        $this->service->rotate($firm, $original);

        $this->expectException(\RuntimeException::class);
        $this->service->rotate($firm, $original->fresh());
    }

    public function test_encrypted_secret_ciphertext_is_immutable_after_creation(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);
        $subscription = app(WebhookSubscriptionService::class)->create(
            $firm, $owner, ['matter.created'], 'https://example.com/hooks', $owner
        );

        ['secret' => $secret] = $this->service->generate($firm, $subscription);

        $this->expectException(\LogicException::class);
        $secret->update(['encrypted_secret_ciphertext' => 'tampered']);
    }
}
