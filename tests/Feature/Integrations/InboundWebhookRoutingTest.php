<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
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
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

/**
 * InboundWebhookRoutingTest — Checkpoint 7
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §1/§8).
 * Exercises `POST /webhooks/integrations/{provider}` end to end through
 * the real HTTP kernel — the wiring fix landed in bootstrap/app.php
 * (fix-diff-review.md) makes this route genuinely reachable.
 */
final class InboundWebhookRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_a_valid_provider_and_token_with_a_valid_signature_is_accepted_through_to_verification(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $response = $this->postWebhook('test', $headers, $body);

        $response->assertStatus(202);
        $response->assertExactJson(['status' => 'accepted']);
    }

    public function test_an_unregistered_provider_receives_the_generic_rejected_response(): void
    {
        $response = $this->postWebhook('unknownprovider', [
            'X-Test-Provider-Connection-Token' => Str::random(43),
            'X-Test-Provider-Signature' => 'v1='.str_repeat('a', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ], '{}');

        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);
    }

    public function test_a_malformed_provider_segment_never_reaches_the_webhook_specific_code(): void
    {
        // The route regex is [a-z0-9_]+ — uppercase/special characters
        // never match the route at all, producing a standard 404, never
        // the custom rejection envelope.
        $response = $this->postWebhook('Test!', [], '{}');

        $response->assertStatus(404);
    }

    public function test_a_missing_routing_token_header_is_rejected(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);
        unset($headers['X-Test-Provider-Connection-Token']);

        $response = $this->postWebhook('test', $headers, $body);

        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);
    }

    public function test_an_unknown_routing_token_is_rejected(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);
        $headers['X-Test-Provider-Connection-Token'] = Str::random(43);

        $response = $this->postWebhook('test', $headers, $body);

        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);
    }

    public function test_a_malformed_routing_token_is_rejected(): void
    {
        $response = $this->postWebhook('test', [
            'X-Test-Provider-Connection-Token' => '!!!not-a-real-token!!!',
            'X-Test-Provider-Signature' => 'v1='.str_repeat('a', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ], '{}');

        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);
    }

    public function test_a_token_issued_for_a_different_provider_is_rejected(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        // Deliberately NOT IntegrationProvider::factory()->create() —
        // that factory's default `code` contains hyphens (e.g.
        // "test-fixture-xxxx"), which the route's own [a-z0-9_]+
        // regex cannot match at all, producing an irrelevant 404
        // instead of exercising this test's actual scenario.
        $otherProvider = IntegrationProvider::factory()->create(['code' => 'otherprovider']);

        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $response = $this->postWebhook($otherProvider->code, $headers, $body);

        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);
    }

    public function test_a_token_belonging_to_another_connection_never_resolves_this_connections_identity(): void
    {
        $fixtureA = $this->activeConnectionWithWebhookSecret();
        $fixtureB = $this->activeConnectionWithWebhookSecret();

        // Sign with connection B's secret but present connection A's
        // routing token — resolves to A's identity, then fails
        // signature verification against A's own secret candidates.
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixtureB['secret'], $fixtureA['rawToken'], $body);

        $response = $this->postWebhook('test', $headers, $body);

        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);
    }

    public function test_a_token_superseded_by_re_enabling_webhook_routing_no_longer_resolves(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $oldToken = $fixture['rawToken'];

        // Re-enabling issues a brand-new token and removes the old
        // index row (ProviderConnectionService::enableWebhookRouting()).
        $this->connectionService()->enableWebhookRouting($fixture['connection']);

        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $oldToken, $body);

        $response = $this->postWebhook('test', $headers, $body);

        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);
    }

    public function test_a_duplicate_routing_token_header_is_rejected_not_resolved_to_either_value(): void
    {
        $fixtureA = $this->activeConnectionWithWebhookSecret();
        $fixtureB = $this->activeConnectionWithWebhookSecret();

        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixtureA['secret'], $fixtureA['rawToken'], $body);

        $response = $this->postWebhookWithDuplicateHeader(
            'test',
            $headers,
            $body,
            'X-Test-Provider-Connection-Token',
            [$fixtureA['rawToken'], $fixtureB['rawToken']],
        );

        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);
    }

    public function test_every_rejection_case_produces_the_byte_identical_response(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();

        $unknownProvider = $this->postWebhook('unknownprovider', [
            'X-Test-Provider-Connection-Token' => Str::random(43),
            'X-Test-Provider-Signature' => 'v1='.str_repeat('a', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ], $body);

        $unknownToken = $this->postWebhook('test', [
            'X-Test-Provider-Connection-Token' => Str::random(43),
            'X-Test-Provider-Signature' => 'v1='.str_repeat('a', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ], $body);

        $badSignature = $this->postWebhook('test', [
            'X-Test-Provider-Connection-Token' => $fixture['rawToken'],
            'X-Test-Provider-Signature' => 'v1='.str_repeat('b', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ], $body);

        foreach ([$unknownProvider, $unknownToken, $badSignature] as $response) {
            $response->assertStatus(401);
            $this->assertSame('{"status":"rejected"}', $response->getContent());
        }
    }

    public function test_guessing_never_creates_a_receipt_row_when_the_routing_token_never_resolves(): void
    {
        $before = DB::table('integration_webhook_receipts')->count();

        $this->postWebhook('test', [
            'X-Test-Provider-Connection-Token' => Str::random(43),
            'X-Test-Provider-Signature' => 'v1='.str_repeat('a', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ], '{}');

        $after = DB::table('integration_webhook_receipts')->count();

        $this->assertSame($before, $after);
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

    /**
     * Low-level helper: injects a header with MULTIPLE distinct values
     * (genuine repeated header lines) — something Laravel's ordinary
     * $server/HTTP_* test-call surface cannot represent, since PHP's
     * $_SERVER only ever holds one slot per header name.
     */
    private function postWebhookWithDuplicateHeader(string $provider, array $headers, string $body, string $duplicateHeaderName, array $duplicateValues): TestResponse
    {
        $server = [];
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, $duplicateHeaderName) === 0) {
                continue;
            }
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $symfonyRequest = SymfonyRequest::create(url("/webhooks/integrations/{$provider}"), 'POST', [], [], [], $server, $body);
        $symfonyRequest->headers->set($duplicateHeaderName, $duplicateValues, true);

        $request = \Illuminate\Http\Request::createFromBase($symfonyRequest);
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $this->createTestResponse($response, $request);
    }
}
