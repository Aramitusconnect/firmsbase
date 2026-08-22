<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\AuthorizationCodeAlreadyUsedException;
use App\Integrations\Exceptions\UnknownProviderException;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Support\PkceService;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The most security-critical test file for Checkpoint 1
 * (checkpoint-00-final-specification.md §18/§21/§22): TestProvider is
 * the ONLY concrete provider implementation in this mission, and it
 * must never make a real network call, never leak a hardcoded
 * credential-shaped constant, and must default to OFF.
 *
 * Extends the full Laravel TestCase (needed for Http::fake()/
 * config_path()/the container) but deliberately never uses
 * RefreshDatabase/DatabaseMigrations/factories and issues zero database
 * queries — Checkpoint 1 introduces zero migrations/tables.
 */
final class TestProviderStubTest extends TestCase
{
    private const ENV_FLAG = 'INTEGRATIONS_TEST_PROVIDER_ENABLED';

    /** @var string|false original getenv() value, to restore in tearDown(). */
    private string|false $originalGetenv;

    private mixed $originalEnvSuperglobal;

    private mixed $originalServerSuperglobal;

    protected function setUp(): void
    {
        parent::setUp();

        // Snapshot whatever the real environment happens to be before
        // this test mutates it, across every source Illuminate\Support\Env
        // might consult, so tearDown() can restore it exactly rather
        // than assuming "unset" is the correct baseline.
        $this->originalGetenv = getenv(self::ENV_FLAG);
        $this->originalEnvSuperglobal = $_ENV[self::ENV_FLAG] ?? null;
        $this->originalServerSuperglobal = $_SERVER[self::ENV_FLAG] ?? null;

        // Several tests in this class now mint real TestProvider
        // authorization codes (exchangeCodeForToken() requires a code
        // minted via simulateAuthorizationGrant() since it enforces PKCE
        // + single-use semantics) — TestProvider's own class docblock
        // condition (a) requires resetSimulationState() from every test
        // that exercises this registry, so it never leaks between tests.
        TestProvider::resetSimulationState();
    }

    protected function tearDown(): void
    {
        TestProvider::resetSimulationState();

        if ($this->originalGetenv === false) {
            putenv(self::ENV_FLAG);
        } else {
            putenv(self::ENV_FLAG.'='.$this->originalGetenv);
        }

        if ($this->originalEnvSuperglobal === null) {
            unset($_ENV[self::ENV_FLAG]);
        } else {
            $_ENV[self::ENV_FLAG] = $this->originalEnvSuperglobal;
        }

        if ($this->originalServerSuperglobal === null) {
            unset($_SERVER[self::ENV_FLAG]);
        } else {
            $_SERVER[self::ENV_FLAG] = $this->originalServerSuperglobal;
        }

        parent::tearDown();
    }

    /**
     * Sets (or clears, when $value is null) the governing environment
     * flag across every source TestProvider/config/integrations.php
     * could plausibly read it from, so the test is robust regardless of
     * which adapter Illuminate\Support\Env resolves through.
     */
    private function setEnvFlag(?string $value): void
    {
        if ($value === null) {
            putenv(self::ENV_FLAG);
            unset($_ENV[self::ENV_FLAG], $_SERVER[self::ENV_FLAG]);

            return;
        }

        putenv(self::ENV_FLAG.'='.$value);
        $_ENV[self::ENV_FLAG] = $value;
        $_SERVER[self::ENV_FLAG] = $value;
    }

    // ---------------------------------------------------------------
    // Zero real HTTP calls
    // ---------------------------------------------------------------

    public function test_exercising_every_method_makes_zero_real_http_calls(): void
    {
        Http::fake();

        $provider = new TestProvider;

        $provider->key();
        $provider->displayName();
        $provider->description();
        $provider->isConfigured();
        $provider->supportedAuthMethods();
        $provider->authorizationUrl(['client_id' => 'x']);
        $pkce = new PkceService;
        $verifier = $pkce->generateVerifier();
        $mintedCode = $provider->simulateAuthorizationGrant($pkce->challengeForVerifier($verifier));
        $provider->exchangeCodeForToken($mintedCode, ['code_verifier' => $verifier]);
        $provider->refreshToken('refresh-token');
        $provider->requiredScopes();
        $provider->requiredCredentialFields();
        $provider->validateCredentials(['api_key' => str_repeat('a', 16)]);
        $provider->webhookEventTypes();
        $provider->verifyInboundSignature('{}', ['signature' => 'whatever']);
        $provider->parseInboundEvent('{}', []);
        $provider->subscribe([]);
        $provider->renewSubscription([]);
        $provider->healthCheckEndpointConvention();
        $provider->checkHealth([]);
        $provider->pullableResourceTypes();
        $provider->pull([], 'contact', null);
        $provider->pushableResourceTypes();
        $provider->push([], 'contact', ['name' => 'x']);
        $provider->supportsIncrementalFor('contact');
        $provider->incrementalCursorFor([], 'contact');
        $provider->revokeAtProvider([]);

        Http::assertNothingSent();
    }

