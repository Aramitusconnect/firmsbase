<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Support;

use App\Integrations\Providers\GoogleWorkspace\GoogleWorkspaceProvider;
use Google\Auth\AccessToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;
use UnexpectedValueException;

/**
 * GoogleOidcJwtVerificationTest — FirmsVault Live Integrations,
 * Checkpoint 3 (test-writer pass). The DEDICATED, EXHAUSTIVE security
 * test matrix for Gmail's inbound Cloud Pub/Sub push OIDC JWT
 * verification (checkpoint3-design-sync-webhooks.md §6.3.1/§6.3.3,
 * §10.1; checkpoint3-security-review.md Finding 2/Finding 4/Finding 5).
 *
 * This is a genuinely new, security-critical capability with no
 * precedent anywhere in this codebase (checkpoint3-combined-design.md
 * §2's headline finding) — an INBOUND, attacker-reachable webhook
 * endpoint authenticated by a real, independently-verifiable OIDC JWT
 * bearer token, not a shared-secret match. Every check below is proven
 * as a fail-closed AND, exactly matching
 * GoogleWorkspaceProvider::verifyPubSubOidcToken()'s own documented
 * contract: Bearer-shape check before any parsing; RS256
 * signature+aud+iss+exp (delegated to, and enforced by,
 * Google\Auth\AccessToken::verify() itself — spot-checked live against
 * the library's real source per checkpoint3-security-review.md Finding
 * 4); a defense-in-depth re-check of iss; exact-match (hash_equals()) on
 * the configured push-auth service-account email; strict
 * email_verified === true; iat not issued in the future beyond a
 * bounded clock-skew allowance.
 *
 * SUSPECTED PRODUCTION DEFECT flagged by this file (not fixed here, per
 * this task's test-writer scope): test_accepts_a_jwt_whose_issuer_is_the_bare_accounts_google_com_form()
 * asserts the binding design's documented two-value iss check
 * ("accounts.google.com" or "https://accounts.google.com") but the
 * shipped GoogleWorkspaceProvider::verifyPubSubOidcToken() only accepts
 * the https:// form — see that test's own docblock for the full
 * analysis. This test is expected to FAIL against the current code.
 *
 * `Google\Auth\AccessToken` is NEVER the real class in this file — every
 * scenario swaps it for a test double via app()->instance(), the exact
 * container-swap mechanism checkpoint3-security-review.md Finding 2
 * mandates (identical precedent to
 * ProviderConnectionServiceTenantMismatchTest.php:320/
 * ProviderConnectionServiceOAuthTest.php:1090's own
 * app()->instance($class, $provider) usage). No test in this file EVER
 * reaches Google's real cert endpoint — the fixture-signed "JWT" values
 * used below are opaque strings; this file tests
 * GoogleWorkspaceProvider's OWN claim-policy code (§6.3.3), never
 * firebase/php-jwt's or google/auth's internal RS256 verification
 * itself (that library behavior was independently spot-checked directly
 * against the library's real source by the security review, not
 * re-tested here).
 */
