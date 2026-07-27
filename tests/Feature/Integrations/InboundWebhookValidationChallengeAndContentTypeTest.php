<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\EntitlementSource;
use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsWebhooksContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\ResolvedWebhookConnection;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Http\Controllers\InboundWebhookController;
use App\Integrations\Jobs\RecordWebhookVerificationFailureJob;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\IntegrationOAuthStateService;
use App\Integrations\Services\ProviderConnectionService;
use App\Integrations\Services\WebhookConnectionResolverService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\PkceService;
use App\Integrations\Support\ProviderRedirectUrlValidator;
use App\Models\Firm;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\EntitlementService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * InboundWebhookValidationChallengeAndContentTypeTest — Checkpoint 1
 * (FirmsVault Live Integrations) coverage for:
 *  - the two new SupportsWebhooksContract methods
 *    (detectSubscriptionValidationChallenge()/extractRoutingIdentifier())
 *    and TestProvider's own implementations of them;
 *  - InboundWebhookController's new validation-challenge short-circuit
 *    branch (design §4/§5), using a small local provider double since
 *    TestProvider itself has no validation-challenge concept and always
 *    answers null;
 *  - the new content-type allowlist (design §6): JSON-family for the
 *    normal per-event pipeline, text/plain ONLY on the
 *    validation-challenge branch;
 *  - the regression this checkpoint's own diff review flagged as the
 *    "real bug" fixed by §1.4: a connection that is Active but has ZERO
 *    WebhookSigningSecret credentials must PROCEED to verification, not
 *    be auto-rejected the way `$candidates === []` used to unconditionally
 *    reject before this fix.
 */
final class InboundWebhookValidationChallengeAndContentTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $auditLogPath = storage_path('logs/integration-webhook-audit.log');
        if (file_exists($auditLogPath)) {
            @unlink($auditLogPath);
        }
    }

    // ------------------------------------------------------------
    // 1. TestProvider's own implementations of the two new contract
    // methods.
    // ------------------------------------------------------------

    public function test_test_provider_always_answers_null_for_a_validation_challenge(): void
    {
        $provider = new TestProvider;

        $this->assertNull($provider->detectSubscriptionValidationChallenge([], []));
        $this->assertNull($provider->detectSubscriptionValidationChallenge(['validationToken' => 'whatever'], ['x-some-header' => 'value']));
    }

    public function test_test_provider_extracts_the_routing_identifier_from_its_own_header_case_insensitively(): void
    {
        $provider = new TestProvider;

        $this->assertSame('raw-token-value', $provider->extractRoutingIdentifier('{}', ['x-test-provider-connection-token' => 'raw-token-value']));
        $this->assertSame('raw-token-value', $provider->extractRoutingIdentifier('{}', ['X-TEST-PROVIDER-CONNECTION-TOKEN' => 'raw-token-value']));
    }

    public function test_test_provider_extracts_null_when_the_routing_header_is_absent(): void
    {
        $provider = new TestProvider;

        $this->assertNull($provider->extractRoutingIdentifier('{}', []));
        $this->assertNull($provider->extractRoutingIdentifier('{}', ['x-completely-unrelated-header' => 'value']));
    }

    public function test_test_provider_extract_routing_identifier_never_throws_on_a_non_string_header_value(): void
    {
        $provider = new TestProvider;

        // $headers is documented as generic; a defensively-written
        // implementation must not blow up on an unexpected shape.
        $this->assertNull($provider->extractRoutingIdentifier('{}', ['x-test-provider-connection-token' => ['unexpected' => 'array']]));
    }

    // ------------------------------------------------------------
    // 2. Controller's validation-challenge short-circuit branch — a
    // local provider double is required here since TestProvider itself
    // has no validation-challenge concept.
    // ------------------------------------------------------------

    public function test_a_non_null_validation_challenge_short_circuits_with_the_providers_exact_body_status_and_content_type(): void
    {
        $this->registerChallengeProvider(['body' => 'DECODED-VALIDATION-TOKEN-123', 'status' => 200, 'content_type' => 'text/plain']);

        $response = $this->call('POST', '/webhooks/integrations/test?validationToken=DECODED-VALIDATION-TOKEN-123', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], '');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame('DECODED-VALIDATION-TOKEN-123', $response->getContent());
    }

    public function test_the_validation_challenge_branch_never_writes_a_receipt_or_event_row(): void
    {
        $this->registerChallengeProvider(['body' => 'tok', 'status' => 200, 'content_type' => 'text/plain']);

        $receiptCountBefore = DB::table('integration_webhook_receipts')->count();

        $this->call('POST', '/webhooks/integrations/test?validationToken=tok', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '')
            ->assertStatus(200);

        $receiptCountAfter = DB::table('integration_webhook_receipts')->count();
        $this->assertSame($receiptCountBefore, $receiptCountAfter, 'A validation-challenge response must never write a receipt row — there is no firm_integration_id to attribute it to at this point.');
    }

    public function test_the_validation_challenge_branch_logs_the_dedicated_audit_event(): void
    {
        $this->registerChallengeProvider(['body' => 'tok', 'status' => 200, 'content_type' => 'text/plain']);

        $this->call('POST', '/webhooks/integrations/test?validationToken=tok', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '')
            ->assertStatus(200);

        $logPath = storage_path('logs/integration-webhook-audit.log');
        $this->assertFileExists($logPath);
        $contents = (string) file_get_contents($logPath);
        $this->assertStringContainsString('integration_webhook.validation_challenge_answered', $contents);
    }

    public function test_the_validation_challenge_branch_still_rejects_a_disallowed_content_type(): void
    {
        // The challenge branch only accepts text/plain (Microsoft
        // Graph's own documented handshake content type) — anything
        // else, even though this IS a genuine validation challenge per
        // detectSubscriptionValidationChallenge()'s own return, must
        // still be rejected by the content-type allowlist.
        $this->registerChallengeProvider(['body' => 'tok', 'status' => 200, 'content_type' => 'text/plain']);

        $response = $this->call('POST', '/webhooks/integrations/test?validationToken=tok', [], [], [], [
            'CONTENT_TYPE' => 'application/xml',
        ], '');

        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);
    }

    public function test_a_null_validation_challenge_proceeds_to_the_normal_per_event_pipeline(): void
    {
        // Sanity control: the SAME provider double, when its
        // detectSubscriptionValidationChallenge() call is configured to
        // return null (no ?validationToken= present), must proceed
        // through the ordinary per-event pipeline exactly like
        // TestProvider does — proving the branch is a genuine "if
        // present, else fall through," not an unconditional short
        // circuit.
        $this->registerChallengeProvider(null);

        $response = $this->call('POST', '/webhooks/integrations/test', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        // No validationToken query param -> the double returns null ->
        // falls through to the ordinary pipeline -> rejected for an
        // unresolvable routing identifier (the double's
        // extractRoutingIdentifier() is a stub that always returns
        // null) -> the SAME collapsed 401, never the challenge response
        // shape.
        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);
    }

    // ------------------------------------------------------------
    // 3. Content-type allowlist for the normal per-event pipeline.
    // ------------------------------------------------------------

    public function test_application_json_is_accepted_for_the_normal_pipeline(): void
    {
        $fixture = $this->activeConnectionWithoutWebhookSecret();
        $response = $this->postWebhookWithContentType('test', $fixture, 'application/json');

        // No stored secret exists, so signature verification will fail
        // — but the point here is the request is NOT rejected for its
        // content type; it reaches the real per-event pipeline.
        $this->assertRequestReachedVerification();
        unset($response);
    }

    public function test_a_json_family_vendor_content_type_is_accepted_for_the_normal_pipeline(): void
    {
        $fixture = $this->activeConnectionWithoutWebhookSecret();
        $this->postWebhookWithContentType('test', $fixture, 'application/vnd.api+json');

        $this->assertRequestReachedVerification();
    }

    /**
     * A genuinely absent Content-Type header cannot be produced through
     * this test harness's HTTP surface at all: Symfony's
     * Request::create() (which both $this->call() and TestResponse
     * ultimately build on) defaults raw POST content with no explicit
     * CONTENT_TYPE server key to 'application/x-www-form-urlencoded' —
     * confirmed directly (`php -r '...Request::create(...)->headers->get("Content-Type")'`
     * during this checkpoint's own investigation) — which is itself
     * correctly REJECTED by the allowlist (it is not JSON-family). So
     * "missing Content-Type is leniently accepted" cannot be
     * HTTP-round-trip-tested without a false negative; it is proven
     * directly against the controller's own private
     * isAcceptableContentType() method instead, which is exactly the
     * unit this leniency rule lives in.
     */
    public function test_a_genuinely_missing_content_type_is_leniently_accepted_by_the_allowlist_method_directly(): void
    {
        $method = $this->isAcceptableContentTypeMethod();
        $controller = $this->app->make(InboundWebhookController::class);

        $this->assertTrue($method->invoke($controller, null, false), 'A null Content-Type must be leniently accepted for the normal per-event pipeline.');
        $this->assertTrue($method->invoke($controller, '', false), 'An empty-string Content-Type must be leniently accepted for the normal per-event pipeline.');
        $this->assertTrue($method->invoke($controller, '   ', false), 'A whitespace-only Content-Type must be leniently accepted for the normal per-event pipeline.');
        $this->assertTrue($method->invoke($controller, null, true), 'A null Content-Type must be leniently accepted on the validation-challenge branch too.');
    }

    public function test_the_content_type_allowlist_method_normalizes_case_and_strips_a_charset_parameter(): void
    {
        $method = $this->isAcceptableContentTypeMethod();
        $controller = $this->app->make(InboundWebhookController::class);

        $this->assertTrue($method->invoke($controller, 'APPLICATION/JSON', false), 'Content-Type matching must be case-insensitive.');
        $this->assertTrue($method->invoke($controller, 'application/json; charset=utf-8', false), 'A trailing charset parameter must not defeat the match.');
        $this->assertTrue($method->invoke($controller, 'TEXT/PLAIN; charset=utf-8', true), 'The validation-challenge branch\'s text/plain match must also be case-insensitive and charset-tolerant.');
        $this->assertFalse($method->invoke($controller, 'text/plain', false), 'text/plain must NOT be accepted on the normal per-event pipeline.');
        $this->assertFalse($method->invoke($controller, 'application/json', true), 'application/json must NOT be accepted on the validation-challenge branch — only text/plain is.');
    }

    public function test_a_disallowed_content_type_is_rejected_before_routing_resolution_on_the_normal_pipeline(): void
    {
        $fixture = $this->activeConnectionWithoutWebhookSecret();

        $capturedSql = [];
        DB::listen(function ($query) use (&$capturedSql) {
            $capturedSql[] = strtolower($query->sql);
        });

        $response = $this->postWebhookWithContentType('test', $fixture, 'application/x-www-form-urlencoded');

        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);

        $touchesRoutingIndex = array_filter($capturedSql, fn ($sql) => str_contains($sql, 'integration_webhook_routing_index'));
        $this->assertEmpty($touchesRoutingIndex, 'A disallowed content type must be rejected BEFORE routing-identifier resolution is even attempted.');
    }

    public function test_text_plain_is_rejected_on_the_normal_per_event_pipeline_not_only_the_challenge_branch(): void
    {
        $fixture = $this->activeConnectionWithoutWebhookSecret();
        $response = $this->postWebhookWithContentType('test', $fixture, 'text/plain');

        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);
    }

    // ------------------------------------------------------------
    // 4. Regression test — isConnectionActive()-based rejection: an
    // Active connection with ZERO WebhookSigningSecret credentials must
    // PROCEED to verification (design §1.4's real bug fix), never be
    // auto-rejected the way the old `$candidates === []` check did.
    // ------------------------------------------------------------

    public function test_an_active_connection_with_zero_webhook_signing_secret_credentials_proceeds_to_verification_not_auto_rejected(): void
    {
        Queue::fake();

        $fixture = $this->activeConnectionWithoutWebhookSecret();
        $body = json_encode(['event_id' => (string) Str::uuid(), 'event_type' => 'test.resource.created', 'payload' => []]);
        $timestamp = now()->getTimestamp();

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TEST_PROVIDER_CONNECTION_TOKEN' => $fixture['rawToken'],
            // A deliberately WRONG signature — with zero stored
            // candidates, TestProvider::verifyInboundSignature() falls
            // back to its own (freshly resolved, unrelated) instance
            // keys, so this can never verify. The point of this test is
            // NOT that verification succeeds — it's that the request
            // genuinely REACHES verification instead of being
            // short-circuited earlier for having no secret material.
            'HTTP_X_TEST_PROVIDER_SIGNATURE' => 'v1='.str_repeat('a', 64),
            'HTTP_X_TEST_PROVIDER_TIMESTAMP' => (string) $timestamp,
        ];

        $response = $this->call('POST', '/webhooks/integrations/test', [], [], [], $server, $body);

        $response->assertStatus(401);
        $response->assertExactJson(['status' => 'rejected']);

        // Distinguishing proof #1: the queued verification-failure job
        // was dispatched with failure_reason='signature_mismatch' (the
        // POST-verification rejection reason), never
        // 'disconnected_event_rejected' (the pre-verification,
        // isConnectionActive()=false reason this connection does NOT
        // hit, since it genuinely IS Active).
        Queue::assertPushed(RecordWebhookVerificationFailureJob::class, function (RecordWebhookVerificationFailureJob $job) {
            return $job->failureReason === 'signature_mismatch';
        });
        Queue::assertNotPushed(RecordWebhookVerificationFailureJob::class, function (RecordWebhookVerificationFailureJob $job) {
            return $job->failureReason === 'disconnected_event_rejected';
        });

        // Distinguishing proof #2: the audit log recorded
        // EVENT_SIGNATURE_VERIFIED's sibling rejection
        // (signature_rejected) WITH a firm_integration_id in context —
        // the shape only the POST-verification rejection branch
        // produces (InboundWebhookController::__invoke() step 8), never
        // the early rejectEarly() shape (provider-only context, no
        // firm_integration_id) — and the log never mentions
        // disconnected_event_rejected at all.
        $logPath = storage_path('logs/integration-webhook-audit.log');
        $this->assertFileExists($logPath);
        $contents = (string) file_get_contents($logPath);
        $this->assertStringNotContainsString('disconnected_event_rejected', $contents, 'An Active connection with zero WebhookSigningSecret credentials must never hit the disconnected/inactive rejection path.');
        $this->assertStringContainsString('"firm_integration_id":'.$fixture['connection']->id, $contents, 'The rejection must have happened AFTER routing identity was resolved and isConnectionActive() returned true (i.e. genuinely reached step 8, signature verification) — proving the request was not auto-rejected purely for lacking stored secret material.');
    }

    public function test_the_service_layer_is_connection_active_directly_confirms_the_regression_stays_fixed(): void
    {
        // Direct, unit-level proof at the exact seam the diff review's
        // §1.4 fix lives at — WebhookConnectionResolverService::isConnectionActive()
        // must return true for an Active connection regardless of
        // whether it has any WebhookSigningSecret credential rows at
        // all (that concern belongs entirely to
        // activeAndPreviousWebhookSecretsFor(), a separate method).
        $fixture = $this->activeConnectionWithoutWebhookSecret();

        $resolver = new WebhookConnectionResolverService(
            $this->credentialService(),
            new EmailBodyEncryptionService(new EncryptionKeyService),
            new TenantContextService,
        );

        $resolved = new ResolvedWebhookConnection(
            $fixture['connection']->firm_id,
            $fixture['connection']->id,
            $fixture['connection']->integration_provider_id,
            'test',
        );

        $this->assertTrue(
            $resolver->isConnectionActive($resolved),
            'isConnectionActive() must return true for an Active connection with zero WebhookSigningSecret credentials — conflating "no secret" with "not active" is exactly the bug design §1.4 fixed.'
        );
        $this->assertSame(
            [],
            $resolver->activeAndPreviousWebhookSecretsFor($resolved),
            'activeAndPreviousWebhookSecretsFor() legitimately still returns an empty array here — that emptiness must no longer be interpreted as a rejection reason by the controller.'
        );
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @param  array{body: string, status: int, content_type: string}|null  $challenge
     */
    private function registerChallengeProvider(?array $challenge): void
    {
        ValidationChallengeTestProviderDouble::$challengeToReturn = $challenge;

        config(['integrations.providers' => [ProviderKey::Test->value => ValidationChallengeTestProviderDouble::class]]);
    }

    /**
     * @return array{firm: Firm, connection: FirmIntegration, rawToken: string}
     */
    private function activeConnectionWithoutWebhookSecret(): array
    {
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);

        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        $connection = FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::Active->value]);
        $rawToken = $this->connectionService()->enableWebhookRouting($connection, $this->webhookRoutingActorUserId($connection));

        // Deliberately NO credentialService()->store(...) call — this
        // connection has zero WebhookSigningSecret credential rows,
        // which is the exact scenario design §1.4's fix concerns.
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

    /**
     * @param  array{firm: Firm, connection: FirmIntegration, rawToken: string}  $fixture
     */
    private function postWebhookWithContentType(string $provider, array $fixture, string $contentType): TestResponse
    {
        $body = json_encode(['event_id' => (string) Str::uuid(), 'event_type' => 'test.resource.created', 'payload' => []]);
        $timestamp = now()->getTimestamp();

        $server = [
            'CONTENT_TYPE' => $contentType,
            'HTTP_X_TEST_PROVIDER_CONNECTION_TOKEN' => $fixture['rawToken'],
            'HTTP_X_TEST_PROVIDER_SIGNATURE' => 'v1='.str_repeat('a', 64),
            'HTTP_X_TEST_PROVIDER_TIMESTAMP' => (string) $timestamp,
        ];

        return $this->call('POST', "/webhooks/integrations/{$provider}", [], [], [], $server, $body);
    }

    /**
     * Confirms the request reached the routing-resolution/verification
     * stage (route_identity_resolved was logged) rather than being
     * rejected purely for its content type.
     */
    private function assertRequestReachedVerification(): void
    {
        $logPath = storage_path('logs/integration-webhook-audit.log');
        $this->assertFileExists($logPath, 'Expected the audit log to exist — the request must have at least reached request_received.');
        $contents = (string) file_get_contents($logPath);
        $this->assertStringContainsString(
            'integration_webhook.route_identity_resolved',
            $contents,
            'The request must have reached routing-identity resolution — proving it was not rejected purely for its (acceptable) content type.'
        );
    }

    private function isAcceptableContentTypeMethod(): \ReflectionMethod
    {
        $method = new \ReflectionMethod(InboundWebhookController::class, 'isAcceptableContentType');
        $method->setAccessible(true);

        return $method;
    }
}

