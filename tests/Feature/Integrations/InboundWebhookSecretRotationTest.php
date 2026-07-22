<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\IntegrationOAuthStateService;
use App\Integrations\Services\ProviderConnectionService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\PkceService;
use App\Integrations\Support\ProviderRedirectUrlValidator;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * InboundWebhookSecretRotationTest — Checkpoint 7's 2-candidate
 * rotation overlap window
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §8),
 * exercised through the real HTTP route.
 */
final class InboundWebhookSecretRotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_the_current_active_secret_verifies(): void
    {
        $fixture = $this->connectionWithCredential();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $this->postWebhook('test', $headers, $body)->assertStatus(202);
    }

    public function test_the_previous_secret_verifies_within_the_overlap_window(): void
    {
        $fixture = $this->connectionWithCredential();
        $rotated = $this->credentialService()->rotate($fixture['connection'], $fixture['credential'], 'new-secret-'.Str::random(24));

        $body = $this->eventBody();
        // Sign with the OLD (now-Rotated) secret.
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $this->postWebhook('test', $headers, $body)->assertStatus(202);
    }

    public function test_the_new_active_secret_also_verifies_immediately_after_rotation(): void
    {
        $fixture = $this->connectionWithCredential();
        $newSecret = 'new-secret-'.Str::random(24);
        $this->credentialService()->rotate($fixture['connection'], $fixture['credential'], $newSecret);

        $body = $this->eventBody();
        $headers = $this->signedHeaders($newSecret, $fixture['rawToken'], $body);

        $this->postWebhook('test', $headers, $body)->assertStatus(202);
    }

    public function test_the_previous_secret_fails_once_the_overlap_window_has_elapsed(): void
    {
        $fixture = $this->connectionWithCredential();
        $rotated = $this->credentialService()->rotate($fixture['connection'], $fixture['credential'], 'new-secret-'.Str::random(24));

        // Backdate rotated_at past the default 24h overlap window.
        $this->runWithFirmContext($fixture['firm'], fn () => DB::table('integration_credentials')
            ->where('id', $fixture['credential']->id)
            ->update(['rotated_at' => now()->subHours(25)]));

        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $this->postWebhook('test', $headers, $body)->assertStatus(401);
    }

    public function test_a_secret_two_rotations_old_never_verifies(): void
    {
        $fixture = $this->connectionWithCredential();
        $secondSecret = 'second-secret-'.Str::random(24);
        $secondCredential = $this->credentialService()->rotate($fixture['connection'], $fixture['credential'], $secondSecret);
        $this->credentialService()->rotate($fixture['connection'], $secondCredential, 'third-secret-'.Str::random(24));

        $body = $this->eventBody();
        // The ORIGINAL secret is now two rotations old.
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $this->postWebhook('test', $headers, $body)->assertStatus(401);
    }

    public function test_a_revoked_only_credential_rejects_every_request_through_the_identical_generic_path(): void
    {
        $fixture = $this->connectionWithCredential();
        $this->credentialService()->revoke($fixture['connection'], $fixture['credential'], 'revoked for test');

        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $revokedResponse = $this->postWebhook('test', $headers, $body);

        $wrongSecretResponse = $this->postWebhook('test', [
            'X-Test-Provider-Connection-Token' => $fixture['rawToken'],
            'X-Test-Provider-Signature' => 'v1='.str_repeat('9', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ], $body);

        $this->assertSame(401, $revokedResponse->getStatusCode());
        $this->assertSame($wrongSecretResponse->getStatusCode(), $revokedResponse->getStatusCode());
        $this->assertSame($wrongSecretResponse->getContent(), $revokedResponse->getContent());
    }

    public function test_which_secret_candidate_matched_is_never_exposed_in_the_http_response(): void
    {
        $fixture = $this->connectionWithCredential();
        $this->credentialService()->rotate($fixture['connection'], $fixture['credential'], 'new-secret-'.Str::random(24));

        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $response = $this->postWebhook('test', $headers, $body);

        $response->assertStatus(202);
        $response->assertExactJson(['status' => 'accepted']);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array{firm: Firm, connection: FirmIntegration, rawToken: string, secret: string, credential: \App\Integrations\Models\IntegrationCredential}
     */
    private function connectionWithCredential(): array
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $connection = FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::Active->value]);
        $rawToken = $this->connectionService()->enableWebhookRouting($connection);

        $secret = 'wh-secret-'.Str::random(32);
        $credential = $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection->fresh(), CredentialType::WebhookSigningSecret, $secret));

        return ['firm' => $firm, 'connection' => $connection, 'rawToken' => $rawToken, 'secret' => $secret, 'credential' => $credential];
    }

    private function credentialService(): IntegrationCredentialService
    {
        return new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService()));
    }

    private function connectionService(): ProviderConnectionService
    {
        return new ProviderConnectionService(
            new IntegrationOAuthStateService(
                new EmailBodyEncryptionService(new EncryptionKeyService()),
                new PkceService(),
                new ProviderRedirectUrlValidator(),
            ),
            $this->credentialService(),
            new IntegrationAccessPolicyService(),
            new \App\Integrations\Core\ProviderRegistry(),
            new OutboundProviderHttpClient(),
            new ProviderRedirectUrlValidator(),
            new TimelineEventRecorder(),
        );
    }

    private function eventBody(): string
    {
        return json_encode([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'test.resource.created',
            'payload' => ['foo' => 'bar'],
        ]);
    }

    private function signedHeaders(string $secret, string $rawToken, string $body, ?int $timestamp = null): array
    {
        $timestamp ??= now()->getTimestamp();
        $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.'.'.$body, $secret);

        return [
            'X-Test-Provider-Connection-Token' => $rawToken,
            'X-Test-Provider-Signature' => $signature,
            'X-Test-Provider-Timestamp' => (string) $timestamp,
        ];
    }

    private function postWebhook(string $provider, array $headers, string $body): TestResponse
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', "/webhooks/integrations/{$provider}", [], [], [], $server, $body);
    }
}