    public function test_source_file_contains_no_real_network_call_primitive(): void
    {
        $reflection = new ReflectionClass(TestProvider::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertIsString($source);

        $forbiddenNeedles = [
            'Http::',
            'GuzzleHttp',
            'Guzzle',
            'curl_',
            "fopen('http",
            "file_get_contents('http",
            'fsockopen',
        ];

        foreach ($forbiddenNeedles as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $source,
                "TestProvider.php must not reference real network primitive: {$needle}"
            );
        }
    }

    // ---------------------------------------------------------------
    // Runtime-generated, non-hardcoded secret-shaped values
    // ---------------------------------------------------------------

    public function test_exchange_code_for_token_generates_different_access_and_refresh_tokens_each_call(): void
    {
        $provider = new TestProvider;
        $pkce = new PkceService;

        // Two distinct, freshly minted authorization codes — each
        // exchanged exactly once — rather than the same code exchanged
        // twice: this test proves non-hardcoded runtime generation, not
        // replay rejection (see
        // test_a_second_exchange_of_the_same_authorization_code_is_rejected()
        // below for that separate proof).
        $firstVerifier = $pkce->generateVerifier();
        $firstCode = $provider->simulateAuthorizationGrant($pkce->challengeForVerifier($firstVerifier));
        $first = $provider->exchangeCodeForToken($firstCode, ['code_verifier' => $firstVerifier]);

        $secondVerifier = $pkce->generateVerifier();
        $secondCode = $provider->simulateAuthorizationGrant($pkce->challengeForVerifier($secondVerifier));
        $second = $provider->exchangeCodeForToken($secondCode, ['code_verifier' => $secondVerifier]);

        // A hardcoded constant would return the exact same string both
        // times — this is the only way to actually prove non-hardcoded
        // runtime generation.
        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertNotSame($first['access_token'], $first['refresh_token']);
    }

    public function test_a_second_exchange_of_the_same_authorization_code_is_rejected(): void
    {
        // Genuinely separate proof from the uniqueness test above: mints
        // exactly ONE authorization code, exchanges it once
        // successfully, then proves a second exchange of that SAME code
        // is rejected — replay protection remains intact.
        $provider = new TestProvider;
        $pkce = new PkceService;

        $verifier = $pkce->generateVerifier();
        $code = $provider->simulateAuthorizationGrant($pkce->challengeForVerifier($verifier));

        $tokenSet = $provider->exchangeCodeForToken($code, ['code_verifier' => $verifier]);
        $this->assertArrayHasKey('access_token', $tokenSet);

        $this->expectException(AuthorizationCodeAlreadyUsedException::class);

        $provider->exchangeCodeForToken($code, ['code_verifier' => $verifier]);
    }

    public function test_refresh_token_generates_different_access_and_refresh_tokens_each_call(): void
    {
        $provider = new TestProvider;

        $first = $provider->refreshToken('same-refresh-token');
        $second = $provider->refreshToken('same-refresh-token');

        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
    }

    public function test_incremental_cursor_is_generated_fresh_each_call(): void
    {
        $provider = new TestProvider;

        $first = $provider->incrementalCursorFor([], 'contact');
        $second = $provider->incrementalCursorFor([], 'contact');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
    }

    public function test_webhook_subscribe_generates_a_different_subscription_id_each_call(): void
    {
        $provider = new TestProvider;

        $first = $provider->subscribe([]);
        $second = $provider->subscribe([]);

        $this->assertNotSame($first['subscription_id'], $second['subscription_id']);
    }

    public function test_webhook_renew_subscription_generates_a_different_subscription_id_each_call(): void
    {
        $provider = new TestProvider;

        $first = $provider->renewSubscription([]);
        $second = $provider->renewSubscription([]);

        $this->assertNotSame($first['subscription_id'], $second['subscription_id']);
    }

