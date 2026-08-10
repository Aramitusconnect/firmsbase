<?php

declare(strict_types=1);

namespace Tests\Feature\Security\Hosts;

use App\Enums\EntitlementSource;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\ProviderConnectionService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\EntitlementService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * OAuthAndWebhookHostBoundaryTest — Mission 1 (canonical reconstruction),
 * test matrix items AR-AY (OAuth host boundary) and AZ-BC (webhook host
 * independence). These are narrow, mission-specific additions on top of
 * the extensive PRE-EXISTING OAuthConnectionControllerCallbackRouteTest /
 * InboundWebhookSignatureVerificationTest suites (both of which passed
 * unchanged after this mission's hostname migration — proving the
 * migration didn't regress the flows those files already cover in
 * depth). What is genuinely NEW here, and therefore not already proven
 * anywhere else, is host-BOUNDARY behavior specifically:
 *
 *  - AR/AS: the OAuth initiate/callback routes are now domain-bound to
 *    app.firmsvault.com (routes/web.php) — a request to either route on
 *    any OTHER canonical host must 404 (the route simply does not exist
 *    there), rather than silently succeeding on the wrong origin.
 *  - AZ-BC: routes/webhooks.php was deliberately left domain-UNconstrained
 *    (see that file's own docblock) — a validly signed inbound webhook
 *    must succeed identically no matter which Host header accompanies
 *    it, and signature verification must stay host-independent too.
 */
final class OAuthAndWebhookHostBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
    }

    // ============================================================
    // AR/AS — OAuth routes are domain-bound to the firm app host only.
    // ============================================================

    public function test_the_oauth_initiate_route_does_not_exist_on_the_admin_host(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $response = $this->get($this->adminUrl('/integrations/oauth/'.$connection->id.'/initiate'));

        $response->assertNotFound();
    }

    public function test_the_oauth_initiate_route_does_not_exist_on_the_client_portal_host(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $response = $this->get($this->clientPortalUrl('/integrations/oauth/'.$connection->id.'/initiate'));

        $response->assertNotFound();
    }

    public function test_the_oauth_callback_route_does_not_exist_on_the_admin_host(): void
    {
        $response = $this->get($this->adminUrl('/integrations/oauth/callback?state=x&code=y'));

        $response->assertNotFound();
    }

    public function test_the_oauth_initiate_route_resolves_on_the_firm_app_host(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $response = $this->actingAs($user)->get($this->firmAppUrl('/integrations/oauth/'.$connection->id.'/initiate'));

        // Not a 404 — the route exists on this host; the actual redirect
        // target/outcome is the pre-existing suite's own concern.
        $this->assertNotSame(404, $response->getStatusCode());
    }

    // ============================================================
    // AZ-BC — inbound webhook route is deliberately host-unconstrained.
    // ============================================================

    public function test_a_validly_signed_webhook_succeeds_on_the_marketing_host(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $this->postWebhookOnHost($this->marketingUrl(''), $headers, $body)->assertStatus(202);
    }

    public function test_a_validly_signed_webhook_succeeds_on_the_admin_host(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $this->postWebhookOnHost($this->adminUrl(''), $headers, $body)->assertStatus(202);
    }

    public function test_a_validly_signed_webhook_succeeds_on_an_arbitrary_unrecognized_host(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        // Deliberate: a real webhook provider's outbound IP/hostname is
        // never one of FirmsVault's own six canonical hosts — this proves
        // TrustHosts (host-only in non-local/testing) never gets a chance
        // to interfere with this route, since it is required outside the
        // `web` group entirely (see routes/webhooks.php's own docblock).
        $this->postWebhookOnHost('http://some-provider-outbound.example', $headers, $body)->assertStatus(202);
    }

    public function test_an_invalid_signature_is_rejected_regardless_of_host(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);
        $headers['X-Test-Provider-Signature'] = 'v1='.str_repeat('9', 64);

        $this->postWebhookOnHost($this->adminUrl(''), $headers, $body)->assertStatus(401);
    }

    // ============================================================
    // Helpers (mirrors InboundWebhookSignatureVerificationTest's own).
    // ============================================================

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
        return app(ProviderConnectionService::class);
    }

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

    private function postWebhookOnHost(string $baseUrl, array $headers, string $body): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', rtrim($baseUrl, '/').'/webhooks/integrations/test', [], [], [], $server, $body);
    }
}
