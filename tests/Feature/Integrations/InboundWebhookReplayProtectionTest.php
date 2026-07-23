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
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * InboundWebhookReplayProtectionTest — Checkpoint 7's ±300s replay
 * window (reviews/checkpoint-07/frozen-design-post-security-review.md
 * §8), exercised through the real HTTP route.
 */
final class InboundWebhookReplayProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_a_current_valid_timestamp_is_accepted(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body, now()->getTimestamp());

        $this->postWebhook('test', $headers, $body)->assertStatus(202);
    }

    public function test_an_expired_timestamp_is_rejected(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body, now()->subMinutes(20)->getTimestamp());

        $response = $this->postWebhook('test', $headers, $body);
        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);
    }

    public function test_a_future_timestamp_is_rejected(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body, now()->addMinutes(20)->getTimestamp());

        $this->postWebhook('test', $headers, $body)->assertStatus(401);
    }

    public function test_a_timestamp_exactly_300_seconds_in_the_past_is_accepted(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body, now()->subSeconds(300)->getTimestamp());

        $this->postWebhook('test', $headers, $body)->assertStatus(202);
    }

    public function test_a_timestamp_301_seconds_in_the_past_is_rejected(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body, now()->subSeconds(301)->getTimestamp());

        $this->postWebhook('test', $headers, $body)->assertStatus(401);
    }

    public function test_a_timestamp_exactly_300_seconds_in_the_future_is_accepted(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body, now()->addSeconds(300)->getTimestamp());

        $this->postWebhook('test', $headers, $body)->assertStatus(202);
    }

    public function test_a_timestamp_301_seconds_in_the_future_is_rejected(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body, now()->addSeconds(301)->getTimestamp());

        $this->postWebhook('test', $headers, $body)->assertStatus(401);
    }

    public function test_an_expired_timestamp_with_an_otherwise_valid_signature_produces_the_same_generic_rejection_as_a_bad_signature(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();

        $expired = $this->postWebhook(
            'test',
            $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body, now()->subMinutes(20)->getTimestamp()),
            $body,
        );

        $badSignature = $this->postWebhook('test', [
            'X-Test-Provider-Connection-Token' => $fixture['rawToken'],
            'X-Test-Provider-Signature' => 'v1='.str_repeat('9', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ], $body);

        $this->assertSame($expired->getStatusCode(), $badSignature->getStatusCode());
        $this->assertSame($expired->getContent(), $badSignature->getContent());
    }

    public function test_a_future_timestamp_and_an_expired_timestamp_produce_byte_identical_wire_responses(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();

        $future = $this->postWebhook(
            'test',
            $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body, now()->addMinutes(20)->getTimestamp()),
            $body,
        );
        $expired = $this->postWebhook(
            'test',
            $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body, now()->subMinutes(20)->getTimestamp()),
            $body,
        );

        $this->assertSame($future->getStatusCode(), $expired->getStatusCode());
        $this->assertSame($future->getContent(), $expired->getContent());
    }

    public function test_a_wildly_expired_timestamp_is_rejected_the_same_generic_way_as_a_barely_expired_one(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body, now()->subYear()->getTimestamp());

        $response = $this->postWebhook('test', $headers, $body);
        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function activeConnectionWithWebhookSecret(): array
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $connection = FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::Active->value]);
        $rawToken = $this->connectionService()->enableWebhookRouting($connection);

        $secret = 'wh-secret-'.Str::random(32);
        $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection->fresh(), CredentialType::WebhookSigningSecret, $secret));

        return ['firm' => $firm, 'connection' => $connection, 'rawToken' => $rawToken, 'secret' => $secret];
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
            new IntegrationAccessPolicyService(new TimelineEventRecorder()),
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

    private function signedHeaders(string $secret, string $rawToken, string $body, int $timestamp): array
    {
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
