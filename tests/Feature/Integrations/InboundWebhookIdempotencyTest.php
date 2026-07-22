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
 * InboundWebhookIdempotencyTest — Checkpoint 7
 * (reviews/checkpoint-07/frozen-design-post-security-review.md
 * §10.1/§10.2). Both idempotency layers: receipt-level
 * UNIQUE(routing_token_hash, body_hash) and event-level
 * UNIQUE(firm_integration_id, provider_key, provider_event_id).
 */
final class InboundWebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_a_duplicate_valid_delivery_returns_the_same_accepted_response_without_a_second_event_row(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $first = $this->postWebhook('test', $headers, $body);
        $first->assertStatus(202);

        $eventCountAfterFirst = DB::table('integration_inbound_webhook_events')->count();

        $second = $this->postWebhook('test', $headers, $body);

        $this->assertSame($first->getStatusCode(), $second->getStatusCode());
        $this->assertSame($first->getContent(), $second->getContent());

        $eventCountAfterSecond = DB::table('integration_inbound_webhook_events')->count();
        $this->assertSame($eventCountAfterFirst, $eventCountAfterSecond, 'A duplicate delivery must never create a second event row.');
    }

    public function test_a_duplicate_via_a_fresh_http_request_never_creates_a_second_row_for_the_same_provider_event_id(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $this->postWebhook('test', $headers, $body);
        $this->postWebhook('test', $headers, $body);
        $this->postWebhook('test', $headers, $body);

        $decoded = json_decode($body, true);
        $count = $this->runWithFirmContext(
            $fixture['firm'],
            fn () => DB::table('integration_inbound_webhook_events')
                ->where('firm_integration_id', $fixture['connection']->id)
                ->where('provider_event_id', $decoded['event_id'])
                ->count(),
        );

        $this->assertSame(1, $count);
    }

    public function test_two_different_connections_of_the_same_firm_can_independently_reuse_the_same_provider_event_id(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $connectionOne = FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::Active->value]);
        $connectionTwo = FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::Active->value]);

        $tokenOne = $this->connectionService()->enableWebhookRouting($connectionOne);
        $tokenTwo = $this->connectionService()->enableWebhookRouting($connectionTwo);

        $secretOne = 'secret-one-'.Str::random(24);
        $secretTwo = 'secret-two-'.Str::random(24);
        $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connectionOne->fresh(), CredentialType::WebhookSigningSecret, $secretOne));
        $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connectionTwo->fresh(), CredentialType::WebhookSigningSecret, $secretTwo));

        $sharedEventId = (string) Str::uuid();
        $body = json_encode(['event_id' => $sharedEventId, 'event_type' => 'test.resource.created', 'payload' => []]);

        $responseOne = $this->postWebhook('test', $this->signedHeaders($secretOne, $tokenOne, $body), $body);
        $responseTwo = $this->postWebhook('test', $this->signedHeaders($secretTwo, $tokenTwo, $body), $body);

        $responseOne->assertStatus(202);
        $responseTwo->assertStatus(202);

        $count = $this->runWithFirmContext($firm, fn () => DB::table('integration_inbound_webhook_events')
            ->where('provider_event_id', $sharedEventId)
            ->count());

        $this->assertSame(2, $count, 'Two different connections must not conflate identical provider-minted event ids.');
    }

    public function test_two_different_firms_can_independently_reuse_the_same_provider_event_id(): void
    {
        $fixtureA = $this->activeConnectionWithWebhookSecret();
        $fixtureB = $this->activeConnectionWithWebhookSecret();

        $sharedEventId = (string) Str::uuid();
        $body = json_encode(['event_id' => $sharedEventId, 'event_type' => 'test.resource.created', 'payload' => []]);

        $this->postWebhook('test', $this->signedHeaders($fixtureA['secret'], $fixtureA['rawToken'], $body), $body)->assertStatus(202);
        $this->postWebhook('test', $this->signedHeaders($fixtureB['secret'], $fixtureB['rawToken'], $body), $body)->assertStatus(202);

        $countA = $this->runWithFirmContext($fixtureA['firm'], fn () => DB::table('integration_inbound_webhook_events')->where('provider_event_id', $sharedEventId)->count());
        $countB = $this->runWithFirmContext($fixtureB['firm'], fn () => DB::table('integration_inbound_webhook_events')->where('provider_event_id', $sharedEventId)->count());

        $this->assertSame(1, $countA);
        $this->assertSame(1, $countB);
    }

    public function test_a_concurrent_duplicate_insert_at_the_db_layer_never_creates_two_event_rows(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $eventId = (string) Str::uuid();

        $values = [
            'uuid' => (string) Str::uuid7(),
            'firm_id' => $fixture['firm']->id,
            'firm_integration_id' => $fixture['connection']->id,
            'receipt_id' => null,
            'provider_key' => 'test',
            'provider_event_id' => $eventId,
            'receipt_body_hash' => null,
            'event_type' => 'test.resource.created',
            'payload_reference_json' => '{}',
            'payload_hash' => null,
            'status' => 'verified',
            'processing_attempts' => 0,
            'received_at' => now(),
            'retention_deadline' => now()->addDays(400),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->runWithFirmContext($fixture['firm'], function () use ($values) {
            $rows = DB::table('integration_inbound_webhook_events')->insertOrIgnoreReturning(
                $values,
                returning: ['id'],
                uniqueBy: ['firm_integration_id', 'provider_key', 'provider_event_id'],
            );
            $this->assertNotEmpty($rows);
        });

        // Simulates the second half of a race: a retry that hits the
        // ON CONFLICT DO NOTHING path — must resolve to a no-op, never
        // an error, never a second row.
        $secondAttemptRows = $this->runWithFirmContext($fixture['firm'], fn () => DB::table('integration_inbound_webhook_events')->insertOrIgnoreReturning(
            array_merge($values, ['uuid' => (string) Str::uuid7()]),
            returning: ['id'],
            uniqueBy: ['firm_integration_id', 'provider_key', 'provider_event_id'],
        ));

        $this->assertCount(0, $secondAttemptRows, 'ON CONFLICT DO NOTHING must resolve the second attempt to zero returned rows.');

        $count = $this->runWithFirmContext($fixture['firm'], fn () => DB::table('integration_inbound_webhook_events')->where('provider_event_id', $eventId)->count());
        $this->assertSame(1, $count);
    }

    public function test_receipt_level_routing_token_hash_and_body_hash_uniqueness_is_enforced_at_the_db_layer_directly(): void
    {
        $routingTokenHash = hash('sha256', Str::random(43));
        $bodyHash = hash('sha256', 'identical-body-bytes');

        $values = [
            'provider_key' => 'test',
            'routing_token_hash' => $routingTokenHash,
            'request_correlation_id' => null,
            'provider_event_id' => (string) Str::uuid(),
            'body_hash' => $bodyHash,
            'signature_version' => 'v1',
            'verification_outcome' => 'verified',
            'received_at' => now(),
            'provider_timestamp' => now(),
            'acknowledgment_status' => 'acknowledged',
            'acknowledged_at' => now(),
            'processing_handoff_status' => 'pending',
            'failure_code' => null,
            'retention_deadline' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $firstRows = DB::table('integration_webhook_receipts')->insertOrIgnoreReturning($values, returning: ['id'], uniqueBy: ['routing_token_hash', 'body_hash']);
        $this->assertCount(1, $firstRows);

        // Bypass the service entirely — insert twice directly.
        $secondRows = DB::table('integration_webhook_receipts')->insertOrIgnoreReturning(
            array_merge($values, ['provider_event_id' => (string) Str::uuid()]),
            returning: ['id'],
            uniqueBy: ['routing_token_hash', 'body_hash'],
        );

        $this->assertCount(0, $secondRows, 'A second insert with the same (routing_token_hash, body_hash) pair must be a silent no-op.');

        $count = DB::table('integration_webhook_receipts')->where('routing_token_hash', $routingTokenHash)->where('body_hash', $bodyHash)->count();
        $this->assertSame(1, $count);
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
