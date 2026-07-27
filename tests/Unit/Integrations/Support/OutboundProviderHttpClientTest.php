<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Support;

use App\Integrations\Exceptions\AuthorizationCodeAlreadyUsedException;
use App\Integrations\Exceptions\ExpiredAuthorizationCodeException;
use App\Integrations\Exceptions\InvalidPkceVerifierException;
use App\Integrations\Exceptions\OAuthAccountMismatchException;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Exceptions\SimulatedProviderFailureException;
use App\Integrations\Support\OutboundProviderHttpClient;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * OutboundProviderHttpClientTest — Checkpoint 5
 * (agent-h-security-architecture-review.md item 19; frozen-design-post-review.md
 * §15). OutboundProviderHttpClient has zero dependencies and does no
 * I/O of its own — it only wraps a caller-supplied closure and
 * sanitizes what it throws. This test extends the full Laravel
 * Tests\TestCase (mirrors ProviderRegistryTest's identical convention:
 * needed for Http::fake()/the Http facade — pure PHPUnit\Framework\TestCase
 * cannot exercise it) but never uses RefreshDatabase/DatabaseMigrations
 * and issues no database query.
 *
 * Proves: (1) the sanitized exception shape exposes ONLY a status code
 * and a small closed category enum, never the original message/
 * headers/body; (2) it actually re-throws the exception types it
 * claims are already-safe unchanged; (3) it actually wraps
 * SimulatedProviderFailureException and any other Throwable; (4) even
 * when the wrapped closure shapes a REAL HTTP call (Http::fake()'d, so
 * no socket ever opens — Http::preventStrayRequests() would itself
 * fail the test if one did), the resulting sanitized exception still
 * never leaks the raw response body/headers.
 */
