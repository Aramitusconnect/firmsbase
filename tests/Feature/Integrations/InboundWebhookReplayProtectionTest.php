<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\EntitlementSource;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Providers\TestProvider\TestProvider;
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
use App\Services\EntitlementService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

        // Checkpoint 1 (FirmsVault Live Integrations): InboundWebhookController
        // now resolves the provider via ProviderRegistry/ProviderKey FIRST,
        // before anything else — without this, every real HTTP request this
        // test makes through the controller collapses to a 401 regardless of
        // routing token/signature validity. Mirrors
        // InboundWebhookLifecycleRevalidationTest::setUp()'s identical,
        // already-established override.
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
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
        // Checkpoint 13 (frozen-test-closure-plan.md §4): freeze PHP time
        // for the whole test so the client-constructed header timestamp
        // (now()->subSeconds(300)) and the server's own internal
        // now()->getTimestamp() replay-window check resolve to the
        // IDENTICAL frozen instant, regardless of any real wall-clock
        // second-tick that would otherwise elapse between building the
        // header and the route handling it — which at the exact ±300s
        // boundary is the difference between 300s (accepted) and 301s
        // (rejected). Strengthens, never weakens, the boundary assertion.
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body, now()->subSeconds(300)->getTimestamp());

        $this->postWebhook('test', $headers, $body)->assertStatus(202);
    }

    public function test_a_timestamp_301_seconds_in_the_past_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body, now()->subSeconds(301)->getTimestamp());

        $this->postWebhook('test', $headers, $body)->assertStatus(401);
    }

    public function test_a_timestamp_exactly_300_seconds_in_the_future_is_accepted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body, now()->addSeconds(300)->getTimestamp());

        $this->postWebhook('test', $headers, $body)->assertStatus(202);
    }

    public function test_a_timestamp_301_seconds_in_the_future_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

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
        // Checkpoint 1 (design §6): the controller's new content-type
        // allowlist rejects Symfony's default 'application/x-www-form-urlencoded'
        // for raw POST content with no explicit Content-Type — every real
        // webhook sender sets this, so this is the correct fixture fix
        // (see InboundWebhookAuditLoggerTest::postWebhook() for the full
        // rationale).
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', "/webhooks/integrations/{$provider}", [], [], [], $server, $body);
    }
}
