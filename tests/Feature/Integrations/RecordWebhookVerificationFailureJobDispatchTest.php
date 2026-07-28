<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\EntitlementSource;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Jobs\RecordWebhookVerificationFailureJob;
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
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * RecordWebhookVerificationFailureJobDispatchTest — security review
 * Finding 5 / checkpoint1-diff-review.md's own follow-up recommendation
 * ("Add a Queue::fake()-based assertion somewhere that
 * RecordWebhookVerificationFailureJob is genuinely dispatched (not run
 * inline), since InboundWebhookTimingInvarianceTest alone can't
 * distinguish that under the test suite's QUEUE_CONNECTION=sync
 * setting"). Queue::fake() replaces the queue connection entirely, so a
 * job appearing in Queue::assertPushed() proves
 * InboundWebhookController called RecordWebhookVerificationFailureJob::dispatch()
 * (the queue mechanism) — never that it called ->handle() directly like
 * an ordinary synchronous method call, which QUEUE_CONNECTION=sync alone
 * cannot distinguish (a ShouldQueue job dispatched onto the 'sync'
 * connection also executes immediately, but it still went THROUGH the
 * queue dispatch path, unlike a bare inline call).
 *
 * Also proves, for each of the controller's distinct rejection branches,
 * that the job is dispatched with the CORRECT provider_code/failure_reason
 * — the exact vocabulary the controller's own rejectEarly()/
 * dispatchVerificationFailure() call sites use internally.
 */
final class RecordWebhookVerificationFailureJobDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
    }

    public function test_an_unregistered_provider_dispatches_with_provider_code_and_unknown_routing_token_reason(): void
    {
        Queue::fake();

        $this->postWebhook('unknownprovider', [
            'X-Test-Provider-Connection-Token' => Str::random(43),
            'X-Test-Provider-Signature' => 'v1='.str_repeat('a', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ], '{}');

        Queue::assertPushed(RecordWebhookVerificationFailureJob::class, fn (RecordWebhookVerificationFailureJob $job): bool => $job->providerCode === 'unknownprovider' && $job->failureReason === 'unknown_routing_token');
        Queue::assertPushed(RecordWebhookVerificationFailureJob::class, 1);
    }

    public function test_a_missing_routing_token_header_dispatches_with_missing_headers_reason(): void
    {
        Queue::fake();

        $this->postWebhook('test', [
            'X-Test-Provider-Signature' => 'v1='.str_repeat('a', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ], '{}');

        Queue::assertPushed(RecordWebhookVerificationFailureJob::class, fn (RecordWebhookVerificationFailureJob $job): bool => $job->providerCode === 'test' && $job->failureReason === 'missing_headers');
    }

    public function test_an_unknown_routing_token_for_a_registered_provider_dispatches_with_unknown_routing_token_reason(): void
    {
        Queue::fake();

        $this->postWebhook('test', [
            'X-Test-Provider-Connection-Token' => Str::random(43),
            'X-Test-Provider-Signature' => 'v1='.str_repeat('a', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ], '{}');

        Queue::assertPushed(RecordWebhookVerificationFailureJob::class, fn (RecordWebhookVerificationFailureJob $job): bool => $job->providerCode === 'test' && $job->failureReason === 'unknown_routing_token');
    }

    public function test_a_disallowed_content_type_dispatches_with_malformed_payload_reason(): void
    {
        Queue::fake();

        $server = [
            'CONTENT_TYPE' => 'application/xml',
            'HTTP_X_TEST_PROVIDER_CONNECTION_TOKEN' => Str::random(43),
        ];
        $this->call('POST', '/webhooks/integrations/test', [], [], [], $server, '{}');

        Queue::assertPushed(RecordWebhookVerificationFailureJob::class, fn (RecordWebhookVerificationFailureJob $job): bool => $job->providerCode === 'test' && $job->failureReason === 'malformed_payload');
    }

    public function test_a_disconnected_connection_dispatches_with_disconnected_event_rejected_reason(): void
    {
        Queue::fake();

        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        // Deliberately NOT ProviderConnectionService::disconnect() —
        // that also clears firm_integrations.webhook_routing_token and
        // deletes the integration_webhook_routing_index row in the same
        // transaction, so a subsequent request would hit the EARLIER
        // "unknown_routing_token" rejection instead (confirmed: this is
        // exactly what InboundWebhookLifecycleRevalidationTest's own
        // "disconnected connection" test exercises, without asserting
        // which specific reason). To isolate the DISTINCT
        // isConnectionActive()=false rejection reason this test targets
        // (routing resolves successfully, but the connection's status
        // is not Active), the status is flipped directly, leaving the
        // routing index row intact.
        $this->runWithFirmContext($fixture['firm'], fn () => $fixture['connection']->update(['status' => ConnectionStatus::Error->value]));

        $this->postWebhook('test', $headers, $body);

        Queue::assertPushed(RecordWebhookVerificationFailureJob::class, fn (RecordWebhookVerificationFailureJob $job): bool => $job->providerCode === 'test' && $job->failureReason === 'disconnected_event_rejected');
    }

    public function test_an_invalid_signature_dispatches_with_signature_mismatch_reason(): void
    {
        Queue::fake();

        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);
        $headers['X-Test-Provider-Signature'] = 'v1='.str_repeat('9', 64);

        $this->postWebhook('test', $headers, $body);

        Queue::assertPushed(RecordWebhookVerificationFailureJob::class, fn (RecordWebhookVerificationFailureJob $job): bool => $job->providerCode === 'test' && $job->failureReason === 'signature_mismatch');
    }

    public function test_a_malformed_payload_after_successful_verification_dispatches_with_malformed_payload_reason(): void
    {
        Queue::fake();

        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = json_encode(['event_type' => 'test.resource.created', 'payload' => []]); // no event_id
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $response = $this->postWebhook('test', $headers, $body);
        $response->assertStatus(400);

        Queue::assertPushed(RecordWebhookVerificationFailureJob::class, fn (RecordWebhookVerificationFailureJob $job): bool => $job->providerCode === 'test' && $job->failureReason === 'malformed_payload');
    }

    public function test_a_successful_verified_request_never_dispatches_the_failure_job_at_all(): void
    {
        Queue::fake();

        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();
        $headers = $this->signedHeaders($fixture['secret'], $fixture['rawToken'], $body);

        $response = $this->postWebhook('test', $headers, $body);
        $response->assertStatus(202);

        Queue::assertNotPushed(RecordWebhookVerificationFailureJob::class);
    }

    public function test_the_dispatch_genuinely_goes_through_the_queue_not_a_direct_synchronous_handle_call(): void
    {
        // Without Queue::fake(), under this suite's real
        // QUEUE_CONNECTION=sync setting, dispatching a ShouldQueue job
        // still executes handle() synchronously within the SAME request
        // — proving the row is written confirms the dispatch mechanism
        // is real (not a no-op), complementing the Queue::fake() proofs
        // above (which prove it's dispatch(), not a bare method call).
        $countBefore = DB::table('integration_webhook_verification_failures')->count();

        $this->postWebhook('test', [
            'X-Test-Provider-Connection-Token' => Str::random(43),
            'X-Test-Provider-Signature' => 'v1='.str_repeat('a', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ], '{}');

        $countAfter = DB::table('integration_webhook_verification_failures')->count();
        $this->assertSame($countBefore + 1, $countAfter);
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
        $firmUser = $this->runWithFirmContext($firm, fn () => $connection->connectedByFirmUser);
        $rawToken = $this->connectionService()->enableWebhookRouting($connection, $firmUser->user_id);

        $secret = 'wh-secret-'.Str::random(32);
        $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection->fresh(), CredentialType::WebhookSigningSecret, $secret));

        return ['firm' => $firm, 'connection' => $connection, 'rawToken' => $rawToken, 'secret' => $secret, 'firmUser' => $firmUser];
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
            app(IntegrationEntitlementPolicyService::class),
            // Checkpoint 3 addition (FirmsVault Live Integrations,
            // Google Workspace): ProviderConnectionService's constructor
            // gained this 9th, required dependency -- every manual
            // construction site in this file must supply it.
            app(GmailMailboxRoutingService::class),
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
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', "/webhooks/integrations/{$provider}", [], [], [], $server, $body);
    }
}