final class OutboundProviderHttpClientTest extends TestCase
{
    private OutboundProviderHttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new OutboundProviderHttpClient;
    }

    public function test_execute_returns_the_operations_return_value_on_success(): void
    {
        $result = $this->client->execute(fn () => 'the-real-return-value', 'someOperation');

        $this->assertSame('the-real-return-value', $result);
    }

    // ---------------------------------------------------------------
    // Already-safe app-defined exceptions are rethrown UNCHANGED
    // ---------------------------------------------------------------

    public function test_invalid_pkce_verifier_exception_passes_through_unchanged(): void
    {
        $original = new InvalidPkceVerifierException;

        try {
            $this->client->execute(function () use ($original): never {
                throw $original;
            }, 'exchangeCodeForToken');
            $this->fail('Expected InvalidPkceVerifierException to propagate.');
        } catch (InvalidPkceVerifierException $caught) {
            $this->assertSame($original, $caught);
        }
    }

    public function test_expired_authorization_code_exception_passes_through_unchanged(): void
    {
        $original = new ExpiredAuthorizationCodeException;

        try {
            $this->client->execute(function () use ($original): never {
                throw $original;
            }, 'exchangeCodeForToken');
            $this->fail('Expected ExpiredAuthorizationCodeException to propagate.');
        } catch (ExpiredAuthorizationCodeException $caught) {
            $this->assertSame($original, $caught);
        }
    }

    public function test_authorization_code_already_used_exception_passes_through_unchanged(): void
    {
        $original = new AuthorizationCodeAlreadyUsedException;

        try {
            $this->client->execute(function () use ($original): never {
                throw $original;
            }, 'exchangeCodeForToken');
            $this->fail('Expected AuthorizationCodeAlreadyUsedException to propagate.');
        } catch (AuthorizationCodeAlreadyUsedException $caught) {
            $this->assertSame($original, $caught);
        }
    }

    public function test_oauth_account_mismatch_exception_passes_through_unchanged(): void
    {
        $original = new OAuthAccountMismatchException;

        try {
            $this->client->execute(function () use ($original): never {
                throw $original;
            }, 'exchangeCodeForToken');
            $this->fail('Expected OAuthAccountMismatchException to propagate.');
        } catch (OAuthAccountMismatchException $caught) {
            $this->assertSame($original, $caught);
        }
    }

    // ---------------------------------------------------------------
    // SimulatedProviderFailureException -> SanitizedProviderHttpException
    // ---------------------------------------------------------------

    public function test_simulated_provider_failure_is_wrapped_into_a_sanitized_exception_preserving_category_and_status(): void
    {
        $secretLeakingMessage = 'raw provider response: Authorization: Bearer super-secret-token-xyz';

        try {
            $this->client->execute(function () use ($secretLeakingMessage): never {
                throw new SimulatedProviderFailureException(
                    category: SanitizedProviderHttpException::CATEGORY_INVALID_GRANT,
                    statusCode: 400,
                    message: $secretLeakingMessage,
                );
            }, 'refreshToken');
            $this->fail('Expected a SanitizedProviderHttpException.');
        } catch (SanitizedProviderHttpException $caught) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_INVALID_GRANT, $caught->category());
            $this->assertSame(400, $caught->statusCode());
            $this->assertStringNotContainsString('super-secret-token-xyz', $caught->getMessage());
            $this->assertStringNotContainsString($secretLeakingMessage, $caught->getMessage());
        }
    }

    public function test_simulated_provider_failure_with_no_status_code_is_wrapped_with_a_null_status(): void
    {
        try {
            $this->client->execute(function (): never {
                throw new SimulatedProviderFailureException(
                    category: SanitizedProviderHttpException::CATEGORY_NETWORK_ERROR,
                    statusCode: null,
                    message: 'connection reset',
                );
            }, 'refreshToken');
            $this->fail('Expected a SanitizedProviderHttpException.');
        } catch (SanitizedProviderHttpException $caught) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_NETWORK_ERROR, $caught->category());
            $this->assertNull($caught->statusCode());
        }
    }

    // ---------------------------------------------------------------
    // Any other Throwable -> generic, defensive fallback
    // ---------------------------------------------------------------

    public function test_an_arbitrary_throwable_is_wrapped_into_category_unknown_with_no_status_code(): void
    {
        $sensitiveMessage = 'Guzzle\\RequestException: headers={"Authorization":"Bearer real-secret"}';

        try {
            $this->client->execute(function () use ($sensitiveMessage): never {
                throw new RuntimeException($sensitiveMessage);
            }, 'someFutureLiveProviderCall');
            $this->fail('Expected a SanitizedProviderHttpException.');
        } catch (SanitizedProviderHttpException $caught) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_UNKNOWN, $caught->category());
            $this->assertNull($caught->statusCode());
            $this->assertStringNotContainsString('real-secret', $caught->getMessage());
            $this->assertStringNotContainsString($sensitiveMessage, $caught->getMessage());
        }
    }

    public function test_the_original_throwables_message_is_never_forwarded_anywhere_in_the_sanitized_exception(): void
    {
        $original = new RuntimeException('response body: {"access_token":"leak-me-if-you-can"}');

        try {
            $this->client->execute(function () use ($original): never {
                throw $original;
            }, 'exchangeCodeForToken');
            $this->fail('Expected a SanitizedProviderHttpException.');
        } catch (SanitizedProviderHttpException $caught) {
            $this->assertStringNotContainsString('leak-me-if-you-can', $caught->getMessage());
            $this->assertNotSame($original->getMessage(), $caught->getMessage());
            $this->assertNull($caught->getPrevious(), 'The original throwable must not be chained as the previous exception, since getPrevious()->getMessage() would re-expose it.');
        }
    }

    public function test_the_operation_label_appears_in_the_sanitized_message_but_the_category_and_status_remain_the_only_structured_fields(): void
    {
        try {
            $this->client->execute(function (): never {
                throw new RuntimeException('irrelevant');
            }, 'revokeAtProvider');
            $this->fail('Expected a SanitizedProviderHttpException.');
        } catch (SanitizedProviderHttpException $caught) {
            $this->assertStringContainsString('revokeAtProvider', $caught->getMessage());
        }
    }

    // ---------------------------------------------------------------
    // SanitizedProviderHttpException's own closed category enum
    // ---------------------------------------------------------------

    public function test_sanitized_exception_rejects_an_unknown_category_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SanitizedProviderHttpException('not_a_real_category', null, 'someOperation');
    }

    public function test_sanitized_exception_category_set_is_small_and_closed(): void
    {
        $reflection = new ReflectionClass(SanitizedProviderHttpException::class);
        $constants = $reflection->getConstants();

        $categoryConstants = array_filter(
            $constants,
            static fn ($value, $name) => str_starts_with($name, 'CATEGORY_'),
            ARRAY_FILTER_USE_BOTH,
        );

        $this->assertNotEmpty($categoryConstants);
        // CHECKPOINT 8 UPDATE (reviews/checkpoint-08/diff-review.md §5
        // item 4; agent-8h-architecture-security-review.md's frozen
        // retry-taxonomy expansion): the closed category vocabulary grew
        // intentionally and correctly from 5 to 13 — 8 new categories
        // (rate_limited, authentication_failed, authorization_failed,
        // malformed_response, validation_failed, conflict,
        // configuration_error, connection_unavailable) were deliberately
        // added to support Checkpoint 8's retry/health-signal
        // classification.
        // FirmsVault Live Integrations Checkpoint 2 UPDATE (Microsoft 365
        // provider — checkpoint2-combined-design.md §2 P-9; §2 P-7c): the
        // closed category vocabulary grew intentionally and correctly
        // again, from 13 to 14 — one new category, CATEGORY_CURSOR_EXPIRED
        // ('cursor_expired'), deliberately added for
        // ProviderRequestExecutor::categorizeStatus()'s new 410 Gone arm
        // (Microsoft's delta-query cursor-expiry signal). The ceiling
        // below is bumped to the new, exact, intentionally authorized
        // count — verified directly via reflection against the real
        // source, not merely trusted — so this guard-rail still fails the
        // moment the set grows again WITHOUT a deliberate, reviewed
        // update.
        $this->assertLessThanOrEqual(14, count($categoryConstants), 'The category enum must remain small and closed, not grow into an open string.');
        $this->assertSame(14, count($categoryConstants), 'The category enum must be exactly the FirmsVault Live Integrations Checkpoint 2 frozen count of 14 — update this deliberately, with review, if it ever changes again.');
    }

    // ---------------------------------------------------------------
    // Behavioral proof against a REAL HTTP-call shape: no real network
    // call occurs, and the sanitized exception never leaks response
    // detail — replaces a prior raw-source-text scan for "Http::"/
    // "Guzzle"/etc, which would false-positive on a mere comment and
    // proves nothing about actual runtime behavior.
    // ---------------------------------------------------------------

    public function test_execute_sanitizes_a_failure_from_a_real_http_call_shape_with_no_real_network_call(): void
    {
        // preventStrayRequests() makes ANY request that is not matched
        // by an explicit Http::fake() rule throw immediately instead of
        // ever reaching a real socket — this is what proves "no real
        // network call occurs," not merely that a fake was configured.
        Http::preventStrayRequests();
        Http::fake([
            'https://provider.invalid.example/token' => Http::response(
                [
                    'error' => 'invalid_grant',
                    'error_description' => 'refresh_token revoked for client_secret=super-secret-client-value',
                ],
                400,
            ),
        ]);

        try {
            $this->client->execute(function () {
                $response = Http::post('https://provider.invalid.example/token', ['grant_type' => 'refresh_token']);

                if ($response->failed()) {
                    throw new SimulatedProviderFailureException(
                        category: SanitizedProviderHttpException::CATEGORY_INVALID_GRANT,
                        statusCode: $response->status(),
                        message: 'token endpoint rejected: '.$response->body(),
                    );
                }

                return $response->json();
            }, 'refreshToken');

            $this->fail('Expected a SanitizedProviderHttpException.');
        } catch (SanitizedProviderHttpException $caught) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_INVALID_GRANT, $caught->category());
            $this->assertSame(400, $caught->statusCode());
            $this->assertStringNotContainsString('super-secret-client-value', $caught->getMessage());
            $this->assertStringNotContainsString('error_description', $caught->getMessage());
            $this->assertStringNotContainsString('token endpoint rejected', $caught->getMessage(), 'The raw provider response text must not leak into the sanitized message.');
        }

        // Confirms exactly the ONE fake-intercepted call happened —
        // together with preventStrayRequests() above, this is the
        // behavioral proof that no real outbound socket call was ever
        // made by the closure execute() ran.
        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------------
    // Genuinely-static structural fact: this class has no HTTP client
    // (or any other) dependency at all. A reflection-based check on the
    // constructor/properties is the meaningful way to assert this —
    // not a text scan for library names, which would miss e.g. a
    // fully-qualified class-string stored as a property default.
    // ---------------------------------------------------------------

    public function test_class_has_no_constructor_and_no_properties_of_any_kind(): void
    {
        $reflection = new ReflectionClass(OutboundProviderHttpClient::class);

        $this->assertNull(
            $reflection->getConstructor(),
            'OutboundProviderHttpClient must have no constructor — and therefore no injectable HTTP client (or any other) dependency.'
        );

        $this->assertSame(
            [],
            $reflection->getProperties(),
            'OutboundProviderHttpClient must hold no properties at all — it only wraps a caller-supplied closure per execute() call, never a stored client/dependency reference.'
        );
    }
}
