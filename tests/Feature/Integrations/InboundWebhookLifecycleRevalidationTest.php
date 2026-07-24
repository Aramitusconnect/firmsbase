<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationWebhookRoutingIndex;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\IntegrationOAuthStateService;
use App\Integrations\Services\ProviderConnectionService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\PkceService;
use App\Integrations\Support\ProviderRedirectUrlValidator;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\EntitlementService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * InboundWebhookLifecycleRevalidationTest — Checkpoint 7's connection/
 * credential lifecycle interactions with the inbound webhook pipeline
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §4/§8.1
 * row 8/9). Disconnect must clear BOTH `firm_integrations.
 * webhook_routing_token` AND the corresponding
 * `integration_webhook_routing_index` row in the SAME transaction.
 */
final class InboundWebhookLifecycleRevalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // ProviderConnectionService::disconnect() unconditionally
        // resolves the connection's provider via ProviderRegistry
        // before it ever checks SupportsDisconnectContract — without
        // this, disconnect() throws UnknownProviderException in any
        // environment that doesn't set INTEGRATIONS_TEST_PROVIDER_ENABLED.
        // Mirrors ProviderConnectionServiceOAuthTest::setUp()'s
        // identical, already-established override.
        config(['integrations.providers' => [\App\Integrations\Enums\ProviderKey::Test->value => \App\Integrations\Providers\TestProvider\TestProvider::class]]);
    }

    public function test_a_disconnected_connection_rejects_an_otherwise_valid_request(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $this->connectionService()->disconnect($fixture['connection'], $fixture['firmUser']->user_id);

        $response = $this->postWebhook('test', $headers, $body);
        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);
    }

    public function test_a_revoked_credential_rejects_an_otherwise_valid_request(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $this->credentialService()->revoke($fixture['connection'], $fixture['credential'], 'revoked for test');

        $this->postWebhook('test', $headers, $body)->assertStatus(401);
    }

    public function test_disconnect_clears_the_plaintext_display_column_on_firm_integrations(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();

        $this->connectionService()->disconnect($fixture['connection'], $fixture['firmUser']->user_id);

        $fresh = $this->runWithFirmContext($fixture['firm'], fn () => $fixture['connection']->fresh());
        $this->assertNull($fresh->webhook_routing_token);
    }

    public function test_disconnect_clears_the_routing_index_row_in_the_same_transaction(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();

        $before = IntegrationWebhookRoutingIndex::query()->where('firm_integration_id', $fixture['connection']->id)->count();
        $this->assertSame(1, $before);

        $this->connectionService()->disconnect($fixture['connection'], $fixture['firmUser']->user_id);

        $after = IntegrationWebhookRoutingIndex::query()->where('firm_integration_id', $fixture['connection']->id)->count();
        $this->assertSame(0, $after, 'disconnect() must remove the routing-index row in the same transaction as clearing webhook_routing_token.');
    }

    public function test_the_old_token_no_longer_resolves_after_disconnect(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $this->connectionService()->disconnect($fixture['connection'], $fixture['firmUser']->user_id);

        $response = $this->postWebhook('test', $headers, $body);
        $response->assertStatus(401);
    }

    public function test_disable_webhook_routing_alone_also_clears_the_routing_index_row(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();

        $this->connectionService()->disableWebhookRouting($fixture['connection'], $fixture['firmUser']->user_id);

        $after = IntegrationWebhookRoutingIndex::query()->where('firm_integration_id', $fixture['connection']->id)->count();
        $this->assertSame(0, $after);

        $fresh = $this->runWithFirmContext($fixture['firm'], fn () => $fixture['connection']->fresh());
        $this->assertNull($fresh->webhook_routing_token);
    }

    public function test_disconnected_and_revoked_rejections_are_byte_identical_to_a_wrong_secret_rejection(): void
    {
        $fixtureDisconnected = $this->activeConnectionWithWebhookSecret();
        $fixtureRevoked = $this->activeConnectionWithWebhookSecret();

        $bodyDisconnected = $this->eventBody();
        $headersDisconnected = $this->signedHeaders($fixtureDisconnected['secret'], $fixtureDisconnected['rawToken'], $bodyDisconnected);
        $this->connectionService()->disconnect($fixtureDisconnected['connection'], $fixtureDisconnected['firmUser']->user_id);
        $disconnectedResponse = $this->postWebhook('test', $headersDisconnected, $bodyDisconnected);

        $bodyRevoked = $this->eventBody();
        $headersRevoked = $this->signedHeaders($fixtureRevoked['secret'], $fixtureRevoked['rawToken'], $bodyRevoked);
        $this->credentialService()->revoke($fixtureRevoked['connection'], $fixtureRevoked['credential'], 'test');
        $revokedResponse = $this->postWebhook('test', $headersRevoked, $bodyRevoked);

        $this->assertSame(401, $disconnectedResponse->getStatusCode());
        $this->assertSame(401, $revokedResponse->getStatusCode());
        $this->assertSame($disconnectedResponse->getContent(), $revokedResponse->getContent());
        $this->assertSame('{"status":"rejected"}', $disconnectedResponse->getContent());
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function activeConnectionWithWebhookSecret(): array
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $user = User::factory()->create();
        $firmUser = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->role(FirmUserRole::FirmOwner)->create());

        $connection = FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::Active->value]);
        $rawToken = $this->connectionService()->enableWebhookRouting($connection, $firmUser->user_id);

        $secret = 'wh-secret-'.Str::random(32);
        $credential = $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection->fresh(), CredentialType::WebhookSigningSecret, $secret));

        return [
            'firm' => $firm, 'connection' => $connection, 'rawToken' => $rawToken,
            'secret' => $secret, 'credential' => $credential, 'firmUser' => $firmUser,
        ];
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
            // Checkpoint 10 addition (frozen design §4): ProviderConnectionService's
            // constructor gained this 8th, required dependency — every
            // manual construction site in this file must supply it.
            app(IntegrationEntitlementPolicyService::class),
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
