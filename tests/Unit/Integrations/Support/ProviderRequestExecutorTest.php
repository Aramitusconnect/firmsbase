<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Support;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Exceptions\ProviderEnvironmentMisconfiguredException;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConnectionHealth;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\IntegrationUsageRecord;
use App\Integrations\Support\ProviderRequestExecutor;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ProviderRequestExecutorTest — Checkpoint 1 (FirmsVault Live
 * Integrations). Core coverage for
 * App\Integrations\Support\ProviderRequestExecutor per
 * checkpoint1-design-http-ratelimit-usage.md §6.1, corrected/extended
 * per checkpoint1-security-review.md Findings 1 and 4 and
 * checkpoint1-combined-design.md §1's five-step ordering.
 *
 * Every test opens with Http::fake([...]) — mandatory now that
 * tests/TestCase.php's suite-wide Http::preventStrayRequests() guard
 * (set in setUp()) fails any request that doesn't match a registered
 * fake rule.
 *
 * A single sandbox base URL is configured for ProviderKey::Test in
 * every test (via configureSandboxEnvironment()) — ProviderRequestExecutor::send()'s
 * first step (the environment/URL guard) throws
 * ProviderEnvironmentMisconfiguredException for ANY call when
 * `integrations.provider_environments.test` has no entry at all (the
 * out-of-the-box config default is an empty array), so every
 * non-environment-guard-focused test must configure this first.
 */