    public function test_webhook_signing_key_is_generated_per_instance_not_a_hardcoded_constant(): void
    {
        // The webhook signing key is never returned by a public method
        // (it is only used internally by verifyInboundSignature()), so
        // reflection is the only way to directly compare the two
        // independently generated values and prove neither is a
        // hardcoded class constant shared across instances.
        $propertyReflection = new ReflectionProperty(TestProvider::class, 'webhookSigningKey');
        $propertyReflection->setAccessible(true);

        $first = $propertyReflection->getValue(new TestProvider);
        $second = $propertyReflection->getValue(new TestProvider);

        $this->assertNotSame($first, $second);
        $this->assertNotEmpty($first);
        $this->assertNotEmpty($second);
    }

    public function test_pull_and_push_generate_different_external_ids_each_call(): void
    {
        $provider = new TestProvider;

        $firstPush = $provider->push([], 'contact', []);
        $secondPush = $provider->push([], 'contact', []);
        $this->assertNotSame($firstPush['external_id'], $secondPush['external_id']);

        $firstPull = $provider->pull([], 'contact', null);
        $secondPull = $provider->pull([], 'contact', null);
        $this->assertNotSame($firstPull['items'][0]['external_id'], $secondPull['items'][0]['external_id']);
    }

    // ---------------------------------------------------------------
    // CHECKPOINT 12 F4 — idempotency-key honoring in push()
    // (frozen-design-post-security-review.md §2 F4; checkpoint-00-final-
    // specification.md §16). CRITICAL, deliberately NOT a modification
    // of test_pull_and_push_generate_different_external_ids_each_call()
    // above: that test calls push([], 'contact', []) with an EMPTY
    // context — no 'idempotency_key' key at all — so
    // $context['idempotency_key'] ?? null resolves to null and F4's new
    // dedup branch never triggers; that test's "different external ids
    // each call" assertion remains completely correct and unaffected by
    // F4, and is left byte-for-byte untouched here. This is instead a
    // NEW, separate proof that push() genuinely dedupes when a caller
    // DOES supply a real, matching idempotency_key across two separate
    // calls — the actual behavior F4 adds.
    // ---------------------------------------------------------------

    public function test_push_with_a_matching_idempotency_key_returns_the_identical_response_on_a_second_call(): void
    {
        $provider = new TestProvider;

        $first = $provider->push(['idempotency_key' => 'same-key-value'], 'contact', ['name' => 'Ada']);
        $second = $provider->push(['idempotency_key' => 'same-key-value'], 'contact', ['name' => 'Ada']);

        $this->assertSame($first['external_id'], $second['external_id'], 'A repeated push() call carrying the SAME idempotency_key must return the SAME external_id, not a freshly generated one.');
        $this->assertSame($first['version_token'], $second['version_token']);
        $this->assertSame($first['status'], $second['status']);
        $this->assertSame($first, $second, 'The entire response array must be identical, not merely individual fields that happen to match.');
    }

    public function test_push_with_a_different_idempotency_key_returns_a_genuinely_different_response(): void
    {
        // Companion proof: F4's dedup is keyed on the idempotency key
        // itself, not merely "any second call" — a DIFFERENT key must
        // still produce fresh, independent values.
        $provider = new TestProvider;

        $first = $provider->push(['idempotency_key' => 'key-one'], 'contact', []);
        $second = $provider->push(['idempotency_key' => 'key-two'], 'contact', []);

        $this->assertNotSame($first['external_id'], $second['external_id']);
    }

    public function test_reset_simulation_state_clears_the_idempotency_registry(): void
    {
        $provider = new TestProvider;

        $before = $provider->push(['idempotency_key' => 'a-key-to-be-cleared'], 'contact', []);

        TestProvider::resetSimulationState();

        $after = $provider->push(['idempotency_key' => 'a-key-to-be-cleared'], 'contact', []);

        $this->assertNotSame($before['external_id'], $after['external_id'], 'resetSimulationState() must clear $issuedIdempotentPushResponses — the SAME key, reused after a reset, must be treated as genuinely new.');
    }

    // ---------------------------------------------------------------
    // CHECKPOINT 12 — rotateWebhookSigningKey() unit test (frozen-
    // design-post-security-review.md §3, §5 N3): working-as-designed,
    // just never exercised by any test until now (12H verification item
    // 7: zero callers anywhere, including tests). Proves the documented
    // 2-candidate (current + immediately-previous) overlap window
    // verifyInboundSignature() already implements.
    // ---------------------------------------------------------------

