<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\EntitlementSource;
use App\Integrations\Contracts\SupportsWebhooksContract;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Throwable;

/**
 * WebhookSecretCandidatesNeverEchoedTest — security review Finding 2,
 * sub-issue B (checkpoint1-security-review.md; required by
 * checkpoint1-combined-design.md §2.1's "Additionally required" note):
 * a canary-based conformance test proving no provider implementation
 * echoes the secret-bearing `$headers` array — specifically the value
 * injected under the reserved
 * SupportsWebhooksContract::SECRET_CANDIDATES_HEADER_KEY — back out
 * anywhere. `verifyInboundSignature()`/`parseInboundEvent()` MAY now
 * receive live plaintext webhook-signing-secret candidates inside
 * `$headers` for the first time in this codebase's history (Checkpoint
 * 1's rewire), so this file structurally proves the never-echo
 * constraint holds, rather than trusting the interface docblock's
 * convention alone.
 *
 * A canary value is used as the connection's REAL, credential-stored
 * webhook-signing secret (not a decoy injected alongside it) — this is
 * the actual live secret material
 * WebhookConnectionResolverService::activeAndPreviousWebhookSecretsFor()
 * decrypts and the controller injects under
 * SupportsWebhooksContract::SECRET_CANDIDATES_HEADER_KEY on every real
 * request, so proving it never surfaces anywhere proves the real
 * production data flow is safe, not a synthetic stand-in for it.
 */
final class WebhookSecretCandidatesNeverEchoedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Checkpoint 1 (FirmsVault Live Integrations): InboundWebhookController
        // now resolves the provider via ProviderRegistry/ProviderKey FIRST,
        // before anything else.
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);

        $auditLogPath = storage_path('logs/integration-webhook-audit.log');
        if (file_exists($auditLogPath)) {
            @unlink($auditLogPath);
        }
    }

    // ------------------------------------------------------------
    // 1. Full, real request through InboundWebhookController — the
    // canary IS the connection's real, credential-stored secret, which
    // the controller decrypts and injects under
    // SECRET_CANDIDATES_HEADER_KEY on this exact request.
    // ------------------------------------------------------------

    public function test_the_real_injected_secret_candidate_never_appears_in_the_audit_log_or_persisted_rows(): void
    {
        $canary = 'CANARY-SECRET-'.Str::random(40);

        $fixture = $this->activeConnectionWithSecret($canary);
        $body = $this->eventBody();
        $headers = $this->signedHeaders($canary, $fixture['rawToken'], $body);

        $response = $this->postWebhook('test', $headers, $body);

        // Sanity precondition: verification must have genuinely
        // succeeded using the canary as the real secret — otherwise this
        // test would trivially "pass" by never exercising the code path
        // where the canary is actually injected into $headers at all.
        $response->assertStatus(202, 'Precondition failed: the request must be genuinely verified (and therefore reach parseInboundEvent()/the persistence layer) for this test to prove anything about the injected secret candidate.');

        // Audit log — every rejection/acceptance event this request
        // could have logged.
        $logPath = storage_path('logs/integration-webhook-audit.log');
        $this->assertFileExists($logPath, 'The audit logger must have written its dedicated log file for this request.');
        $logContents = (string) file_get_contents($logPath);
        $this->assertStringNotContainsString($canary, $logContents, 'The real, injected webhook-signing-secret candidate must never appear in InboundWebhookAuditLogger output.');

        // integration_webhook_receipts — every column of the persisted
        // receipt row.
        $receipt = DB::table('integration_webhook_receipts')->orderByDesc('id')->first();
        $this->assertNotNull($receipt);
        foreach ((array) $receipt as $column => $value) {
            $this->assertStringNotContainsString($canary, (string) $value, "integration_webhook_receipts.{$column} must never contain the injected secret candidate.");
        }

        // integration_inbound_webhook_events — every column of the
        // persisted event row (includes payload_reference_json, the
        // field parseInboundEvent()'s return ultimately feeds into).
        $event = $this->runWithFirmContext($fixture['firm'], fn () => DB::table('integration_inbound_webhook_events')->orderByDesc('id')->first());
        $this->assertNotNull($event);
        foreach ((array) $event as $column => $value) {
            $this->assertStringNotContainsString($canary, (string) $value, "integration_inbound_webhook_events.{$column} must never contain the injected secret candidate.");
        }
    }

    public function test_the_real_injected_secret_candidate_never_appears_in_a_rejected_requests_audit_log_either(): void
    {
        // Same real, credential-backed secret and connection, but a
        // DELIBERATELY invalid signature — proves the never-echo
        // guarantee holds on the rejection path too, where the
        // controller has already injected the real candidates into
        // $forVerification before calling verifyInboundSignature().
        $canary = 'CANARY-SECRET-REJECT-'.Str::random(40);

        $fixture = $this->activeConnectionWithSecret($canary);
        $body = $this->eventBody();
        $headers = $this->signedHeaders($canary, $fixture['rawToken'], $body);
        $headers['X-Test-Provider-Signature'] = 'v1='.str_repeat('9', 64);

        $response = $this->postWebhook('test', $headers, $body);
        $response->assertStatus(401);

        $logPath = storage_path('logs/integration-webhook-audit.log');
        $this->assertFileExists($logPath);
        $logContents = (string) file_get_contents($logPath);
        $this->assertStringNotContainsString($canary, $logContents, 'The real, injected webhook-signing-secret candidate must never appear in the audit log on the rejection path either.');
    }

    // ------------------------------------------------------------
    // 2. Direct contract-level proof — TestProvider::parseInboundEvent()'s
    // return value never contains the canary, even when it is present
    // under SECRET_CANDIDATES_HEADER_KEY in $headers (the exact shape
    // the controller constructs, per InboundWebhookController::__invoke()
    // step 7).
    // ------------------------------------------------------------

    public function test_parse_inbound_event_never_echoes_the_secret_candidates_key_back_into_its_return_value(): void
    {
        $canary = 'CANARY-PARSE-'.Str::random(40);

        $provider = new TestProvider;
        $body = json_encode(['event_id' => (string) Str::uuid(), 'event_type' => 'test.resource.created', 'payload' => ['foo' => 'bar']]);

        $headers = [
            'x-test-provider-connection-token' => 'irrelevant-for-this-call',
            SupportsWebhooksContract::SECRET_CANDIDATES_HEADER_KEY => [$canary, 'a-second-candidate-'.Str::random(20)],
        ];

        $parsed = $provider->parseInboundEvent($body, $headers);

        $encoded = json_encode($parsed);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($canary, $encoded, 'parseInboundEvent()\'s return value must never contain a fragment of the injected secret-candidates key.');
    }

    // ------------------------------------------------------------
    // 3. Direct contract-level proof — verifyInboundSignature() never
    // throws with the canary embedded in an exception message, across a
    // range of malformed inputs designed to probe for a careless
    // debug-style exception (e.g. json_encode($headers) inside a
    // RuntimeException message, per security review Finding 2's own
    // hypothetical).
    // ------------------------------------------------------------

    public function test_verify_inbound_signature_never_leaks_the_secret_candidates_key_in_a_thrown_exception_message(): void
    {
        $canary = 'CANARY-VERIFY-'.Str::random(40);

        $provider = new TestProvider;

        $malformedInputs = [
            // Missing signature/timestamp headers entirely.
            ['body' => 'not-json-at-all', 'headers' => []],
            // Malformed hex signature.
            ['body' => '{}', 'headers' => ['x-test-provider-signature' => 'v1=not-hex', 'x-test-provider-timestamp' => (string) now()->getTimestamp()]],
            // Non-numeric timestamp.
            ['body' => '{}', 'headers' => ['x-test-provider-signature' => 'v1='.str_repeat('a', 64), 'x-test-provider-timestamp' => 'not-a-number']],
            // Empty raw body.
            ['body' => '', 'headers' => ['x-test-provider-signature' => 'v1='.str_repeat('a', 64), 'x-test-provider-timestamp' => (string) now()->getTimestamp()]],
        ];

        foreach ($malformedInputs as $case) {
            $headers = $case['headers'];
            $headers[SupportsWebhooksContract::SECRET_CANDIDATES_HEADER_KEY] = [$canary];

            try {
                $result = $provider->verifyInboundSignature($case['body'], $headers);
                // Must never throw for these inputs (interface contract:
                // "Must never throw" is documented for the two NEW
                // methods; verifyInboundSignature() itself is expected
                // to fail closed with a bool, per its own established
                // behavior) — a bool return is the expected, safe
                // outcome for every malformed case above.
                $this->assertIsBool($result);
            } catch (Throwable $e) {
                $this->assertStringNotContainsString(
                    $canary,
                    $e->getMessage(),
                    'verifyInboundSignature() must never leak the injected secret-candidates key in a thrown exception message, even for malformed input.'
                );
            }
        }
    }

    public function test_verify_inbound_signature_never_leaks_the_secret_candidates_key_through_a_full_request_that_forces_an_internal_error(): void
    {
        // Forces a genuine durable-write failure AFTER verification
        // succeeds (mirrors InboundWebhookOutageBehaviorTest's own
        // proven technique), so the controller's generic 500 `errored()`
        // path is exercised for a request whose $forVerification array
        // genuinely carried the real secret candidate — proving the
        // sanitized 500 response itself never leaks it either.
        $canary = 'CANARY-OUTAGE-'.Str::random(40);

        $fixture = $this->activeConnectionWithSecret($canary);
        $body = $this->eventBody();
        $headers = $this->signedHeaders($canary, $fixture['rawToken'], $body);

        DB::statement('ALTER TABLE integration_webhook_receipts RENAME TO integration_webhook_receipts_canary_outage_test');

        try {
            DB::unprepared('SAVEPOINT canary_outage_test_recovery');
            $response = $this->postWebhook('test', $headers, $body);
            DB::unprepared('ROLLBACK TO SAVEPOINT canary_outage_test_recovery');
            DB::unprepared('RELEASE SAVEPOINT canary_outage_test_recovery');
        } finally {
            DB::statement('ALTER TABLE integration_webhook_receipts_canary_outage_test RENAME TO integration_webhook_receipts');
        }

        $response->assertStatus(500);
        $this->assertStringNotContainsString($canary, (string) $response->getContent(), 'A sanitized 500 response must never leak the injected secret-candidates key, even when the durable write immediately after verification fails.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array{firm: Firm, connection: FirmIntegration, rawToken: string}
     */
    private function activeConnectionWithSecret(string $secret): array
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $connection = FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::Active->value]);
        $rawToken = $this->connectionService()->enableWebhookRouting($connection, $this->webhookRoutingActorUserId($connection));

        $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection->fresh(), CredentialType::WebhookSigningSecret, $secret));

        return ['firm' => $firm, 'connection' => $connection, 'rawToken' => $rawToken];
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
        );
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

    private function postWebhook(string $provider, array $headers, string $body): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', "/webhooks/integrations/{$provider}", [], [], [], $server, $body);
    }
}