final class ProviderRequestExecutorTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX_BASE_URL = 'https://sandbox-api.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->configureSandboxEnvironment();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ------------------------------------------------------------
    // Success path
    // ------------------------------------------------------------

    public function test_success_path_writes_a_usage_row_with_exactly_the_four_allowlisted_metadata_keys_and_returns_the_correct_response(): void
    {
        Http::fake([
            self::SANDBOX_BASE_URL.'/resource' => Http::response(['id' => 'abc-123'], 200),
        ]);

        [$firm, $connection] = $this->makeConnection();

        $response = $this->runWithFirmContext($firm, fn () => $this->executor()->send(
            connection: $connection,
            providerKey: ProviderKey::Test,
            method: 'POST',
            url: self::SANDBOX_BASE_URL.'/resource',
            capability: 'SupportsPushSyncContract',
            operationType: 'push',
            direction: SyncDirection::Outbound,
            resourceType: ResourceType::Contact,
            authInjector: fn ($r) => $r->withToken('fake-token'),
            usageIdempotencyKey: 'push_operation:success-1',
        ));

        $this->assertSame(200, $response->status);
        $this->assertSame(['id' => 'abc-123'], $response->json);

        $rows = $this->usageRows($firm, $connection);
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('success', $row->outcome);
        $this->assertSame(ProviderKey::Test->value, $row->provider_key);
        $this->assertSame('SupportsPushSyncContract', $row->capability);
        $this->assertSame('push', $row->operation_type);
        $this->assertSame(SyncDirection::Outbound, $row->direction);
        $this->assertSame(ResourceType::Contact->value, $row->resource_type);
        $this->assertSame('push_operation:success-1', $row->idempotency_key);
        $this->assertNotNull($row->correlation_id);

        $metadata = $row->metadata_json;
        $this->assertEqualsCanonicalizing(
            ['status_code', 'category', 'duration_ms', 'http_method'],
            array_keys($metadata),
            'metadata_json must carry EXACTLY these four allowlisted keys — no more, no fewer.'
        );
        $this->assertSame(200, $metadata['status_code']);
        $this->assertNull($metadata['category']);
        $this->assertSame('POST', $metadata['http_method']);
        $this->assertIsInt($metadata['duration_ms']);
    }

    // ------------------------------------------------------------
    // Auth injector actually lands on the outgoing request
    // ------------------------------------------------------------

    public function test_auth_injector_closures_effect_lands_on_the_real_outgoing_request(): void
    {
        Http::fake([
            self::SANDBOX_BASE_URL.'/resource' => Http::response(['ok' => true], 200),
        ]);

        [$firm, $connection] = $this->makeConnection();

        $this->runWithFirmContext($firm, fn () => $this->executor()->send(
            connection: $connection,
            providerKey: ProviderKey::Test,
            method: 'GET',
            url: self::SANDBOX_BASE_URL.'/resource',
            capability: 'SupportsPullSyncContract',
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Contact,
            authInjector: fn ($r) => $r->withToken('fake-token'),
            usageIdempotencyKey: 'sync_run_page:auth-1',
        ));

        Http::assertSent(fn (HttpClientRequest $request) => $request->hasHeader('Authorization', 'Bearer fake-token'));
    }

    // ------------------------------------------------------------
    // Correlation ID
    // ------------------------------------------------------------

    public function test_correlation_id_is_auto_generated_when_omitted_and_matches_the_request_header_and_the_usage_record(): void
    {
        Http::fake([
            self::SANDBOX_BASE_URL.'/resource' => Http::response(['ok' => true], 200),
        ]);

        [$firm, $connection] = $this->makeConnection();

        $this->runWithFirmContext($firm, fn () => $this->executor()->send(
            connection: $connection,
            providerKey: ProviderKey::Test,
            method: 'GET',
            url: self::SANDBOX_BASE_URL.'/resource',
            capability: 'SupportsPullSyncContract',
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Contact,
            authInjector: fn ($r) => $r,
            usageIdempotencyKey: 'sync_run_page:correlation-auto',
        ));

        $row = $this->usageRows($firm, $connection)->first();
        $this->assertNotNull($row->correlation_id);

        Http::assertSent(function (HttpClientRequest $request) use ($row) {
            return $request->hasHeader('X-FirmsVault-Correlation-Id', $row->correlation_id);
        });
    }

    public function test_a_supplied_correlation_id_is_passed_through_unchanged(): void
    {
        Http::fake([
            self::SANDBOX_BASE_URL.'/resource' => Http::response(['ok' => true], 200),
        ]);

        [$firm, $connection] = $this->makeConnection();
        $suppliedCorrelationId = 'caller-supplied-correlation-id-0001';

        $this->runWithFirmContext($firm, fn () => $this->executor()->send(
            connection: $connection,
            providerKey: ProviderKey::Test,
            method: 'GET',
            url: self::SANDBOX_BASE_URL.'/resource',
            capability: 'SupportsPullSyncContract',
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Contact,
            authInjector: fn ($r) => $r,
            usageIdempotencyKey: 'sync_run_page:correlation-supplied',
            correlationId: $suppliedCorrelationId,
        ));

        $row = $this->usageRows($firm, $connection)->first();
        $this->assertSame($suppliedCorrelationId, $row->correlation_id);

        Http::assertSent(fn (HttpClientRequest $request) => $request->hasHeader('X-FirmsVault-Correlation-Id', $suppliedCorrelationId));
    }

    // ------------------------------------------------------------
    // Idempotency dedup
    // ------------------------------------------------------------

    public function test_two_calls_with_the_identical_usage_idempotency_key_write_exactly_one_usage_row(): void
    {
        Http::fake([
            self::SANDBOX_BASE_URL.'/resource' => Http::sequence()
                ->push(['id' => 'first'], 200)
                ->push(['id' => 'second'], 200),
        ]);

        [$firm, $connection] = $this->makeConnection();
        $key = 'push_operation:dedup-1';

        $this->runWithFirmContext($firm, fn () => $this->executor()->send(
            connection: $connection,
            providerKey: ProviderKey::Test,
            method: 'POST',
            url: self::SANDBOX_BASE_URL.'/resource',
            capability: 'SupportsPushSyncContract',
            operationType: 'push',
            direction: SyncDirection::Outbound,
            resourceType: ResourceType::Contact,
            authInjector: fn ($r) => $r,
            usageIdempotencyKey: $key,
        ));

        $this->runWithFirmContext($firm, fn () => $this->executor()->send(
            connection: $connection,
            providerKey: ProviderKey::Test,
            method: 'POST',
            url: self::SANDBOX_BASE_URL.'/resource',
            capability: 'SupportsPushSyncContract',
            operationType: 'push',
            direction: SyncDirection::Outbound,
            resourceType: ResourceType::Contact,
            authInjector: fn ($r) => $r,
            usageIdempotencyKey: $key,
        ));

        $this->assertCount(1, $this->usageRows($firm, $connection));
    }

    // ------------------------------------------------------------
    // Failure classification table (checkpoint1-security-review.md
    // Finding 4's fixed mapping table)
    // ------------------------------------------------------------

    /**
     * @return array<string, array{0: int, 1: string, 2: string, 3: bool}>
     */
    public static function statusCategoryProvider(): array
    {
        return [
            '401 -> authentication_failed -> credential_error' => [401, SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED, 'credential_error', true],
            '403 -> authorization_failed -> scope_error' => [403, SanitizedProviderHttpException::CATEGORY_AUTHORIZATION_FAILED, 'scope_error', true],
            '400 -> validation_failed -> provider_error' => [400, SanitizedProviderHttpException::CATEGORY_VALIDATION_FAILED, 'provider_error', true],
            '422 -> validation_failed -> provider_error' => [422, SanitizedProviderHttpException::CATEGORY_VALIDATION_FAILED, 'provider_error', true],
            '404 -> provider_rejected -> provider_error' => [404, SanitizedProviderHttpException::CATEGORY_PROVIDER_REJECTED, 'provider_error', true],
            '409 -> conflict -> provider_error' => [409, SanitizedProviderHttpException::CATEGORY_CONFLICT, 'provider_error', true],
            '429 -> rate_limited -> rate_limited' => [429, SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, 'rate_limited', true],
            '500 -> provider_rejected -> provider_error' => [500, SanitizedProviderHttpException::CATEGORY_PROVIDER_REJECTED, 'provider_error', true],
            '503 -> provider_rejected -> provider_error' => [503, SanitizedProviderHttpException::CATEGORY_PROVIDER_REJECTED, 'provider_error', true],
            '2xx with unparseable json -> malformed_response -> provider_error' => [200, SanitizedProviderHttpException::CATEGORY_MALFORMED_RESPONSE, 'provider_error', false],
        ];
    }

    #[DataProvider('statusCategoryProvider')]
    public function test_failure_classification_maps_status_to_the_expected_category_and_health_category(
        int $status,
        string $expectedCategory,
        string $expectedHealthCategory,
        bool $validJsonBody,
    ): void {
        $body = $validJsonBody ? ['error' => 'simulated'] : 'not-valid-json{{{';

        Http::fake([
            self::SANDBOX_BASE_URL.'/resource' => Http::response($body, $status),
        ]);

        [$firm, $connection] = $this->makeConnection();

        // The caught exception is intentionally handled INSIDE the same
        // runWithFirmContext()/DB::transaction() scope that send()'s own
        // writes happen in — letting a SanitizedProviderHttpException
        // escape that closure would make Laravel's DB::transaction()
        // roll back everything send() just wrote (both the usage row and
        // the health row), which would make every assertion below
        // spuriously see null rows regardless of whether the production
        // code is actually correct.
        $caughtCategory = null;

        $this->runWithFirmContext($firm, function () use ($connection, $status, $validJsonBody, &$caughtCategory) {
            try {
                $this->executor()->send(
                    connection: $connection,
                    providerKey: ProviderKey::Test,
                    method: 'POST',
                    url: self::SANDBOX_BASE_URL.'/resource',
                    capability: 'SupportsPushSyncContract',
                    operationType: 'push',
                    direction: SyncDirection::Outbound,
                    resourceType: ResourceType::Contact,
                    authInjector: fn ($r) => $r,
                    usageIdempotencyKey: 'push_operation:classify-'.$status.'-'.($validJsonBody ? 'json' : 'malformed'),
                );
            } catch (SanitizedProviderHttpException $e) {
                $caughtCategory = $e->category();
            }
        });

        $this->assertNotNull($caughtCategory, 'Expected a SanitizedProviderHttpException.');
        $this->assertSame($expectedCategory, $caughtCategory);

        $row = $this->usageRows($firm, $connection)->first();
        $this->assertNotNull($row);
        $this->assertSame('failure', $row->outcome);
        $this->assertSame($expectedCategory, $row->metadata_json['category']);

        $health = $this->healthRow($firm, $connection);
        $this->assertNotNull($health);
        $this->assertSame($expectedHealthCategory, $health->last_failure_category);
    }

    // ------------------------------------------------------------
    // Retry-After parsing/clamping
    // ------------------------------------------------------------

    public function test_a_429_with_a_retry_after_header_parses_the_delay_correctly(): void
    {
        Http::fake([
            self::SANDBOX_BASE_URL.'/resource' => Http::response([], 429, ['Retry-After' => '120']),
        ]);

        [$firm, $connection] = $this->makeConnection();

        try {
            $this->runWithFirmContext($firm, fn () => $this->executor()->send(
                connection: $connection,
                providerKey: ProviderKey::Test,
                method: 'POST',
                url: self::SANDBOX_BASE_URL.'/resource',
                capability: 'SupportsPushSyncContract',
                operationType: 'push',
                direction: SyncDirection::Outbound,
                resourceType: ResourceType::Contact,
                authInjector: fn ($r) => $r,
                usageIdempotencyKey: 'push_operation:retry-after-1',
            ));

            $this->fail('Expected a SanitizedProviderHttpException.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertSame(120, $e->retryAfterSeconds());
        }
    }

    public function test_a_retry_after_header_exceeding_the_configured_max_backoff_is_clamped(): void
    {
        config(['integrations.outbox.max_backoff_seconds' => 300]);

        Http::fake([
            self::SANDBOX_BASE_URL.'/resource' => Http::response([], 429, ['Retry-After' => '999999']),
        ]);

        [$firm, $connection] = $this->makeConnection();

        try {
            $this->runWithFirmContext($firm, fn () => $this->executor()->send(
                connection: $connection,
                providerKey: ProviderKey::Test,
                method: 'POST',
                url: self::SANDBOX_BASE_URL.'/resource',
                capability: 'SupportsPushSyncContract',
                operationType: 'push',
                direction: SyncDirection::Outbound,
                resourceType: ResourceType::Contact,
                authInjector: fn ($r) => $r,
                usageIdempotencyKey: 'push_operation:retry-after-clamp',
            ));

            $this->fail('Expected a SanitizedProviderHttpException.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertSame(300, $e->retryAfterSeconds(), 'A Retry-After value exceeding the configured max_backoff_seconds ceiling must be clamped down to it.');
        }
    }

    // ------------------------------------------------------------
    // Proactive rate-limit rejection
    // ------------------------------------------------------------

    public function test_proactive_rate_limit_rejection_short_circuits_before_any_http_call_writes_no_usage_row_and_records_health(): void
    {
        config(['integrations.rate_limits.providers.'.ProviderKey::Test->value => [
            'max_attempts_per_window' => 1,
            'window_seconds' => 60,
        ]]);

        Http::fake([
            self::SANDBOX_BASE_URL.'/resource' => Http::response(['ok' => true], 200),
        ]);

        [$firm, $connection] = $this->makeConnection();

        // First call consumes the entire 1-attempt budget and succeeds.
        $this->runWithFirmContext($firm, fn () => $this->executor()->send(
            connection: $connection,
            providerKey: ProviderKey::Test,
            method: 'POST',
            url: self::SANDBOX_BASE_URL.'/resource',
            capability: 'SupportsPushSyncContract',
            operationType: 'push',
            direction: SyncDirection::Outbound,
            resourceType: ResourceType::Contact,
            authInjector: fn ($r) => $r,
            usageIdempotencyKey: 'push_operation:ratelimit-first',
        ));

        Http::assertSentCount(1);

        // Second call for the SAME connection must be rejected WITHOUT
        // ever reaching Http:: — no second request goes out. The
        // exception is caught INSIDE the same runWithFirmContext()
        // closure so the health-recording write it triggers is not lost
        // to a transaction rollback (see the identical fix/comment on
        // the failure-classification test above).
        $caughtCategory = null;
        $caughtRetryAfterSeconds = null;

        $this->runWithFirmContext($firm, function () use ($connection, &$caughtCategory, &$caughtRetryAfterSeconds) {
            try {
                $this->executor()->send(
                    connection: $connection,
                    providerKey: ProviderKey::Test,
                    method: 'POST',
                    url: self::SANDBOX_BASE_URL.'/resource',
                    capability: 'SupportsPushSyncContract',
                    operationType: 'push',
                    direction: SyncDirection::Outbound,
                    resourceType: ResourceType::Contact,
                    authInjector: fn ($r) => $r,
                    usageIdempotencyKey: 'push_operation:ratelimit-second',
                );
            } catch (SanitizedProviderHttpException $e) {
                $caughtCategory = $e->category();
                $caughtRetryAfterSeconds = $e->retryAfterSeconds();
            }
        });

        $this->assertNotNull($caughtCategory, 'Expected a rate_limited SanitizedProviderHttpException.');
        $this->assertSame(SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, $caughtCategory);
        $this->assertGreaterThan(0, $caughtRetryAfterSeconds);

        // Still exactly ONE physical HTTP request total.
        Http::assertSentCount(1);

        // Still exactly ONE usage row total — the rejected attempt wrote none.
        $this->assertCount(1, $this->usageRows($firm, $connection));

        // The rejection IS observable via HealthStateService.
        $health = $this->healthRow($firm, $connection);
        $this->assertNotNull($health);
        $this->assertSame('rate_limited', $health->last_failure_category);
    }

    // ------------------------------------------------------------
    // Cross-connection rate-limit isolation
    // ------------------------------------------------------------

    public function test_rate_limit_exhaustion_on_one_connection_does_not_affect_a_different_connection(): void
    {
        config(['integrations.rate_limits.providers.'.ProviderKey::Test->value => [
            'max_attempts_per_window' => 1,
            'window_seconds' => 60,
        ]]);

        Http::fake([
            self::SANDBOX_BASE_URL.'/resource' => Http::response(['ok' => true], 200),
        ]);

        [$firm, $connectionA] = $this->makeConnection();
        $connectionB = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create([
            'external_account_id' => null,
        ]));

        // Exhaust connection A.
        $this->runWithFirmContext($firm, fn () => $this->executor()->send(
            connection: $connectionA,
            providerKey: ProviderKey::Test,
            method: 'POST',
            url: self::SANDBOX_BASE_URL.'/resource',
            capability: 'SupportsPushSyncContract',
            operationType: 'push',
            direction: SyncDirection::Outbound,
            resourceType: ResourceType::Contact,
            authInjector: fn ($r) => $r,
            usageIdempotencyKey: 'push_operation:isolation-a',
        ));

        try {
            $this->runWithFirmContext($firm, fn () => $this->executor()->send(
                connection: $connectionA,
                providerKey: ProviderKey::Test,
                method: 'POST',
                url: self::SANDBOX_BASE_URL.'/resource',
                capability: 'SupportsPushSyncContract',
                operationType: 'push',
                direction: SyncDirection::Outbound,
                resourceType: ResourceType::Contact,
                authInjector: fn ($r) => $r,
                usageIdempotencyKey: 'push_operation:isolation-a-2',
            ));
            $this->fail('Sanity check: connection A must be exhausted.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, $e->category());
        }

        // Connection B, a completely different connection, must be
        // totally unaffected — its own budget starts fresh.
        $responseB = $this->runWithFirmContext($firm, fn () => $this->executor()->send(
            connection: $connectionB,
            providerKey: ProviderKey::Test,
            method: 'POST',
            url: self::SANDBOX_BASE_URL.'/resource',
            capability: 'SupportsPushSyncContract',
            operationType: 'push',
            direction: SyncDirection::Outbound,
            resourceType: ResourceType::Contact,
            authInjector: fn ($r) => $r,
            usageIdempotencyKey: 'push_operation:isolation-b',
        ));

        $this->assertSame(200, $responseB->status);
        Http::assertSentCount(2, 'Connection A\'s one allowed request plus connection B\'s one allowed request — never a third.');
    }

    // ------------------------------------------------------------
    // Metadata never carries request/response body content
    // ------------------------------------------------------------

    public function test_metadata_never_carries_response_body_content_even_when_the_response_contains_an_obviously_sensitive_field(): void
    {
        Http::fake([
            self::SANDBOX_BASE_URL.'/resource' => Http::response(['secret_account_number' => '4111222233334444'], 200),
        ]);

        [$firm, $connection] = $this->makeConnection();

        $this->runWithFirmContext($firm, fn () => $this->executor()->send(
            connection: $connection,
            providerKey: ProviderKey::Test,
            method: 'POST',
            url: self::SANDBOX_BASE_URL.'/resource',
            capability: 'SupportsPushSyncContract',
            operationType: 'push',
            direction: SyncDirection::Outbound,
            resourceType: ResourceType::Contact,
            authInjector: fn ($r) => $r,
            usageIdempotencyKey: 'push_operation:sensitive-1',
        ));

        $row = $this->usageRows($firm, $connection)->first();
        $rawMetadataJson = json_encode($row->metadata_json);

        $this->assertStringNotContainsString('4111222233334444', $rawMetadataJson);
        $this->assertStringNotContainsString('secret_account_number', $rawMetadataJson);
    }

    // ------------------------------------------------------------
    // Environment/URL guard (Finding 1) — proves the subdomain-suffix
    // bypass is actually closed, a legitimate URL succeeds, and a
    // mismatched port is rejected.
    // ------------------------------------------------------------

    public function test_environment_guard_rejects_a_subdomain_suffix_bypass_url(): void
    {
        [$firm, $connection] = $this->makeConnection();

        // No Http::fake() rule is registered for this URL at all — if
        // the guard failed to reject it BEFORE any HTTP call, the
        // suite-wide Http::preventStrayRequests() guard would itself
        // throw a different exception, which would also fail this test
        // (a second, independent layer of proof that no request went out).
        $bypassUrl = 'https://sandbox-api.example.test.attacker.example/resource';

        try {
            $this->runWithFirmContext($firm, fn () => $this->executor()->send(
                connection: $connection,
                providerKey: ProviderKey::Test,
                method: 'POST',
                url: $bypassUrl,
                capability: 'SupportsPushSyncContract',
                operationType: 'push',
                direction: SyncDirection::Outbound,
                resourceType: ResourceType::Contact,
                authInjector: fn ($r) => $r,
                usageIdempotencyKey: 'push_operation:bypass-1',
            ));

            $this->fail('Expected a ProviderEnvironmentMisconfiguredException — a subdomain-suffix host must never pass the environment guard.');
        } catch (ProviderEnvironmentMisconfiguredException $e) {
            // Expected.
        }

        Http::assertNothingSent();
        $this->assertCount(0, $this->usageRows($firm, $connection), 'A rejected URL must never write a usage row.');
    }

    public function test_environment_guard_allows_a_url_that_genuinely_matches_the_configured_sandbox_host(): void
    {
        Http::fake([
            self::SANDBOX_BASE_URL.'/resource' => Http::response(['ok' => true], 200),
        ]);

        [$firm, $connection] = $this->makeConnection();

        $response = $this->runWithFirmContext($firm, fn () => $this->executor()->send(
            connection: $connection,
            providerKey: ProviderKey::Test,
            method: 'GET',
            url: self::SANDBOX_BASE_URL.'/resource',
            capability: 'SupportsPullSyncContract',
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Contact,
            authInjector: fn ($r) => $r,
            usageIdempotencyKey: 'sync_run_page:legit-1',
        ));

        $this->assertSame(200, $response->status);
        Http::assertSentCount(1);
    }

    public function test_environment_guard_rejects_a_url_with_a_mismatched_port(): void
    {
        [$firm, $connection] = $this->makeConnection();

        $mismatchedPortUrl = 'https://sandbox-api.example.test:8443/resource';

        try {
            $this->runWithFirmContext($firm, fn () => $this->executor()->send(
                connection: $connection,
                providerKey: ProviderKey::Test,
                method: 'GET',
                url: $mismatchedPortUrl,
                capability: 'SupportsPullSyncContract',
                operationType: 'pull',
                direction: SyncDirection::Inbound,
                resourceType: ResourceType::Contact,
                authInjector: fn ($r) => $r,
                usageIdempotencyKey: 'sync_run_page:port-mismatch-1',
            ));

            $this->fail('Expected a ProviderEnvironmentMisconfiguredException — a mismatched port must be rejected.');
        } catch (ProviderEnvironmentMisconfiguredException $e) {
            // Expected.
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function configureSandboxEnvironment(): void
    {
        config(['integrations.provider_environments.'.ProviderKey::Test->value => [
            'mode' => 'sandbox',
            'sandbox_base_url' => self::SANDBOX_BASE_URL,
            'live_base_url' => 'https://live-api.example.test',
        ]]);
    }

    private function executor(): ProviderRequestExecutor
    {
        return app(ProviderRequestExecutor::class);
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function makeConnection(): array
    {
        $firm = Firm::factory()->create();
        $provider = IntegrationProvider::query()->where('code', ProviderKey::Test->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::Test->value]);

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($provider)
            ->create(['external_account_id' => null]));

        return [$firm, $connection];
    }

    private function usageRows(Firm $firm, FirmIntegration $connection)
    {
        return $this->runWithFirmContext($firm, fn () => IntegrationUsageRecord::query()
            ->where('firm_integration_id', $connection->id)
            ->get());
    }

    private function healthRow(Firm $firm, FirmIntegration $connection): ?IntegrationConnectionHealth
    {
        return $this->runWithFirmContext($firm, fn () => IntegrationConnectionHealth::query()
            ->where('firm_integration_id', $connection->id)
            ->first());
    }
}