    public function test_rotate_webhook_signing_key_current_and_previous_candidates_both_verify(): void
    {
        $provider = new TestProvider;
        $body = json_encode(['event_id' => 'evt-rotation-1', 'event_type' => 'test.resource.created', 'payload' => []]);
        $this->assertIsString($body);

        $preRotationKey = $this->readWebhookSigningKey($provider);
        $preRotationHeaders = $this->signedHeadersFor($preRotationKey, $body);

        // Before rotation, a signature made with the (only) current key
        // verifies.
        $this->assertTrue($provider->verifyInboundSignature($body, $preRotationHeaders));

        $provider->rotateWebhookSigningKey();

        $postRotationKey = $this->readWebhookSigningKey($provider);
        $this->assertNotSame($preRotationKey, $postRotationKey, 'Sanity check: rotation must actually produce a fresh current key.');

        $postRotationHeaders = $this->signedHeadersFor($postRotationKey, $body);

        // Both the NEW current key...
        $this->assertTrue($provider->verifyInboundSignature($body, $postRotationHeaders), 'A signature made with the freshly rotated-in current key must verify.');
        // ...and the OLD (now "previous") key must still verify —
        // the 2-candidate overlap window.
        $this->assertTrue($provider->verifyInboundSignature($body, $preRotationHeaders), 'A signature made with the key that was current immediately before rotation must still verify as the "previous" candidate.');
    }

    public function test_rotate_webhook_signing_key_twice_discards_the_first_previous_key_never_accumulates_more_than_one(): void
    {
        $provider = new TestProvider;
        $body = json_encode(['event_id' => 'evt-rotation-2', 'event_type' => 'test.resource.created', 'payload' => []]);
        $this->assertIsString($body);

        $keyGeneration1 = $this->readWebhookSigningKey($provider);
        $headersForGeneration1 = $this->signedHeadersFor($keyGeneration1, $body);

        $provider->rotateWebhookSigningKey(); // generation 1 -> previous, generation 2 -> current
        $provider->rotateWebhookSigningKey(); // generation 2 -> previous, generation 3 -> current

        $keyGeneration3 = $this->readWebhookSigningKey($provider);
        $headersForGeneration3 = $this->signedHeadersFor($keyGeneration3, $body);

        $this->assertTrue($provider->verifyInboundSignature($body, $headersForGeneration3), 'The current (3rd generation) key must verify.');
        $this->assertFalse(
            $provider->verifyInboundSignature($body, $headersForGeneration1),
            'A key from two rotations ago (neither current nor the single most-recent previous) must NOT verify — the overlap window never accumulates more than one previous candidate.'
        );
    }

    private function readWebhookSigningKey(TestProvider $provider): string
    {
        $property = new ReflectionProperty(TestProvider::class, 'webhookSigningKey');
        $property->setAccessible(true);

        return $property->getValue($provider);
    }

    private function signedHeadersFor(string $secret, string $body, ?int $timestamp = null): array
    {
        $timestamp ??= now()->getTimestamp();
        $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.'.'.$body, $secret);