final class GoogleOidcJwtVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const AUDIENCE = 'unit-test-pubsub-audience';

    private const SERVICE_ACCOUNT_EMAIL = 'push@unit-test.iam.gserviceaccount.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'integrations.oauth_apps.googleworkspace.pubsub_push_audience' => self::AUDIENCE,
            'integrations.oauth_apps.googleworkspace.pubsub_push_service_account_email' => self::SERVICE_ACCOUNT_EMAIL,
        ]);
    }

    private function provider(): GoogleWorkspaceProvider
    {
        return app(GoogleWorkspaceProvider::class);
    }

    private function validClaims(array $overrides = []): array
    {
        return array_replace([
            'iss' => 'https://accounts.google.com',
            'email' => self::SERVICE_ACCOUNT_EMAIL,
            'email_verified' => true,
            'iat' => now()->getTimestamp(),
        ], $overrides);
    }

    /**
     * Records every call made to verify() (token + options) so tests can
     * assert the provider never calls the library at all for a
     * shape-rejected header, and that it passes the correct configured
     * audience through.
     */
    private function bindFake(mixed $claimsOrFalse, ?Throwable $throws = null): object
    {
        $spy = new class($claimsOrFalse, $throws) extends AccessToken
        {
            public array $calls = [];

            public function __construct(private readonly mixed $claimsOrFalse, private readonly ?Throwable $throws) {}

            public function verify($token, array $options = [])
            {
                $this->calls[] = ['token' => $token, 'options' => $options];

                if ($this->throws !== null) {
                    throw $this->throws;
                }

                return $this->claimsOrFalse ?? false;
            }
        };

        app()->instance(AccessToken::class, $spy);

        return $spy;
    }

    // ------------------------------------------------------------
    // Bearer header shape — checked BEFORE any parsing/verification
    // ------------------------------------------------------------

    public function test_missing_authorization_header_is_rejected_without_ever_calling_the_verifier(): void
    {
        $fake = $this->bindFake($this->validClaims());

        $result = $this->provider()->verifyInboundSignature('{}', []);

        $this->assertFalse($result);
        $this->assertSame([], $fake->calls, 'A missing Authorization header must be rejected on shape alone, never reaching the verifier.');
    }

    public function test_empty_authorization_header_is_rejected_without_ever_calling_the_verifier(): void
    {
        $fake = $this->bindFake($this->validClaims());

        $result = $this->provider()->verifyInboundSignature('{}', ['Authorization' => '']);

        $this->assertFalse($result);
        $this->assertSame([], $fake->calls);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedBearerHeaderProvider(): array
    {
        return [
            'no scheme at all' => ['just-a-raw-token-value'],
            'wrong scheme' => ['Basic dXNlcjpwYXNz'],
            'lowercase bearer' => ['bearer a-fixture-jwt'],
            'Bearer with no trailing space' => ['Bearer'],
            'Bearer with no token after the space' => ['Bearer '],
            'leading whitespace before Bearer' => [' Bearer a-fixture-jwt'],
        ];
    }

    #[DataProvider('malformedBearerHeaderProvider')]
    public function test_malformed_bearer_header_shapes_are_rejected_without_ever_calling_the_verifier(string $header): void
    {
        $fake = $this->bindFake($this->validClaims());

        $result = $this->provider()->verifyInboundSignature('{}', ['Authorization' => $header]);

        $this->assertFalse($result, "Header \"{$header}\" must be rejected.");
        $this->assertSame([], $fake->calls, "Header \"{$header}\" must never reach the verifier.");
    }

    public function test_a_non_string_authorization_header_value_never_throws(): void
    {
        $this->bindFake($this->validClaims());

        $this->assertFalse($this->provider()->verifyInboundSignature('{}', ['Authorization' => ['nested' => 'array']]));
        $this->assertFalse($this->provider()->verifyInboundSignature('{}', ['Authorization' => 12345]));
        $this->assertFalse($this->provider()->verifyInboundSignature('{}', ['Authorization' => null]));
    }

    // ------------------------------------------------------------
    // The verifier itself rejecting the token
    // ------------------------------------------------------------

    /**
     * Represents an invalid RS256 signature — Google\Auth\AccessToken::verify()
     * throws (or, per its own non-throwException-by-default behavior,
     * could also return false) when the signature does not verify.
     */
    public function test_rejects_a_jwt_with_an_invalid_signature(): void
    {
        $this->bindFake(null, new UnexpectedValueException('Signature verification failed'));

        $result = $this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']);

        $this->assertFalse($result);
    }

    public function test_rejects_when_the_verifier_returns_false(): void
    {
        $this->bindFake(false);

        $result = $this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']);

        $this->assertFalse($result);
    }

    /**
     * checkpoint3-security-review.md Finding 4 — AccessToken::verify()
     * itself throws on an audience mismatch (spot-checked live against
     * the library's real source). This provider passes the exact
     * configured audience through — verified here.
     */
    public function test_rejects_a_jwt_with_the_wrong_audience_and_passes_the_configured_audience_to_the_verifier(): void
    {
        $fake = $this->bindFake(null, new UnexpectedValueException('Audience does not match'));

        $result = $this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']);

        $this->assertFalse($result);
        $this->assertCount(1, $fake->calls);
        $this->assertSame(self::AUDIENCE, $fake->calls[0]['options']['audience'] ?? null, 'The provider must pass the configured pubsub_push_audience through to AccessToken::verify().');
    }

    /**
     * checkpoint3-security-review.md Finding 4 — an expired exp claim
     * causes AccessToken::verify() to throw internally (via
     * firebase/php-jwt's own decode), never a soft pass.
     */
    public function test_rejects_an_expired_jwt(): void
    {
        $this->bindFake(null, new UnexpectedValueException('Expired token'));

        $result = $this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']);

        $this->assertFalse($result);
    }

    // ------------------------------------------------------------
    // Claim-policy re-checks GoogleWorkspaceProvider itself performs
    // (defense-in-depth on iss, plus checks the library does not itself
    // perform: exact email match, strict email_verified, iat freshness)
    // ------------------------------------------------------------

    public function test_rejects_a_jwt_whose_issuer_claim_does_not_match_even_if_the_verifier_returned_it(): void
    {
        $this->bindFake($this->validClaims(['iss' => 'https://attacker-issuer.invalid']));

        $result = $this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']);

        $this->assertFalse($result);
    }

    /**
     * SUSPECTED PRODUCTION DEFECT, deliberately asserted against the
     * DESIGN'S binding requirement, not against the shipped code's
     * actual behavior (per this task's instruction to never silently
     * weaken a test to match broken behavior). checkpoint3-design-sync-webhooks.md
     * §6.3.1 requirement #3 states, for THIS Gmail Pub/Sub JWT
     * verification specifically: "`iss` (issuer) equals `accounts.google.com`
     * or `https://accounts.google.com`" — a two-value check, mirroring
     * the exact same discipline GoogleWorkspaceProvider::decodeAndValidateIdToken()
     * already correctly applies for the (structurally different) OAuth
     * ID-token path a few hundred lines away in the SAME file
     * (`hash_equals('https://accounts.google.com', $iss) ||
     * hash_equals('accounts.google.com', $iss)`).
     *
     * As actually shipped, GoogleWorkspaceProvider::verifyPubSubOidcToken()'s
     * defense-in-depth iss re-check only accepts the exact
     * "https://accounts.google.com" form:
     *
     *   return is_string($iss) && hash_equals('https://accounts.google.com', $iss) && ...
     *
     * `Google\Auth\AccessToken::verify()` itself accepts EITHER form by
     * default (checkpoint3-security-review.md Finding 4, spot-checked
     * live against the library's real source) — so a genuine,
     * library-verified, legitimately-issued Pub/Sub push JWT whose `iss`
     * claim happens to be the bare "accounts.google.com" form would pass
     * the library's own check yet be silently rejected by this
     * provider's OWN re-check, a false-negative (never a security hole —
     * fail-closed direction — but a real, disclosed-vs-shipped
     * availability discrepancy against the binding design). This test
     * asserts the CORRECT, per-design behavior and is therefore expected
     * to FAIL against the current implementation — see this test file's
     * class docblock / this checkpoint's final report for the flag.
     */
    public function test_accepts_a_jwt_whose_issuer_is_the_bare_accounts_google_com_form(): void
    {
        $this->bindFake($this->validClaims(['iss' => 'accounts.google.com']));

        $result = $this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']);

        $this->assertTrue(
            $result,
            'checkpoint3-design-sync-webhooks.md §6.3.1 requirement #3 requires accepting BOTH "accounts.google.com" and '.
            '"https://accounts.google.com" as valid iss values for the Gmail Pub/Sub OIDC JWT — the shipped '.
            'verifyPubSubOidcToken() only accepts the https:// form, a suspected divergence from the binding design.'
        );
    }

    public function test_rejects_a_jwt_with_the_wrong_service_account_email(): void
    {
        $this->bindFake($this->validClaims(['email' => 'attacker@not-the-real-service-account.test']));

        $result = $this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']);

        $this->assertFalse($result);
    }

    public function test_rejects_a_jwt_missing_the_email_claim_entirely(): void
    {
        $claims = $this->validClaims();
        unset($claims['email']);

        $this->bindFake($claims);

        $this->assertFalse($this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']));
    }

    public function test_rejects_a_jwt_where_email_verified_is_false(): void
    {
        $this->bindFake($this->validClaims(['email_verified' => false]));

        $result = $this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']);

        $this->assertFalse($result);
    }

    public function test_rejects_a_jwt_missing_email_verified_entirely(): void
    {
        $claims = $this->validClaims();
        unset($claims['email_verified']);

        $this->bindFake($claims);

        $this->assertFalse($this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']));
    }

    /**
     * Strict `=== true`, never merely truthy — a string "true" or an
     * integer 1 must NOT satisfy this check.
     */
    public function test_rejects_a_jwt_where_email_verified_is_merely_truthy_not_strictly_true(): void
    {
        $this->bindFake($this->validClaims(['email_verified' => 1]));
        $this->assertFalse($this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']));

        $this->bindFake($this->validClaims(['email_verified' => 'true']));
        $this->assertFalse($this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']));
    }

    /**
     * checkpoint3-design-sync-webhooks.md §6.3.1 requirement #6 (iat
     * half) — AccessToken::verify() itself rejects an expired exp; it
     * does NOT reject a future-issued iat, so GoogleWorkspaceProvider
     * re-asserts that half explicitly, with a bounded clock-skew
     * allowance.
     */
    public function test_rejects_a_jwt_issued_too_far_in_the_future(): void
    {
        $this->bindFake($this->validClaims(['iat' => now()->addHour()->getTimestamp()]));

        $result = $this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']);

        $this->assertFalse($result);
    }

    public function test_rejects_a_jwt_missing_the_iat_claim_entirely(): void
    {
        $claims = $this->validClaims();
        unset($claims['iat']);

        $this->bindFake($claims);

        $this->assertFalse($this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']));
    }

    public function test_accepts_an_iat_within_the_bounded_clock_skew_allowance(): void
    {
        // 300 seconds is the documented CLOCK_SKEW_SECONDS constant —
        // a small amount inside that window must still pass.
        $this->bindFake($this->validClaims(['iat' => now()->addSeconds(60)->getTimestamp()]));

        $result = $this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']);

        $this->assertTrue($result);
    }

    // ------------------------------------------------------------
    // The positive path (closes checkpoint3-security-review.md Finding 5)
    // ------------------------------------------------------------

    public function test_accepts_a_correctly_verified_jwt_with_every_claim_valid(): void
    {
        $fake = $this->bindFake($this->validClaims());

        $result = $this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']);

        $this->assertTrue($result);
        $this->assertCount(1, $fake->calls);
        $this->assertSame('a-fixture-jwt', $fake->calls[0]['token'], 'The Bearer prefix must be stripped before the token is handed to the verifier.');
    }

    public function test_verify_inbound_signature_never_throws_on_a_non_array_verifier_result(): void
    {
        // A pathological/future library behavior — must fail closed, not
        // crash with a TypeError.
        $this->bindFake('not-an-array-or-false');

        $result = $this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']);

        $this->assertFalse($result);
    }

    // ------------------------------------------------------------
    // Never logs or persists the raw Authorization header or JWT
    // (checkpoint3-design-sync-webhooks.md §10.1)
    // ------------------------------------------------------------

    public function test_verification_never_logs_the_raw_authorization_header_or_jwt(): void
    {
        Log::spy();
        $this->bindFake($this->validClaims());

        $rawToken = 'super-secret-fixture-jwt-value-that-must-never-appear-in-a-log';
        $this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer '.$rawToken]);

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    public function test_verification_failure_never_logs_the_raw_authorization_header_or_jwt(): void
    {
        Log::spy();
        $this->bindFake(null, new UnexpectedValueException('Signature verification failed'));

        $rawToken = 'super-secret-fixture-jwt-value-that-must-never-appear-in-a-log-on-failure-either';
        $this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer '.$rawToken]);

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    /**
     * Verification is a pure, in-memory boolean check — it must never
     * issue a database write of any kind (and therefore can never
     * persist the raw token in any table), regardless of outcome.
     */
    public function test_verification_never_writes_to_the_database(): void
    {
        $this->bindFake($this->validClaims());

        $writeStatements = [];
        DB::listen(function ($query) use (&$writeStatements): void {
            $sql = strtolower($query->sql);

            if (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update') || str_starts_with($sql, 'delete')) {
                $writeStatements[] = $query->sql;
            }
        });

        $this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt-that-must-never-be-persisted']);

        $this->assertSame([], $writeStatements, 'verifyInboundSignature() must never write to the database — the raw JWT must never be persisted.');
    }
}
