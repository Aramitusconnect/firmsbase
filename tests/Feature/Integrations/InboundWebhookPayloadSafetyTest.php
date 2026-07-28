<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\EntitlementSource;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationInboundWebhookEvent;
use App\Integrations\Models\IntegrationWebhookReceipt;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\IntegrationOAuthStateService;
use App\Integrations\Services\ProviderConnectionService;
use App\Integrations\Support\GmailMailboxRoutingService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\PkceService;
use App\Integrations\Support\ProviderRedirectUrlValidator;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\EntitlementService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * InboundWebhookPayloadSafetyTest — Checkpoint 7
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §2/§8.1
 * rows 10-11/§13).
 */
final class InboundWebhookPayloadSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const MAX_BYTES = 256 * 1024;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Checkpoint 1 (FirmsVault Live Integrations): InboundWebhookController
        // now resolves the provider via ProviderRegistry/ProviderKey FIRST,
        // before anything else — without this, every real HTTP request this
        // test makes through the controller collapses to a 401 regardless of
        // routing token/signature validity. Mirrors
        // InboundWebhookLifecycleRevalidationTest::setUp()'s identical,
        // already-established override.
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
    }

    public function test_an_oversized_payload_is_rejected_with_413_before_any_db_write(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $oversizedBody = str_repeat('a', self::MAX_BYTES + 1);
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $oversizedBody);

        $before = DB::table('integration_webhook_receipts')->count();

        $response = $this->postWebhook('test', $headers, $oversizedBody);

        $response->assertStatus(413);
        $response->assertExactJson(['status' => 'rejected', 'reason' => 'payload_too_large']);

        $after = DB::table('integration_webhook_receipts')->count();
        $this->assertSame($before, $after);
    }

    public function test_a_body_exactly_at_the_size_limit_is_not_rejected_for_size(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();

        $padding = str_repeat('a', self::MAX_BYTES - 200);
        $body = json_encode(['event_id' => (string) Str::uuid(), 'event_type' => 'test.resource.created', 'payload' => ['pad' => $padding]]);
        $this->assertLessThanOrEqual(self::MAX_BYTES, strlen($body));

        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);
        $response = $this->postWebhook('test', $headers, $body);

        $this->assertNotSame(413, $response->getStatusCode());
    }

    public function test_the_413_response_fires_identically_regardless_of_provider_token_or_signature_validity(): void
    {
        $oversizedBody = str_repeat('a', self::MAX_BYTES + 1);

        $response = $this->postWebhook('unknownprovider', [
            'X-Test-Provider-Connection-Token' => Str::random(43),
            'X-Test-Provider-Signature' => 'v1='.str_repeat('a', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ], $oversizedBody);

        $response->assertStatus(413);
        $response->assertExactJson(['status' => 'rejected', 'reason' => 'payload_too_large']);
    }

    public function test_a_malformed_json_body_with_a_valid_signature_is_handled_as_malformed_not_a_crash(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $malformedBody = '{not-valid-json-at-all';
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $malformedBody);

        $response = $this->postWebhook('test', $headers, $malformedBody);

        $response->assertStatus(400);
        $response->assertExactJson(['status' => 'rejected', 'reason' => 'malformed_payload']);
    }

    public function test_a_valid_json_body_missing_event_id_after_verification_is_malformed_not_a_random_id(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = json_encode(['event_type' => 'test.resource.created', 'payload' => []]);
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $response = $this->postWebhook('test', $headers, $body);

        $response->assertStatus(400);
        $response->assertExactJson(['status' => 'rejected', 'reason' => 'malformed_payload']);
    }

    public function test_invalid_utf_8_in_the_body_is_handled_safely_without_crashing(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $invalidUtf8Body = "{\"event_id\":\"\xB1\x31\",\"event_type\":\"x\"}";
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $invalidUtf8Body);

        $response = $this->postWebhook('test', $headers, $invalidUtf8Body);

        // Signature verification is byte-oriented and must succeed;
        // json_decode() then fails cleanly on the invalid bytes,
        // routing through the malformed-payload path, never an
        // uncaught error.
        $response->assertStatus(400);
        $response->assertExactJson(['status' => 'rejected', 'reason' => 'malformed_payload']);
    }

    public function test_the_secret_and_routing_token_never_appear_in_the_audit_log(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $this->postWebhook('test', $headers, $body);

        $logPath = storage_path('logs/integration-webhook-audit.log');

        if (! file_exists($logPath)) {
            $this->markTestSkipped('Audit log file was not created — nothing to inspect.');
        }

        $contents = file_get_contents($logPath);

        $this->assertStringNotContainsString($fixture['secret'], $contents);
        $this->assertStringNotContainsString($fixture['rawToken'], $contents);
        $this->assertStringNotContainsString($headers['X-Test-Provider-Signature'], $contents);
    }

    public function test_the_raw_body_is_absent_from_every_column_of_the_receipt_row(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $marker = 'UNIQUE-RAW-BODY-MARKER-'.Str::random(24);
        $body = json_encode(['event_id' => (string) Str::uuid(), 'event_type' => 'test.resource.created', 'payload' => ['marker' => $marker]]);
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $this->postWebhook('test', $headers, $body)->assertStatus(202);

        $receipt = DB::table('integration_webhook_receipts')->orderByDesc('id')->first();
        $this->assertNotNull($receipt);

        foreach ((array) $receipt as $column => $value) {
            $this->assertStringNotContainsString($marker, (string) $value, "Column {$column} must never contain a fragment of the raw request body.");
        }
    }

    public function test_the_raw_body_is_absent_from_every_column_of_the_event_row(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $marker = 'UNIQUE-RAW-BODY-MARKER-'.Str::random(24);
        $body = json_encode(['event_id' => (string) Str::uuid(), 'event_type' => 'test.resource.created', 'payload' => ['marker' => $marker]]);
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $this->postWebhook('test', $headers, $body)->assertStatus(202);

        $event = $this->runWithFirmContext($fixture['firm'], fn () => DB::table('integration_inbound_webhook_events')->orderByDesc('id')->first());
        $this->assertNotNull($event);

        foreach ((array) $event as $column => $value) {
            $this->assertStringNotContainsString($marker, (string) $value, "Column {$column} must never contain a fragment of the raw request body.");
        }
    }

    public function test_receipt_model_serialization_excludes_the_routing_token_hash(): void
    {
        $receipt = IntegrationWebhookReceipt::factory()->create();

        $array = $receipt->toArray();
        $json = json_decode($receipt->toJson(), true);

        $this->assertArrayNotHasKey('routing_token_hash', $array);
        $this->assertArrayNotHasKey('routing_token_hash', $json);
    }

    public function test_event_model_serialization_excludes_nothing_sensitive_and_never_contains_a_raw_signature_or_secret(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $event = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connection)->create());

        $array = $this->runWithFirmContext($firm, fn () => $event->fresh()->toArray());
        $encoded = json_encode($array);

        $this->assertStringNotContainsString('secret', strtolower($encoded));
        $this->assertStringNotContainsString('signature', strtolower($encoded));
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function activeConnectionWithWebhookSecret(): array
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $connection = FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::Active->value]);
        $rawToken = $this->connectionService()->enableWebhookRouting($connection, $this->webhookRoutingActorUserId($connection));

        $secret = 'wh-secret-'.Str::random(32);
        $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection->fresh(), CredentialType::WebhookSigningSecret, $secret));

        return ['firm' => $firm, 'connection' => $connection, 'rawToken' => $rawToken, 'secret' => $secret];
    }

    private function credentialService(): IntegrationCredentialService
    {
        return new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService), new TimelineEventRecorder);
    }

    private function connectionService(): ProviderConnectionService
    {
        return new ProviderConnectionService(
            new IntegrationOAuthStateService(
                new EmailBodyEncryptionService(new EncryptionKeyService),
                new PkceService,
                new ProviderRedirectUrlValidator,
            ),
            $this->credentialService(),
            new IntegrationAccessPolicyService(new TimelineEventRecorder),
            new ProviderRegistry,
            new OutboundProviderHttpClient,
            new ProviderRedirectUrlValidator,
            new TimelineEventRecorder,
            // Checkpoint 10 addition (frozen design §4): ProviderConnectionService's
            // constructor gained this 8th, required dependency — every
            // manual construction site in this file must supply it.
            app(IntegrationEntitlementPolicyService::class),
            // Checkpoint 3 addition (FirmsVault Live Integrations,
            // Google Workspace): ProviderConnectionService's constructor
            // gained this 9th, required dependency -- every manual
            // construction site in this file must supply it.
            app(GmailMailboxRoutingService::class),
        );
    }

    /**
     * Checkpoint 10 addition: enableWebhookRouting()/disableWebhookRouting()
     * now require an authorized $currentUserId. FirmIntegrationFactory
     * already creates an Active, Attorney-role FirmUser as
     * connected_by_firm_user_id for every connection it builds, so that
     * same FirmUser's user_id is reused here as the acting user rather
     * than minting a second, unrelated one.
     */
    private function webhookRoutingActorUserId(FirmIntegration $connection): int
    {
        return $this->runWithFirmContext(
            $connection->firm_id,
            fn () => $connection->connectedByFirmUser->user_id,
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
        // Checkpoint 1 (design §6): the controller's new content-type
        // allowlist rejects Symfony's default 'application/x-www-form-urlencoded'
        // for raw POST content with no explicit Content-Type — every real
        // webhook sender sets this, so this is the correct fixture fix
        // (see InboundWebhookAuditLoggerTest::postWebhook() for the full
        // rationale). Unaffected for this file's oversized-payload tests,
        // which are rejected by LimitInboundWebhookPayloadSize BEFORE the
        // controller's content-type check ever runs.
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', "/webhooks/integrations/{$provider}", [], [], [], $server, $body);
    }
}