/**
 * A minimal, self-contained SupportsWebhooksContract double used only by
 * this file's validation-challenge branch tests — TestProvider itself
 * has no validation-challenge concept (always returns null), so a
 * controllable double is required to exercise
 * InboundWebhookController's generic branch (which must work for ANY
 * provider implementing the contract, not just TestProvider).
 * `$challengeToReturn` is static because ProviderRegistry::get()
 * resolves a fresh instance via the container per call — a
 * constructor-injected value would not survive that resolution.
 */
final class ValidationChallengeTestProviderDouble implements IntegrationProviderContract, SupportsWebhooksContract
{
    /**
     * @var array{body: string, status: int, content_type: string}|null
     */
    public static ?array $challengeToReturn = null;

    public function key(): ProviderKey
    {
        return ProviderKey::Test;
    }

    public function displayName(): string
    {
        return 'Validation Challenge Test Double';
    }

    public function description(): string
    {
        return 'Test-only double for validation-challenge branch coverage.';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportedAuthMethods(): array
    {
        return [AuthMethod::None];
    }

    public function webhookEventTypes(): array
    {
        return ['test.resource.created'];
    }

    public function verifyInboundSignature(string $rawBody, array $headers): bool
    {
        return false;
    }

    public function parseInboundEvent(string $rawBody, array $headers): array
    {
        return ['event_id' => null, 'event_type' => null, 'payload' => []];
    }

    public function subscribe(array $context): array
    {
        return [];
    }

    public function renewSubscription(array $context): array
    {
        return [];
    }

    public function detectSubscriptionValidationChallenge(array $queryParams, array $headers): ?array
    {
        return self::$challengeToReturn;
    }

    public function extractRoutingIdentifier(string $rawBody, array $headers): ?string
    {
        // Always null — this double is not used for the normal
        // per-event pipeline in this file, only the validation-challenge
        // branch and its own null-fallthrough control case.
        return null;
    }
}
