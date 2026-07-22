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
 * InboundWebhookOutageBehaviorTest — Checkpoint 7's durable-write-
 * before-ack contract (reviews/checkpoint-07/frozen-design-post-security-review.md
 * §8.1 row 12: "no ack sent at all in this case"). Forces a genuine
 * database-level failure on the receipts table (a temporary rename,
 * restored in a finally block) rather than mocking, so the assertion
 * proves real transactional behavior.
 */
final class InboundWebhookOutageBehaviorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_a_durable_receipt_write_failure_never_sends_a_202_and_returns_a_500(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        DB::statement('ALTER TABLE integration_webhook_receipts RENAME TO integration_webhook_receipts_outage_test');

        try {
            $response = $this->postWebhookRecoveringFromDbError('test', $headers, $body);

            $this->assertNotSame(202, $response->getStatusCode(), 'A durable-receipt-write failure must never result in a 202 acknowledgment.');
            $response->assertStatus(500);
            $response->assertExactJson(['status' => 'error']);
        } finally {
            DB::statement('ALTER TABLE integration_webhook_receipts_outage_test RENAME TO integration_webhook_receipts');
        }
    }

    public function test_after_the_outage_clears_a_retried_delivery_of_the_same_bytes_succeeds_and_creates_exactly_one_row(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        DB::statement('ALTER TABLE integration_webhook_receipts RENAME TO integration_webhook_receipts_outage_test_2');
        try {
            $this->postWebhookRecoveringFromDbError('test', $headers, $body)->assertStatus(500);
        } finally {
            DB::statement('ALTER TABLE integration_webhook_receipts_outage_test_2 RENAME TO integration_webhook_receipts');
        }

        $before = DB::table('integration_webhook_receipts')->count();

        $retry = $this->postWebhook('test', $headers, $body);
        $retry->assertStatus(202);

        $after = DB::table('integration_webhook_receipts')->count();
        $this->assertSame($before + 1, $after);
    }

    public function test_a_database_error_during_pre_verification_routing_lookup_never_leaks_internal_error_detail(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        DB::statement('ALTER TABLE integration_webhook_routing_index RENAME TO integration_webhook_routing_index_outage_test');

        try {
            $response = $this->postWebhookRecoveringFromDbError('test', $headers, $body);

            $this->assertStringNotContainsString('relation', strtolower((string) $response->getContent()));
            $this->assertStringNotContainsString('sql', strtolower((string) $response->getContent()));
            $this->assertStringNotContainsString('exception', strtolower((string) $response->getContent()));
        } finally {
            DB::statement('ALTER TABLE integration_webhook_routing_index_outage_test RENAME TO integration_webhook_routing_index');
        }
    }

    /**
     * A query failure inside the webhook request leaves the current
     * PostgreSQL transaction/savepoint in an "aborted" state — Laravel's
     * HTTP kernel catches the resulting exception and returns a
     * sanitized response object (which is what this method returns),
     * but nothing automatically issues a ROLLBACK, so any FOLLOWING
     * statement on the same connection (including this test's own
     * schema-restoring ALTER TABLE ... RENAME in its finally block)
     * would otherwise fail with "current transaction is aborted".
     * Wrapping the call in an explicit SAVEPOINT taken immediately
     * before it, and rolling back to (never fully rolling back past)
     * that savepoint afterward, recovers the connection to a healthy
     * state without touching any of the surrounding test's fixture
     * data or RefreshDatabase's own outer transaction.
     */
    private function postWebhookRecoveringFromDbError(string $provider, array $headers, string $body): TestResponse
    {
        DB::unprepared('SAVEPOINT outage_test_recovery');

        $response = $this->postWebhook($provider, $headers, $body);

        DB::unprepared('ROLLBACK TO SAVEPOINT outage_test_recovery');
        DB::unprepared('RELEASE SAVEPOINT outage_test_recovery');

        return $response;
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
