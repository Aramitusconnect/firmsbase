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
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

/**
 * InboundWebhookSignatureVerificationTest — Checkpoint 7
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §8),
 * exercised through the real HTTP route.
 */
final class InboundWebhookSignatureVerificationTest extends TestCase
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

    public function test_a_valid_signature_is_accepted(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $this->postWebhook('test', $headers, $body)->assertStatus(202);
    }

    public function test_an_invalid_signature_is_rejected(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);
        $headers['X-Test-Provider-Signature'] = 'v1='.str_repeat('9', 64);

        $response = $this->postWebhook('test', $headers, $body);
        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);
    }

    public function test_flipping_a_single_byte_in_the_raw_body_after_signing_invalidates_the_signature(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $mutated = $body;
        $pos = strpos($mutated, '"foo"');
        $mutated = substr_replace($mutated, 'X', $pos, 1);
        $this->assertNotSame($body, $mutated);

        $response = $this->postWebhook('test', $headers, $mutated);
        $response->assertStatus(401);
    }

    public function test_an_altered_timestamp_is_rejected(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);
        $headers['X-Test-Provider-Timestamp'] = (string) (((int) $headers['X-Test-Provider-Timestamp']) + 5);

        $this->postWebhook('test', $headers, $body)->assertStatus(401);
    }

    public function test_a_json_reserialization_with_different_key_ordering_invalidates_the_signature(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $eventId = (string) Str::uuid();

        $original = '{"event_id":"'.$eventId.'","event_type":"test.resource.created","payload":{}}';
        $reserialized = '{"payload":{},"event_type":"test.resource.created","event_id":"'.$eventId.'"}';

        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $original);

        $response = $this->postWebhook('test', $headers, $reserialized);
        $response->assertStatus(401, 'Signature verification must operate on the exact raw bytes, never a re-parsed/re-encoded logical equivalent.');
    }

    public function test_malformed_hex_is_rejected_without_crashing(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);
        $headers['X-Test-Provider-Signature'] = 'v1=not-valid-hex-content-at-all';

        $response = $this->postWebhook('test', $headers, $body);
        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);
    }

    public function test_a_wrong_algorithm_prefix_is_rejected(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $timestamp = now()->getTimestamp();
        $hex = hash_hmac('sha256', 'v1:'.$timestamp.'.'.$body, $fixture['secret']);

        $headers = [
            'X-Test-Provider-Connection-Token' => $fixture['rawToken'],
            'X-Test-Provider-Signature' => 'v2='.$hex,
            'X-Test-Provider-Timestamp' => (string) $timestamp,
        ];

        $this->postWebhook('test', $headers, $body)->assertStatus(401);
    }

    public function test_a_wrong_secret_is_rejected(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders('a-completely-unrelated-secret', $fixture['rawToken'], $body);

        $this->postWebhook('test', $headers, $body)->assertStatus(401);
    }

    public function test_the_signature_header_present_twice_is_rejected(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);
        $validSignature = $headers['X-Test-Provider-Signature'];

        $response = $this->postWebhookWithDuplicateHeader(
            'test', $headers, $body, 'X-Test-Provider-Signature', [$validSignature, $validSignature]
        );

        $response->assertStatus(401, 'A duplicate header must be rejected outright, never resolved by picking one value, even if both values are identical.');
    }

    public function test_the_timestamp_header_present_twice_is_rejected(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);
        $validTimestamp = $headers['X-Test-Provider-Timestamp'];

        $response = $this->postWebhookWithDuplicateHeader(
            'test', $headers, $body, 'X-Test-Provider-Timestamp', [$validTimestamp, $validTimestamp]
        );

        $response->assertStatus(401);
    }

    public function test_a_comma_folded_signature_value_is_rejected_at_format_validation(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);
        $headers['X-Test-Provider-Signature'] = $headers['X-Test-Provider-Signature'].','.$headers['X-Test-Provider-Signature'];

        $this->postWebhook('test', $headers, $body)->assertStatus(401);
    }

    public function test_a_case_varied_header_name_is_still_recognized(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $server = [
            // Checkpoint 1 (design §6): explicit Content-Type, otherwise
            // Symfony defaults raw POST content to
            // 'application/x-www-form-urlencoded', which the controller's
            // new content-type allowlist now correctly rejects (see
            // postWebhook()'s own docblock below for the full rationale).
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TEST_PROVIDER_CONNECTION_TOKEN' => $headers['X-Test-Provider-Connection-Token'],
            'HTTP_X_TEST_PROVIDER_SIGNATURE' => $headers['X-Test-Provider-Signature'],
            'HTTP_X_TEST_PROVIDER_TIMESTAMP' => $headers['X-Test-Provider-Timestamp'],
        ];

        // PHP's HTTP_* server-var convention already normalizes header
        // NAME casing before it ever reaches Symfony's HeaderBag — this
        // asserts the request succeeds regardless, proving lookup is
        // not accidentally case-sensitive at any layer this test can
        // reach.
        $response = $this->call('POST', '/webhooks/integrations/test', [], [], [], $server, $body);
        $response->assertStatus(202);
    }

    public function test_an_unexpected_extra_header_does_not_affect_the_outcome_either_way(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);
        $headers['X-Some-Unexpected-Header'] = 'irrelevant-value';

        $this->postWebhook('test', $headers, $body)->assertStatus(202);
    }

    // ------------------------------------------------------------
    // Cross-firm secret isolation
    // ------------------------------------------------------------

    public function test_firm_bs_signature_computed_with_firm_bs_real_secret_never_verifies_against_firm_as_connection(): void
    {
        $firmA = $this->activeConnectionWithWebhookSecret();
        $firmB = $this->activeConnectionWithWebhookSecret();

        $body = $this->eventBody();
        $headers = $this->signedHeaders($firmB['secret'], $firmA['rawToken'], $body);

        $this->postWebhook('test', $headers, $body)->assertStatus(401);
    }

    public function test_a_session_with_no_tenant_context_cannot_read_integration_credentials_even_knowing_a_stolen_routing_token_hash(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();

        (new TenantContextService)->clearDatabaseTenantContext();

        $rows = DB::table('integration_credentials')
            ->where('firm_integration_id', $fixture['connection']->id)
            ->get();

        $this->assertCount(0, $rows, 'FORCE RLS on integration_credentials must deny an ordinary no-context session, independent of anything known about routing.');
    }

    public function test_a_different_firms_context_cannot_read_this_connections_credential_row(): void
    {
        $fixtureA = $this->activeConnectionWithWebhookSecret();
        $fixtureB = $this->activeConnectionWithWebhookSecret();

        $rows = $this->runWithFirmContext($fixtureB['firm'], fn () => DB::table('integration_credentials')
            ->where('firm_integration_id', $fixtureA['connection']->id)
            ->get());

        $this->assertCount(0, $rows);
    }

    public function test_resolving_connection_identity_for_the_route_never_queries_integration_credentials_before_context_is_set(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        // Deliberately an UNKNOWN token: the early-exit path never
        // resolves a connection, so it must never touch
        // integration_credentials regardless.
        $headers['X-Test-Provider-Connection-Token'] = Str::random(43);

        $capturedSql = [];
        DB::listen(function ($query) use (&$capturedSql) {
            $capturedSql[] = strtolower($query->sql);
        });

        $this->postWebhook('test', $headers, $body);

        $touchesCredentials = array_filter($capturedSql, fn ($sql) => str_contains($sql, 'integration_credentials'));
        $this->assertEmpty($touchesCredentials);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array{firm: Firm, connection: FirmIntegration, rawToken: string, secret: string}
     */
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
        // webhook sender sets this, so this is the correct fixture fix.
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', "/webhooks/integrations/{$provider}", [], [], [], $server, $body);
    }

    private function postWebhookWithDuplicateHeader(string $provider, array $headers, string $body, string $duplicateHeaderName, array $duplicateValues): TestResponse
    {
        // Checkpoint 1: same content-type-allowlist fixture fix as
        // postWebhook() above — without it, every one of this helper's
        // callers would collapse to the content-type rejection BEFORE
        // ever reaching the duplicate-header comparison this helper
        // exists to exercise.
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, $duplicateHeaderName) === 0) {
                continue;
            }
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $symfonyRequest = SymfonyRequest::create(url("/webhooks/integrations/{$provider}"), 'POST', [], [], [], $server, $body);
        $symfonyRequest->headers->set($duplicateHeaderName, $duplicateValues, true);

        $request = Request::createFromBase($symfonyRequest);
        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $this->createTestResponse($response, $request);
    }
}