        return [
            'X-Test-Provider-Signature' => $signature,
            'X-Test-Provider-Timestamp' => (string) $timestamp,
        ];
    }

    // ---------------------------------------------------------------
    // CHECKPOINT 12 — full-container-boot proof that ProviderRegistry::
    // get() throws when INTEGRATIONS_TEST_PROVIDER_ENABLED is genuinely
    // unset (frozen-design-post-security-review.md §3; class docblock
    // condition (b) above). Every OTHER test in this suite that needs
    // TestProvider registered does so via config(['integrations.providers'
    // => [...]]) — a directly-poked config array, never the real
    // environment variable. This test deliberately does NOT poke
    // config('integrations.providers') at all: it relies on the
    // ALREADY-booted application container's own config, computed by the
    // real config/integrations.php file's own env() call against this
    // process's real (confirmed absent from .env/.env.testing.example/
    // phpunit.xml — see this checkpoint's own verification) environment
    // variable — proving the registry-level throw is reachable from a
    // genuine cold boot, not merely from a hand-typed test double.
    // ---------------------------------------------------------------

    public function test_provider_registry_get_throws_for_the_test_provider_key_when_the_real_env_var_is_genuinely_unset_at_full_container_boot(): void
    {
        // Defensive/explicit, not load-bearing: this process's real
        // environment already has the flag unset (confirmed absent from
        // every config source this checkpoint's own .env.example change
        // documents) — setEnvFlag(null) here simply makes that
        // precondition explicit and self-restoring via tearDown(),
        // matching this class's own established convention, rather than
        // silently depending on ambient process state.
        $this->setEnvFlag(null);

        // array_key_exists() + a direct index, deliberately NOT `?? '...'`
        // (which uses isset() under the hood and would itself treat the
        // expected null value as "absent," silently defeating this sanity
        // check).
        $configuredProviders = config('integrations.providers');
        $this->assertIsArray($configuredProviders);
        $this->assertArrayHasKey(ProviderKey::Test->value, $configuredProviders);
        $this->assertNull(
            $configuredProviders[ProviderKey::Test->value],
            'Sanity check: the CURRENTLY-BOOTED container\'s own config('.
            "'integrations.providers') must already reflect the unset flag ".
            'before proceeding — this test never calls config([\'integrations.providers\' => ...]) itself, so this assertion is the proof the throw below is genuinely driven by a real cold-boot config value, not a test-poked one.'
        );

        $this->expectException(UnknownProviderException::class);

        app(ProviderRegistry::class)->get(ProviderKey::Test);
    }

    // ---------------------------------------------------------------
    // Default-OFF environment gate
    // ---------------------------------------------------------------

    public function test_is_configured_is_false_when_the_environment_flag_is_unset(): void
    {
        $this->setEnvFlag(null);

        $this->assertFalse((new TestProvider)->isConfigured());
    }

    public function test_is_configured_is_false_when_the_environment_flag_is_explicitly_false(): void
    {
        $this->setEnvFlag('false');

        $this->assertFalse((new TestProvider)->isConfigured());
    }

    public function test_is_configured_is_true_only_when_the_environment_flag_is_explicitly_true(): void
    {
        $this->setEnvFlag('true');

        $this->assertTrue((new TestProvider)->isConfigured());
    }

    public function test_is_configured_rejects_a_non_boolean_truthy_looking_string(): void
    {
        // filter_var(..., FILTER_VALIDATE_BOOLEAN) treats an
        // unrecognized string as false — proves this is a real
        // validated boolean gate, not a loose truthy check.
        $this->setEnvFlag('yes-please-enable-it');

        $this->assertFalse((new TestProvider)->isConfigured());
    }

    // ---------------------------------------------------------------
    // Config-driven registration follows the same default-OFF gate
    // ---------------------------------------------------------------

    public function test_config_file_does_not_register_test_provider_when_flag_is_unset(): void
    {
        $this->setEnvFlag(null);

        // Re-require the actual config file directly (not the cached
        // config() helper, which reflects only whatever the environment
        // was at application boot) so this exercises the real,
        // currently-unmutated production file under the current flag
        // state.
        $config = require config_path('integrations.php');

        $this->assertArrayHasKey(ProviderKey::Test->value, $config['providers']);
        $this->assertNull($config['providers'][ProviderKey::Test->value]);
    }

    public function test_config_file_registers_test_provider_class_when_flag_is_true(): void
    {
        $this->setEnvFlag('true');

        $config = require config_path('integrations.php');

        $this->assertSame(TestProvider::class, $config['providers'][ProviderKey::Test->value]);
    }

    public function test_config_file_does_not_register_test_provider_when_flag_is_explicitly_false(): void
    {
        $this->setEnvFlag('false');

        $config = require config_path('integrations.php');

        $this->assertNull($config['providers'][ProviderKey::Test->value]);
    }

    // ---------------------------------------------------------------
    // No real/plausible-looking hardcoded credential constants
    // ---------------------------------------------------------------

    public function test_source_file_contains_no_hardcoded_credential_shaped_constant(): void
    {
        $reflection = new ReflectionClass(TestProvider::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertIsString($source);

        // Every secret-shaped return value in the real source is built
        // via Str::random()/Str::uuid() calls, never a literal string
        // constant assigned to an access/refresh-token-shaped key. This
        // is a structural backstop alongside the behavioral
        // "two calls differ" tests above.
        $this->assertMatchesRegularExpression(
            "/'access_token'\s*=>\s*Str::random\(/",
            $source,
            'access_token must be generated via Str::random(), never a literal constant.'
        );
        $this->assertMatchesRegularExpression(
            "/'refresh_token'\s*=>\s*Str::random\(/",
            $source,
            'refresh_token must be generated via Str::random(), never a literal constant.'
        );
    }
}
