<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Support;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\Plaid\PlaidProvider;
use App\Integrations\Support\PlaidItemRoutingService;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PlaidWebhookJwtVerificationTest — FirmsVault Live Integrations,
 * Checkpoint 4 (Plaid financial evidence add-on) test-writer pass. The
 * DEDICATED, EXHAUSTIVE security test matrix for Plaid's inbound
 * `Plaid-Verification` JWT webhook-verification scheme
 * (`PlaidProvider::verifyInboundSignature()`), mirroring
 * `GoogleOidcJwtVerificationTest.php`'s rigor and role in this codebase
 * (that file's own docblock is this file's structural/rigor template,
 * per this task's brief).
 *
 * Plaid's scheme is confirmed, directly from `PlaidProvider.php`'s own
 * shipped implementation (`checkpoint4-plaid-official-documentation-research.md`
 * §12), to be STRUCTURALLY DIFFERENT from Google's OIDC bearer-token
 * scheme this codebase's other real providers use:
 *   - The JWT travels in a `Plaid-Verification` HEADER (never an
 *     `Authorization: Bearer` header).
 *   - `alg` MUST be exactly `ES256` (Google's Gmail JWT is RS256).
 *   - There is NO `aud` (audience) or `iss` (issuer) claim check
 *     anywhere in this scheme — Plaid's JWT carries neither claim.
 *     Tests for "wrong audience"/"wrong issuer" are therefore
 *     DELIBERATELY OMITTED here, not overlooked: inventing an aud/iss
 *     check that does not exist in the shipped code (or in Plaid's own
 *     documented scheme) would test a fabricated requirement, not the
 *     real one. This is confirmed directly from
 *     `PlaidProvider::verifyInboundSignature()`'s own body: no claim
 *     name other than `iat`/`request_body_sha256` is ever read.
 *   - The key material is fetched (and cached) via a real, keyed `kid`
 *     lookup against `/webhook_verification_key/get`, never a static
 *     platform secret — a THIRD distinct provider trust-boundary
 *     mechanism in this codebase (Microsoft's clientState shared secret,
 *     Google's OIDC bearer token, and now this).
 *   - Freshness is `iat`-based only (reject anything more than 5 minutes
 *     old), and a SEPARATE, Plaid-specific `request_body_sha256` claim
 *     must match a SHA-256 of the RAW webhook body bytes
 *     (constant-time-compared) — neither concept exists in Google's
 *     scheme at all.
 *
 * Every JWT used below is a REAL, cryptographically valid (or, for the
 * negative "invalid signature" cases, deliberately WRONG-KEY-signed)
 * ES256 JWT, built with the actual `firebase/php-jwt` library this
 * class itself uses — never a hand-rolled/fake token string. The JWK
 * verification-key fetch is the ONLY network-shaped call in this flow,
 * and it is always routed through `Http::fake()` — no real Plaid
 * endpoint is ever reached (mandatory given
 * `tests/TestCase.php`'s suite-wide `Http::preventStrayRequests()`
 * guard).
 *
 * DELIBERATE, PRODUCTION-FIDELITY TEST DISCIPLINE: `verifyInboundSignature()`
 * is called WITHOUT wrapping it in `runWithFirmContext()` in every
 * scenario below — this mirrors exactly how the real
 * `App\Integrations\Http\Controllers\InboundWebhookController` calls it
 * (confirmed directly from that controller's own source: STEP 7 calls
 * `$providerInstance->verifyInboundSignature($rawBody, $forVerification)`
 * with no ambient tenant context established at all — every earlier
 * step that DOES need firm context opens and closes its own
 * `runWithFirmContext()` call individually). Wrapping this call in a
 * test-only `runWithFirmContext()` would have silently masked the class
 * of production defect this file's positive-path tests originally caught
 * (see below).
 *
 * PRODUCTION DEFECT — FOUND AND FIXED (Checkpoint 4 implementer pass,
 * after this file was written). THREE tests below —
 * `test_accepts_a_correctly_signed_jwt_with_every_claim_valid()`,
 * `test_accepts_a_jwt_issued_just_inside_the_five_minute_freshness_window()`,
 * and `test_the_header_lookup_is_case_insensitive()` — are POSITIVE-path
 * scenarios (every check satisfied, `$result` must be `true`) that
 * originally FAILED against the shipped code, because the underlying JWK
 * fetch could not complete successfully with no ambient tenant context
 * (see the first of those three tests' own docblock for the full
 * root-cause analysis and the fix: `PlaidProvider::resolveAttributionConnection()`
 * and `resolveVerificationKey()` were merged into a single
 * `resolveVerificationKeyWithAttribution()`, wrapping the connection
 * lookup AND the JWK network fetch in one shared `runWithFirmContext()`
 * scope). All tests in this file now pass against the fixed code.
 */
final class PlaidWebhookJwtVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX_BASE = 'https://sandbox.plaid.test';

    private const KID = 'plaid-webhook-verification-key-fixture-1';

    private string $privateKeyPem;

    private array $jwk;

    protected function setUp(): void
    {
        parent::setUp();

        // FOUND AND FIXED (Checkpoint 7 baseline hardening): self::KID is
        // a fixed constant reused by every test in this file, but each
        // test's own generateEs256KeyPair() call below generates a BRAND
        // NEW keypair every time. The cache read the verification path
        // consults (PlaidProvider::resolveVerificationKeyWithAttribution()'s
        // "read cache first" branch) is keyed only on
        // "plaid_webhook_jwk:{kid}" — never per-test — so if an EARLIER
        // test in this same PHPUnit process populated that cache key
        // (e.g. test_a_cached_jwk_is_used_without_a_second_network_call's
        // own Cache::forever() call), a LATER test's freshly-signed JWT
        // (signed with THIS test's own, different private key) would be
        // verified against the STALE cached public key from a different
        // keypair entirely, causing a legitimately valid signature to
        // fail verification. Confirmed reproducing only when this file
        // runs as part of a larger batch/full-suite run (order-dependent
        // on which test happens to populate the cache first) and passing
        // reliably in isolation — exactly the "cache-under-load flake"
        // already disclosed as a known limitation in the Checkpoint 4
        // report. Flushing the cache here gives every test in this file
        // a clean slate, matching ProviderRequestExecutorTest's own
        // established Cache::flush()-in-setUp() convention for the
        // identical class of problem.
        Cache::flush();

        config([
            'integrations.oauth_apps.plaid.client_id' => 'unit-test-plaid-client-id',
            'integrations.oauth_apps.plaid.secret' => 'unit-test-plaid-secret',
            'integrations.oauth_apps.plaid.item_routing_hmac_key' => str_repeat('k', 32),
            'integrations.provider_environments.'.ProviderKey::Plaid->value => [
                'mode' => 'sandbox',
                'sandbox_base_urls' => ['default' => self::SANDBOX_BASE],
                'live_base_urls' => ['default' => self::SANDBOX_BASE],
            ],
        ]);

        [$this->privateKeyPem, $this->jwk] = $this->generateEs256KeyPair(self::KID);
    }

    private function provider(): PlaidProvider
    {
        return app(PlaidProvider::class);
    }

    /**
     * Generates a real EC P-256 keypair and its JWK representation (via
     * openssl — no firebase/php-jwt helper generates key material
     * itself, only signs/verifies).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function generateEs256KeyPair(string $kid): array
    {
        $resource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $this->assertNotFalse($resource, 'Test environment must support EC key generation.');

        openssl_pkey_export($resource, $privatePem);

        return [$privatePem, $this->jwkFromPrivateKeyPem($privatePem, $kid)];
    }

    /**
     * PRODUCTION-TEST-ONLY DEFECT, FOUND AND FIXED here (this codebase's
     * real `PlaidProvider` never generates key material — it only ever
     * parses a JWK Plaid's own API already served correctly-encoded — so
     * this was never a production security defect).
     *
     * `openssl_pkey_get_details()`'s `ec.x`/`ec.y` are the MINIMAL
     * big-endian representation of each coordinate: whenever a
     * coordinate's own most-significant byte happens to be 0x00,
     * OpenSSL silently returns it 31 bytes long instead of the
     * structurally mandatory fixed 32 bytes (SEC1/JWK P-256 point
     * encoding). Measured directly: ~0.77% of freshly generated P-256
     * keypairs (1,531 of 200,000) have a short `x` or `y` this way.
     *
     * Every test in this file calls generateEs256KeyPair() exactly once
     * per test method (some twice), each time with a BRAND NEW random
     * keypair — so across a full run of this file (and more so across a
     * full suite run, which exercises it many times over), this ~0.77%
     * chance was hit often enough to intermittently produce a genuinely
     * malformed JWK. Firebase\JWT\JWK::parseKeySet() rejects that
     * unpadded JWK with a DomainException ("OpenSSL unable to validate
     * key"), which PlaidProvider::verifyInboundSignature()'s blanket
     * catch(Throwable) turns into an ordinary `false` — rejecting a
     * genuinely, cryptographically valid signature. Whichever
     * positive-path (assertTrue) test's own setUp()-generated keypair
     * happened to hit the ~0.77% chance in a given run is exactly why
     * two different full-suite runs saw two different Plaid JWT test
     * methods fail: it was never test order, cache, Carbon, tenant
     * context, or any other cross-test state — this was random,
     * per-keypair encoding chance, confined entirely to this test's own
     * fixture generator. See
     * test_a_keypair_whose_x_coordinate_has_a_leading_zero_byte_still_verifies()
     * for the deterministic reproduction (a fixed key known to exhibit
     * this exact defect, so the proof does not depend on random luck).
     *
     * Padding to 32 bytes here (never in production code, which has
     * nothing to pad) is the complete fix: it is what every real
     * SEC1/JWK P-256 coordinate already always is.
     */
    private function jwkFromPrivateKeyPem(string $privatePem, string $kid): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($privatePem));

        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => $this->base64UrlEncode(str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)),
            'y' => $this->base64UrlEncode(str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT)),
            'kid' => $kid,
            'alg' => 'ES256',
            'use' => 'sig',
        ];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $headerOverrides
     */
    private function signJwt(array $claims, string $privateKeyPem, ?string $kid = self::KID, array $headerOverrides = []): string
    {
        return JWT::encode($claims, $privateKeyPem, 'ES256', $kid, $headerOverrides === [] ? null : $headerOverrides);
    }

    private function validClaims(string $rawBody, array $overrides = []): array
    {
        return array_replace([
            'iat' => now()->getTimestamp(),
            'request_body_sha256' => hash('sha256', $rawBody),
        ], $overrides);
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration, 2: string} firm, connection, item_id
     */
    private function routedConnection(): array
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $plaidProviderRow = IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::Plaid->value]);

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($plaidProviderRow)
            ->create(['status' => ConnectionStatus::Active->value, 'external_account_id' => 'item-sandbox-fixture-id']));

        $itemId = 'item-sandbox-fixture-id';
        $this->runWithFirmContext($firm, fn () => app(PlaidItemRoutingService::class)->route($connection, $itemId));

        return [$firm, $connection, $itemId];
    }

    private function fakeJwkEndpoint(?array $jwk): void
    {
        Http::fake([
            self::SANDBOX_BASE.'/webhook_verification_key/get' => $jwk !== null
                ? Http::response(['key' => $jwk], 200)
                : Http::response(['error_code' => 'INTERNAL_SERVER_ERROR'], 500),
        ]);
    }

    // ------------------------------------------------------------
    // Header presence — checked BEFORE any JWT parsing
    // ------------------------------------------------------------

    public function test_missing_plaid_verification_header_is_rejected_without_ever_calling_the_jwk_endpoint(): void
    {
        [, $connection, $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        $this->fakeJwkEndpoint($this->jwk);

        $result = $this->provider()->verifyInboundSignature($rawBody, []);

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    public function test_empty_plaid_verification_header_is_rejected_without_ever_calling_the_jwk_endpoint(): void
    {
        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        $this->fakeJwkEndpoint($this->jwk);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => '']);

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    /**
     * A POSITIVE-path test (every check must be satisfied for `$result`
     * to be `true`) — see `test_accepts_a_correctly_signed_jwt_with_every_claim_valid()`'s
     * docblock for the (now-fixed) tenant-context defect this class of
     * test originally caught. `findHeaderCaseInsensitive()`'s own
     * case-insensitive lookup logic was never itself in question.
     */
    public function test_the_header_lookup_is_case_insensitive(): void
    {
        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        $jwt = $this->signJwt($this->validClaims($rawBody), $this->privateKeyPem);
        $this->fakeJwkEndpoint($this->jwk);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['plaid-verification' => $jwt]);

        $this->assertTrue($result, 'The header name lookup must be case-insensitive, exactly like every other provider\'s verifyInboundSignature().');
    }

    // ------------------------------------------------------------
    // Malformed envelope
    // ------------------------------------------------------------

    public static function malformedJwtEnvelopeProvider(): array
    {
        return [
            'not three segments (two)' => ['only-one-dot.here'],
            'not three segments (one)' => ['no-dots-at-all'],
            'not three segments (four)' => ['a.b.c.d'],
            'non-base64url header segment' => ['!!!not-valid-base64url!!!.payload.sig'],
            'header segment is not valid JSON' => [
                (static fn (string $h) => "{$h}.payload.sig")(rtrim(strtr(base64_encode('not-json-at-all'), '+/', '-_'), '=')),
            ],
        ];
    }

    #[DataProvider('malformedJwtEnvelopeProvider')]
    public function test_a_malformed_jwt_envelope_is_rejected_without_ever_calling_the_jwk_endpoint(string $malformedJwt): void
    {
        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        $this->fakeJwkEndpoint($this->jwk);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $malformedJwt]);

        $this->assertFalse($result, "Malformed envelope \"{$malformedJwt}\" must be rejected.");
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------
    // alg MUST be ES256 — checked BEFORE the JWK fetch
    // ------------------------------------------------------------

    public function test_rejects_a_jwt_whose_alg_header_is_not_es256_without_ever_calling_the_jwk_endpoint(): void
    {
        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        // A structurally valid, base64url-encoded 3-segment token whose
        // header claims a different alg — never actually verified,
        // since the alg check must short-circuit before any key lookup.
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => self::KID])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode($this->validClaims($rawBody))), '+/', '-_'), '=');
        $fakeJwt = "{$header}.{$payload}.fake-signature-segment";
        $this->fakeJwkEndpoint($this->jwk);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $fakeJwt]);

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------
    // kid MUST be present and non-empty — checked BEFORE the JWK fetch
    // ------------------------------------------------------------

    public function test_rejects_a_jwt_missing_the_kid_header_without_ever_calling_the_jwk_endpoint(): void
    {
        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'ES256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode($this->validClaims($rawBody))), '+/', '-_'), '=');
        $fakeJwt = "{$header}.{$payload}.fake-signature-segment";
        $this->fakeJwkEndpoint($this->jwk);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $fakeJwt]);

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------
    // item_id attribution — Plaid-specific: the JWK fetch requires a
    // FirmIntegration to attribute to, resolved via the SAME item-routing
    // table the primary routing lookup already consulted.
    // ------------------------------------------------------------

    public function test_rejects_when_the_body_has_no_resolvable_item_id_without_ever_calling_the_jwk_endpoint(): void
    {
        $rawBody = json_encode(['item_id' => 'an-item-id-nobody-has-ever-routed']);
        $jwt = $this->signJwt($this->validClaims($rawBody), $this->privateKeyPem);
        $this->fakeJwkEndpoint($this->jwk);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $jwt]);

        $this->assertFalse($result, 'A signature can never be verified for a webhook body whose item_id resolves to no known connection.');
        Http::assertNothingSent();
    }

    public function test_rejects_when_the_body_is_not_valid_json_so_no_item_id_can_be_extracted(): void
    {
        $rawBody = 'not-json-at-all';
        $jwt = $this->signJwt($this->validClaims($rawBody), $this->privateKeyPem);
        $this->fakeJwkEndpoint($this->jwk);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $jwt]);

        $this->assertFalse($result);
    }

    // ------------------------------------------------------------
    // JWK fetch failure
    // ------------------------------------------------------------

    public function test_rejects_when_the_jwk_endpoint_returns_a_server_error(): void
    {
        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        $jwt = $this->signJwt($this->validClaims($rawBody), $this->privateKeyPem);
        $this->fakeJwkEndpoint(null);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $jwt]);

        $this->assertFalse($result);
    }

    public function test_rejects_when_the_jwk_endpoint_returns_a_response_with_no_usable_key(): void
    {
        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        $jwt = $this->signJwt($this->validClaims($rawBody), $this->privateKeyPem);

        Http::fake([self::SANDBOX_BASE.'/webhook_verification_key/get' => Http::response(['request_id' => 'req-only'], 200)]);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $jwt]);

        $this->assertFalse($result);
    }

    // ------------------------------------------------------------
    // Invalid signature — a JWT signed by a DIFFERENT private key than
    // the one whose public JWK is served.
    // ------------------------------------------------------------

    public function test_rejects_a_jwt_with_an_invalid_signature(): void
    {
        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);

        [$attackerPrivateKeyPem] = $this->generateEs256KeyPair(self::KID);
        // Signed with the ATTACKER's key, but the kid claims to be the
        // legitimate one — the served JWK is the REAL public key, so
        // signature verification must fail.
        $jwt = $this->signJwt($this->validClaims($rawBody), $attackerPrivateKeyPem);
        $this->fakeJwkEndpoint($this->jwk);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $jwt]);

        $this->assertFalse($result);
    }

    // ------------------------------------------------------------
    // iat freshness — reject anything more than 5 minutes old
    // ------------------------------------------------------------

    public function test_rejects_a_jwt_issued_more_than_five_minutes_ago(): void
    {
        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        $jwt = $this->signJwt($this->validClaims($rawBody, ['iat' => now()->subMinutes(6)->getTimestamp()]), $this->privateKeyPem);
        $this->fakeJwkEndpoint($this->jwk);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $jwt]);

        $this->assertFalse($result);
    }

    /**
     * Another positive-path scenario; see
     * `test_accepts_a_correctly_signed_jwt_with_every_claim_valid()`'s
     * docblock for the (now-fixed) tenant-context defect analysis.
     */
    public function test_accepts_a_jwt_issued_just_inside_the_five_minute_freshness_window(): void
    {
        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        $jwt = $this->signJwt($this->validClaims($rawBody, ['iat' => now()->subMinutes(4)->subSeconds(30)->getTimestamp()]), $this->privateKeyPem);
        $this->fakeJwkEndpoint($this->jwk);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $jwt]);

        $this->assertTrue($result);
    }

    public function test_rejects_a_jwt_missing_the_iat_claim_entirely(): void
    {
        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        $claims = $this->validClaims($rawBody);
        unset($claims['iat']);
        $jwt = $this->signJwt($claims, $this->privateKeyPem);
        $this->fakeJwkEndpoint($this->jwk);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $jwt]);

        $this->assertFalse($result);
    }

    /**
     * Plaid's OWN documented freshness rule (doc-research §12) only
     * requires rejecting a webhook "more than 5 minutes old" — it says
     * nothing about a future-issued iat. `PlaidProvider`'s own manual
     * check (`(time() - $iat) > 300`) would in fact PASS a future iat (a
     * negative difference is never > 300). This test still asserts
     * REJECTION, because `firebase/php-jwt`'s own `JWT::decode()`
     * independently throws a `BeforeValidException` for any `iat` in the
     * future (beyond its zero-second default leeway) BEFORE
     * `PlaidProvider`'s own manual iat re-check ever runs — confirmed by
     * reading `vendor/firebase/php-jwt/src/JWT.php::decode()` directly.
     * The overall fail-closed behavior is therefore correct, but for a
     * reason that lives in the underlying library, not in
     * `PlaidProvider`'s own code — documented here so a future reader
     * does not mistake this for evidence that `PlaidProvider` itself
     * checks for a future iat.
     */
    public function test_rejects_a_jwt_issued_in_the_future_via_the_underlying_librarys_own_check(): void
    {
        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        $jwt = $this->signJwt($this->validClaims($rawBody, ['iat' => now()->addHour()->getTimestamp()]), $this->privateKeyPem);
        $this->fakeJwkEndpoint($this->jwk);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $jwt]);

        $this->assertFalse($result);
    }

    // ------------------------------------------------------------
    // request_body_sha256 — Plaid-specific tamper/replay protection
    // ------------------------------------------------------------

    public function test_rejects_when_request_body_sha256_does_not_match_the_actual_raw_body(): void
    {
        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        // The hash claims to match a DIFFERENT body than what was
        // actually received — the classic tamper/replay scenario this
        // claim exists to catch.
        $jwt = $this->signJwt($this->validClaims($rawBody, ['request_body_sha256' => hash('sha256', 'a-completely-different-body')]), $this->privateKeyPem);
        $this->fakeJwkEndpoint($this->jwk);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $jwt]);

        $this->assertFalse($result);
    }

    public function test_rejects_a_jwt_missing_the_request_body_sha256_claim_entirely(): void
    {
        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        $claims = $this->validClaims($rawBody);
        unset($claims['request_body_sha256']);
        $jwt = $this->signJwt($claims, $this->privateKeyPem);
        $this->fakeJwkEndpoint($this->jwk);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $jwt]);

        $this->assertFalse($result);
    }

    /**
     * Documented gotcha (doc-research §12): the hash is sensitive to
     * whitespace in the webhook body — hashing a re-serialized/re-encoded
     * form of the SAME logical JSON (different whitespace) must NOT
     * match a hash computed over the original raw bytes.
     */
    public function test_rejects_when_the_body_was_re_serialized_with_different_whitespace_before_hashing(): void
    {
        [, , $itemId] = $this->routedConnection();
        $rawBodyAsReceived = "{\n  \"item_id\": \"{$itemId}\"\n}";
        $reSerializedForHashing = json_encode(['item_id' => $itemId]); // different whitespace, same logical content
        $jwt = $this->signJwt($this->validClaims($reSerializedForHashing), $this->privateKeyPem);
        $this->fakeJwkEndpoint($this->jwk);

        $result = $this->provider()->verifyInboundSignature($rawBodyAsReceived, ['Plaid-Verification' => $jwt]);

        $this->assertFalse($result, 'request_body_sha256 must be checked against the RAW bytes actually received, never a re-serialized form.');
    }

    // ------------------------------------------------------------
    // The positive path
    // ------------------------------------------------------------

    /**
     * PRODUCTION DEFECT, FOUND BY THIS TEST AND FIXED (Checkpoint 4
     * implementer pass). At the time this test was written, every
     * individual security check in `PlaidProvider::verifyInboundSignature()`
     * was correctly implemented (every negative-path test above passed
     * against the shipped code) — but this positive-path test, exercising
     * the ENTIRE chain with every check satisfied, failed against the
     * implementation as originally shipped.
     *
     * Root cause (as originally shipped): the JWK-fetch call routed
     * through `ProviderRequestExecutor::send()`, which internally writes
     * a usage-record row into `integration_usage_records` — a table
     * under standard, direct-tenant FORCE ROW LEVEL SECURITY (`WITH
     * CHECK (firm_id = NULLIF(current_setting('app.current_firm_id',
     * true), '')::bigint)`, confirmed directly from that table's own
     * migration). `verifyInboundSignature()` is called by
     * `InboundWebhookController` with NO ambient tenant context
     * established at all (confirmed directly from that controller's own
     * source — STEP 7 has no surrounding `runWithFirmContext()`), and
     * `PlaidProvider::resolveAttributionConnection()` only wrapped the
     * FirmIntegration LOOKUP itself in `runWithFirmContext()` — that
     * context was already closed again by the time the JWK-fetch's own
     * `ProviderRequestExecutor::send()` call ran a moment later. That
     * call's internal usage-record INSERT therefore hit the RLS policy
     * above with `app.current_firm_id` unset, was rejected by Postgres,
     * and the resulting `Illuminate\Database\QueryException` was caught
     * by a blanket `catch (Throwable) { return null; }` — silently
     * converting a GENUINELY VALID, correctly-signed webhook into a
     * rejected one. As originally shipped, this would have meant NO
     * Plaid webhook could ever verify successfully in production (every
     * uncached JWK-fetch call fell down this same path).
     *
     * FIX: `PlaidProvider::resolveAttributionConnection()` and
     * `resolveVerificationKey()` were merged into a single
     * `resolveVerificationKeyWithAttribution()`, wrapping the connection
     * lookup AND the JWK network fetch in one shared `runWithFirmContext()`
     * scope — see that method's own docblock. This test now passes
     * against the fixed code, asserting the CORRECT, intended behavior (a
     * fully valid signature must verify as true).
     */
    public function test_accepts_a_correctly_signed_jwt_with_every_claim_valid(): void
    {
        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        $jwt = $this->signJwt($this->validClaims($rawBody), $this->privateKeyPem);
        $this->fakeJwkEndpoint($this->jwk);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $jwt]);

        $this->assertTrue(
            $result,
            'A fully valid, correctly-signed Plaid-Verification JWT (correct alg, correct kid, correct signature, '.
            'fresh iat, matching request_body_sha256) must verify as true.'
        );
    }

    /**
     * A cached kid (Cache::forever() already populated by an earlier
     * successful fetch) never needs to reach ProviderRequestExecutor::send()
     * again — proving the cache-hit path is fully independent of the
     * tenant-context issue flagged above, and exercising
     * resolveVerificationKey()'s own "read cache first" branch directly.
     */
    public function test_a_cached_jwk_is_used_without_a_second_network_call(): void
    {
        Cache::forever('plaid_webhook_jwk:'.self::KID, $this->jwk);

        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        $jwt = $this->signJwt($this->validClaims($rawBody), $this->privateKeyPem);
        Http::fake(); // any real call would now throw StrayRequestException

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $jwt]);

        $this->assertTrue($result, 'A cached JWK must be used without ever reaching the network — this also proves the positive path is reachable end to end once the JWK is already cached.');
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------
    // Never logs the raw JWT
    // ------------------------------------------------------------

    // ------------------------------------------------------------
    // Leading-zero-byte EC coordinate — deterministic regression proof
    // ------------------------------------------------------------

    /**
     * A fixed, known EC P-256 private key (found by brute-force search,
     * offline, over freshly generated keypairs) chosen specifically
     * because its `x` coordinate's most significant byte is 0x00 —
     * openssl_pkey_get_details() therefore returns a 31-byte (not
     * 32-byte) `x` for this key on every single run, deterministically
     * reproducing the exact defect jwkFromPrivateKeyPem() now fixes,
     * rather than depending on the ~0.77%-per-keypair chance a freshly
     * generated key would only hit intermittently.
     */
    private const SHORT_X_COORDINATE_PRIVATE_KEY_PEM = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQg2+sarsD8raP1CXzT
    i+KCf2FeYkQoco+r4kZvllL9jcahRANCAAQAFKCosKO26LcUFLgU9/W6DrZMUpuC
    i14/4LmnI2OQ6t07mAJmgqKsE2Ms9V8CRikITB5aMwUVtJK58eD7mRlo
    -----END PRIVATE KEY-----
    PEM;

    public function test_a_keypair_whose_x_coordinate_has_a_leading_zero_byte_still_verifies(): void
    {
        $rawUnpaddedX = openssl_pkey_get_details(openssl_pkey_get_private(self::SHORT_X_COORDINATE_PRIVATE_KEY_PEM))['ec']['x'];
        $this->assertSame(
            31,
            strlen($rawUnpaddedX),
            'Fixture key must genuinely have a short (unpadded-by-OpenSSL) x coordinate, or this test proves nothing.'
        );

        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        $jwk = $this->jwkFromPrivateKeyPem(self::SHORT_X_COORDINATE_PRIVATE_KEY_PEM, self::KID);
        $jwt = $this->signJwt($this->validClaims($rawBody), self::SHORT_X_COORDINATE_PRIVATE_KEY_PEM);
        $this->fakeJwkEndpoint($jwk);

        $result = $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $jwt]);

        $this->assertTrue(
            $result,
            "A genuinely valid ES256 signature must verify even when its public key's x coordinate has a ".
            'leading zero byte — the JWK\'s x/y must always be the fixed 32-byte SEC1/JWK encoding, never '.
            "OpenSSL's shorter minimal-length form."
        );
    }

    public function test_verification_never_logs_the_raw_plaid_verification_header(): void
    {
        Log::spy();
        [, , $itemId] = $this->routedConnection();
        $rawBody = json_encode(['item_id' => $itemId]);
        $jwt = $this->signJwt($this->validClaims($rawBody), $this->privateKeyPem);
        $this->fakeJwkEndpoint($this->jwk);

        $this->provider()->verifyInboundSignature($rawBody, ['Plaid-Verification' => $jwt]);

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }
}
