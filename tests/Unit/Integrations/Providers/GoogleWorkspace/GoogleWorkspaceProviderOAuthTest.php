<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Providers\GoogleWorkspace;

use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\GoogleWorkspace\GoogleWorkspaceProvider;
use App\Integrations\Services\IntegrationCredentialService;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use Google\Auth\AccessToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * GoogleWorkspaceProviderOAuthTest — FirmsVault Live Integrations,
 * Checkpoint 3 (test-writer pass). Proves GoogleWorkspaceProvider's
 * OAuth/disconnect surface against the frozen design
 * (checkpoint3-combined-design.md §4.2/§4.3) and the actual, just-written
 * production code, mirroring Microsoft365ProviderOAuthTest.php's
 * structure/rigor exactly wherever the mechanics are analogous, and
 * covering the genuinely Google-specific divergences (mandatory
 * prompt=consent, PKCE-always-sent, dual-form iss claim, exp check on
 * the ID token, no refresh-time scope re-assertion, real self-service
 * revoke endpoint) explicitly.
 *
 * Every network-shaped call goes through Http::fake([...]) — mandatory
 * given tests/TestCase.php's suite-wide Http::preventStrayRequests()
 * guard. No real Google credentials/endpoints are ever used; the token
 * base URL and OAuth client id/secret are all config()-overridden test
 * fixtures. `Google\Auth\AccessToken` is bound as a container singleton
 * but is never exercised by any test in this file (no Gmail webhook
 * verification path is touched here) — GoogleWorkspaceProviderWebhookTest.php
 * and the dedicated OIDC verification test file own that surface.
 */
final class GoogleWorkspaceProviderOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'unit-test-google-client-id-0001';

    private const CLIENT_SECRET = 'unit-test-google-client-secret-0001';

    private const TOKEN_BASE = 'https://oauth2.googleapis.test';

    private const GMAIL_BASE = 'https://gmail.googleapis.test';

    private const CALENDAR_BASE = 'https://www.googleapis-calendar.test';

    private const DRIVE_BASE = 'https://www.googleapis-drive.test';

    // ------------------------------------------------------------
    // authorizationUrl()
    // ------------------------------------------------------------

    public function test_authorization_url_builds_against_googles_real_authorize_endpoint(): void
    {
        $this->configureEnvironment();

        $url = $this->provider()->authorizationUrl([
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'state' => 'raw-state-value',
            'code_challenge' => 'challenge-value',
        ]);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertStringContainsString('client_id='.self::CLIENT_ID, $url);
        $this->assertStringContainsString('response_type=code', $url);
    }

    /**
     * checkpoint3-design-oauth-capabilities.md §3.2 — access_type=offline
     * is REQUIRED to receive a refresh token at all.
     */
    public function test_authorization_url_always_includes_access_type_offline(): void
    {
        $this->configureEnvironment();

        $url = $this->provider()->authorizationUrl([
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'state' => 'raw-state-value',
            'code_challenge' => 'challenge-value',
        ]);

        $this->assertStringContainsString('access_type=offline', $url);
    }

    /**
     * checkpoint3-design-oauth-capabilities.md §3.2 — the load-bearing
     * detail: prompt=consent is sent UNCONDITIONALLY on every connect,
     * never only on first-connect. Without it, a returning user who
     * already granted consent receives no refresh_token on a second
     * authorization, and finishCallback()'s "absence of refresh_token is
     * not an error" handling means that failure mode is otherwise
     * silent.
     */
    public function test_authorization_url_always_includes_prompt_consent_even_without_a_reconnect_hint(): void
    {
        $this->configureEnvironment();

        $url = $this->provider()->authorizationUrl([
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'state' => 'raw-state-value',
            'code_challenge' => 'challenge-value',
        ]);

        $this->assertStringContainsString('prompt=consent', $url);
    }

    public function test_authorization_url_includes_include_granted_scopes(): void
    {
        $this->configureEnvironment();

        $url = $this->provider()->authorizationUrl([
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'state' => 'raw-state-value',
            'code_challenge' => 'challenge-value',
        ]);

        $this->assertStringContainsString('include_granted_scopes=true', $url);
    }

    public function test_authorization_url_sends_pkce_code_challenge_parameters_when_supplied(): void
    {
        $this->configureEnvironment();

        $url = $this->provider()->authorizationUrl([
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'state' => 'raw-state-value',
            'code_challenge' => 'the-challenge-value',
            'code_challenge_method' => 'S256',
        ]);

        $this->assertStringContainsString('code_challenge=the-challenge-value', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
    }

    /**
     * checkpoint3-design-oauth-capabilities.md §3.1 — hd is sent ONLY as
     * a UI account-chooser optimization hint, never trusted for
     * anything security-relevant, and only present when the caller
     * supplies a domain hint at all.
     */
    public function test_authorization_url_includes_the_domain_hint_only_when_supplied(): void
    {
        $this->configureEnvironment();

        $withHint = $this->provider()->authorizationUrl([
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'state' => 'raw-state-value',
            'code_challenge' => 'challenge-value',
            'google_domain_hint' => 'contoso.com',
        ]);

        $withoutHint = $this->provider()->authorizationUrl([
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'state' => 'raw-state-value',
            'code_challenge' => 'challenge-value',
        ]);

        $this->assertStringContainsString('hd=contoso.com', $withHint);
        $this->assertStringNotContainsString('hd=', $withoutHint);
    }

    /**
     * checkpoint3-design-oauth-capabilities.md §3.2 — the provider must
     * self-supply its own real OAuth client_id from platform config,
     * NEVER trust $params['client_id'], even if a caller supplies one
     * (identical discipline to Microsoft365Provider's own).
     */
    public function test_authorization_url_never_uses_a_caller_supplied_client_id(): void
    {
        $this->configureEnvironment();

        $url = $this->provider()->authorizationUrl([
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'state' => 'raw-state-value',
            'code_challenge' => 'challenge-value',
            'client_id' => 'attacker-supplied-client-id',
        ]);

        $this->assertStringContainsString('client_id='.self::CLIENT_ID, $url);
        $this->assertStringNotContainsString('attacker-supplied-client-id', $url);
    }

    // ------------------------------------------------------------
    // exchangeCodeForToken()
    // ------------------------------------------------------------

    public function test_exchange_code_for_token_sends_a_form_encoded_request_and_returns_the_correctly_mapped_token_set(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        $idToken = $this->fakeIdToken([
            'aud' => self::CLIENT_ID,
            'iss' => 'https://accounts.google.com',
            'hd' => 'contoso.com',
            'sub' => 'google-subject-id-abc',
            'exp' => now()->addHour()->getTimestamp(),
        ]);

        Http::fake([
            self::TOKEN_BASE.'/token' => Http::response([
                'access_token' => 'fake-access-token',
                'refresh_token' => 'fake-refresh-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'openid email https://www.googleapis.com/auth/gmail.readonly',
                'id_token' => $idToken,
            ], 200),
        ]);

        $tokenSet = $this->runWithFirmContext($firm, fn () => $this->provider()->exchangeCodeForToken('auth-code-123', [
            'connection' => $connection,
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'code_verifier' => 'verifier-value',
        ]));

        $this->assertSame('fake-access-token', $tokenSet['access_token']);
        $this->assertSame('fake-refresh-token', $tokenSet['refresh_token']);
        $this->assertSame('Bearer', $tokenSet['token_type']);
        $this->assertSame(3600, $tokenSet['expires_in']);
        $this->assertSame('google-subject-id-abc', $tokenSet['external_account_id'], 'external_account_id must be mapped from the id_token\'s sub claim.');
        $this->assertSame('contoso.com', $tokenSet['tenant_id'], 'tenant_id must be mapped from the id_token\'s hd claim.');

        Http::assertSent(function (HttpClientRequest $request): bool {
            $contentType = $request->header('Content-Type')[0] ?? '';

            return str_contains($contentType, 'application/x-www-form-urlencoded')
                && str_contains($request->body(), 'grant_type=authorization_code')
                && ! str_starts_with(trim($request->body()), '{');
        });
    }

    /**
     * checkpoint3-design-oauth-capabilities.md §3.1 — a personal
     *
     * @gmail.com account legitimately has NO hd claim at all; null is
     * expected, not an error.
     */
    public function test_exchange_code_for_token_tolerates_a_missing_hd_claim_for_a_personal_account(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        $idToken = $this->fakeIdToken([
            'aud' => self::CLIENT_ID,
            'iss' => 'https://accounts.google.com',
            'sub' => 'google-subject-id-personal',
            'exp' => now()->addHour()->getTimestamp(),
            // no hd claim at all
        ]);

        Http::fake([
            self::TOKEN_BASE.'/token' => Http::response([
                'access_token' => 'fake-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'openid email',
                'id_token' => $idToken,
            ], 200),
        ]);

        $tokenSet = $this->runWithFirmContext($firm, fn () => $this->provider()->exchangeCodeForToken('auth-code-123', [
            'connection' => $connection,
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'code_verifier' => 'verifier-value',
        ]));

        $this->assertNull($tokenSet['tenant_id']);
        $this->assertSame('google-subject-id-personal', $tokenSet['external_account_id']);
    }

    /**
     * checkpoint3-design-oauth-capabilities.md §4 — the bare
     * "accounts.google.com" issuer form (without the https:// scheme) is
     * ALSO explicitly documented as valid by Google, a fixed two-value
     * check.
     */
    public function test_exchange_code_for_token_accepts_the_bare_issuer_form_without_a_scheme(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        $idToken = $this->fakeIdToken([
            'aud' => self::CLIENT_ID,
            'iss' => 'accounts.google.com',
            'sub' => 'google-subject-id-bare-iss',
            'exp' => now()->addHour()->getTimestamp(),
        ]);

        Http::fake([
            self::TOKEN_BASE.'/token' => Http::response([
                'access_token' => 'fake-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'openid email',
                'id_token' => $idToken,
            ], 200),
        ]);

        $tokenSet = $this->runWithFirmContext($firm, fn () => $this->provider()->exchangeCodeForToken('auth-code-123', [
            'connection' => $connection,
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'code_verifier' => 'verifier-value',
        ]));

        $this->assertSame('google-subject-id-bare-iss', $tokenSet['external_account_id']);
    }

    public function test_exchange_code_for_token_rejects_an_id_token_with_a_mismatched_audience_claim(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        $idToken = $this->fakeIdToken([
            'aud' => 'a-completely-different-client-id',
            'iss' => 'https://accounts.google.com',
            'sub' => 'google-subject-id-abc',
            'exp' => now()->addHour()->getTimestamp(),
        ]);

        Http::fake([
            self::TOKEN_BASE.'/token' => Http::response([
                'access_token' => 'fake-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'openid email',
                'id_token' => $idToken,
            ], 200),
        ]);

        try {
            $this->runWithFirmContext($firm, fn () => $this->provider()->exchangeCodeForToken('auth-code-123', [
                'connection' => $connection,
                'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
                'code_verifier' => 'verifier-value',
            ]));
            $this->fail('Expected a SanitizedProviderHttpException for a mismatched aud claim — a wrong audience must never be soft-passed.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED, $e->category());
        }
    }

    public function test_exchange_code_for_token_rejects_an_id_token_with_a_mismatched_issuer_claim(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        $idToken = $this->fakeIdToken([
            'aud' => self::CLIENT_ID,
            'iss' => 'https://attacker-issuer.invalid',
            'sub' => 'google-subject-id-abc',
            'exp' => now()->addHour()->getTimestamp(),
        ]);

        Http::fake([
            self::TOKEN_BASE.'/token' => Http::response([
                'access_token' => 'fake-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'openid email',
                'id_token' => $idToken,
            ], 200),
        ]);

        try {
            $this->runWithFirmContext($firm, fn () => $this->provider()->exchangeCodeForToken('auth-code-123', [
                'connection' => $connection,
                'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
                'code_verifier' => 'verifier-value',
            ]));
            $this->fail('Expected a SanitizedProviderHttpException for a mismatched iss claim — a wrong issuer must never be soft-passed.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED, $e->category());
        }
    }

    public function test_exchange_code_for_token_rejects_a_missing_id_token(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        Http::fake([
            self::TOKEN_BASE.'/token' => Http::response([
                'access_token' => 'fake-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'openid email',
                // no id_token key at all
            ], 200),
        ]);

        try {
            $this->runWithFirmContext($firm, fn () => $this->provider()->exchangeCodeForToken('auth-code-123', [
                'connection' => $connection,
                'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
                'code_verifier' => 'verifier-value',
            ]));
            $this->fail('Expected a SanitizedProviderHttpException for a response with no id_token at all.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED, $e->category());
        }
    }

    /**
     * Google-specific: GoogleWorkspaceProvider::decodeAndValidateIdToken()
     * additionally checks `exp` (Microsoft 365's design did not need an
     * explicit exp check since its token is consumed synchronously — this
     * one costs nothing and guards against a clock-skew/caching bug).
     */
    public function test_exchange_code_for_token_rejects_an_expired_id_token(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        $idToken = $this->fakeIdToken([
            'aud' => self::CLIENT_ID,
            'iss' => 'https://accounts.google.com',
            'sub' => 'google-subject-id-abc',
            'exp' => now()->subHour()->getTimestamp(),
        ]);

        Http::fake([
            self::TOKEN_BASE.'/token' => Http::response([
                'access_token' => 'fake-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'openid email',
                'id_token' => $idToken,
            ], 200),
        ]);

        try {
            $this->runWithFirmContext($firm, fn () => $this->provider()->exchangeCodeForToken('auth-code-123', [
                'connection' => $connection,
                'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
                'code_verifier' => 'verifier-value',
            ]));
            $this->fail('Expected a SanitizedProviderHttpException for an expired id_token.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED, $e->category());
        }
    }

    // ------------------------------------------------------------
    // refreshToken()
    // ------------------------------------------------------------

    public function test_refresh_token_sends_a_form_encoded_request_with_the_refresh_token_grant_type(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        Http::fake([
            self::TOKEN_BASE.'/token' => Http::response([
                'access_token' => 'new-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'openid email https://www.googleapis.com/auth/gmail.readonly',
                // Google's refresh grant response typically has NO
                // refresh_token field at all — see the next test.
            ], 200),
        ]);

        $tokenSet = $this->runWithFirmContext($firm, fn () => $this->provider()->refreshToken('old-refresh-token-plaintext', [
            'connection' => $connection,
        ]));

        $this->assertSame('new-access-token', $tokenSet['access_token']);
        $this->assertSame('Bearer', $tokenSet['token_type']);
        $this->assertSame(3600, $tokenSet['expires_in']);

        Http::assertSent(function (HttpClientRequest $request): bool {
            $contentType = $request->header('Content-Type')[0] ?? '';

            return str_contains($contentType, 'application/x-www-form-urlencoded')
                && str_contains($request->body(), 'grant_type=refresh_token')
                && str_contains($request->body(), 'refresh_token=old-refresh-token-plaintext')
                && ! str_starts_with(trim($request->body()), '{');
        });
    }

    /**
     * checkpoint3-design-oauth-capabilities.md §3.4 — Google's refresh
     * grant response TYPICALLY does not include a new refresh_token at
     * all; ProviderConnectionService::refreshConnectionToken()'s existing
     * isset()-gated conditional-rotate branch already handles this
     * correctly with zero code change, so refreshToken() must simply
     * return null (never throw, never fabricate a value) when the field
     * is absent.
     */
    public function test_refresh_token_returns_a_null_refresh_token_when_google_omits_it_from_the_response(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        Http::fake([
            self::TOKEN_BASE.'/token' => Http::response([
                'access_token' => 'new-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'openid email',
            ], 200),
        ]);

        $tokenSet = $this->runWithFirmContext($firm, fn () => $this->provider()->refreshToken('old-refresh-token-plaintext', [
            'connection' => $connection,
        ]));

        $this->assertNull($tokenSet['refresh_token']);
    }

    /**
     * checkpoint3-design-oauth-capabilities.md §3.4 — unlike Microsoft's
     * refresh grant, Google's refresh grant does NOT accept a
     * caller-supplied scope re-assertion; refreshToken() must never send
     * a `scope` body parameter at all, and — unlike Microsoft365Provider
     * — must never require $context['requested_capabilities'] to be
     * resolvable (this class's refreshToken() never calls
     * requiredScopes() internally).
     */
    public function test_refresh_token_never_sends_a_scope_parameter_and_never_requires_requested_capabilities(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        Http::fake([
            self::TOKEN_BASE.'/token' => Http::response([
                'access_token' => 'new-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'openid email',
            ], 200),
        ]);

        // Deliberately NO 'requested_capabilities' key in $context —
        // must not throw, unlike Microsoft365Provider's own refreshToken().
        $this->runWithFirmContext($firm, fn () => $this->provider()->refreshToken('old-refresh-token-plaintext', [
            'connection' => $connection,
        ]));

        Http::assertSent(function (HttpClientRequest $request): bool {
            return ! str_contains($request->body(), 'scope=');
        });
    }

    // ------------------------------------------------------------
    // requiredScopes()
    // ------------------------------------------------------------

    public function test_required_scopes_throws_on_a_missing_requested_capabilities_context(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->provider()->requiredScopes([]);
    }

    public function test_required_scopes_throws_on_an_empty_requested_capabilities_array(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->provider()->requiredScopes(['requested_capabilities' => []]);
    }

    public function test_required_scopes_throws_when_requested_capabilities_is_not_an_array(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->provider()->requiredScopes(['requested_capabilities' => 'not-an-array']);
    }

    public function test_required_scopes_returns_the_correct_bundle_for_a_given_capability_list(): void
    {
        $scopes = $this->provider()->requiredScopes([
            'requested_capabilities' => [ResourceType::Message->value],
        ]);

        $this->assertContains('https://www.googleapis.com/auth/gmail.readonly', $scopes);
        $this->assertContains('https://www.googleapis.com/auth/gmail.send', $scopes);
        $this->assertNotContains('https://www.googleapis.com/auth/calendar.events', $scopes, 'A capability that was not requested must not contribute its scopes.');
        $this->assertNotContains('https://www.googleapis.com/auth/drive.file', $scopes, 'A capability that was not requested must not contribute its scopes.');
    }

    public function test_required_scopes_always_includes_the_openid_email_baseline(): void
    {
        $scopes = $this->provider()->requiredScopes(['requested_capabilities' => [ResourceType::Message->value]]);

        foreach (['openid', 'email'] as $baseline) {
            $this->assertContains($baseline, $scopes, "Baseline scope \"{$baseline}\" must always be present.");
        }
    }

    public function test_required_scopes_ignores_a_non_string_capability_entry_rather_than_throwing(): void
    {
        $scopes = $this->provider()->requiredScopes([
            'requested_capabilities' => [ResourceType::Message->value, 12345, null],
        ]);

        $this->assertContains('https://www.googleapis.com/auth/gmail.readonly', $scopes);
    }

    public function test_required_scopes_dedups_when_the_same_capability_appears_twice(): void
    {
        $scopes = $this->provider()->requiredScopes([
            'requested_capabilities' => [ResourceType::CalendarEvent->value, ResourceType::CalendarEvent->value],
        ]);

        $this->assertSame(1, count(array_keys($scopes, 'https://www.googleapis.com/auth/calendar.events', true)));
    }

    // ------------------------------------------------------------
    // capabilityScopeMap()
    // ------------------------------------------------------------

    /**
     * checkpoint3-combined-design.md §4.2's binding scope-bundle table —
     * asserted verbatim against the real, shipped implementation.
     */
    public function test_capability_scope_map_returns_the_correct_per_capability_scope_arrays(): void
    {
        $map = $this->provider()->capabilityScopeMap();

        $this->assertSame([
            ResourceType::Message->value => [
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.send',
            ],
            ResourceType::CalendarEvent->value => [
                'https://www.googleapis.com/auth/calendar.events',
            ],
            ResourceType::Document->value => [
                'https://www.googleapis.com/auth/drive.file',
            ],
        ], $map);
    }

    public function test_capability_scope_map_excludes_the_baseline_scopes(): void
    {
        $map = $this->provider()->capabilityScopeMap();

        foreach ($map as $scopes) {
            $this->assertNotContains('openid', $scopes);
            $this->assertNotContains('email', $scopes);
        }
    }

    /**
     * checkpoint3-combined-design.md §4.2 — drive.file (non-sensitive,
     * per-file) is used DELIBERATELY over drive.readonly (Restricted,
     * CASA-gated) — a regression guard against silently widening the
     * Document bundle back to the broader, Restricted scope.
     */
    public function test_capability_scope_map_uses_drive_file_never_drive_readonly(): void
    {
        $map = $this->provider()->capabilityScopeMap();

        $this->assertContains('https://www.googleapis.com/auth/drive.file', $map[ResourceType::Document->value]);
        $this->assertNotContains('https://www.googleapis.com/auth/drive.readonly', $map[ResourceType::Document->value]);
    }

    // ------------------------------------------------------------
    // isConfigured()
    // ------------------------------------------------------------

    public function test_is_configured_is_false_when_client_id_and_secret_are_both_missing(): void
    {
        config([
            'integrations.oauth_apps.googleworkspace.client_id' => null,
            'integrations.oauth_apps.googleworkspace.client_secret' => null,
        ]);

        $this->assertFalse($this->provider()->isConfigured());
    }

    public function test_is_configured_is_false_when_only_the_client_id_is_present(): void
    {
        config([
            'integrations.oauth_apps.googleworkspace.client_id' => self::CLIENT_ID,
            'integrations.oauth_apps.googleworkspace.client_secret' => null,
        ]);

        $this->assertFalse($this->provider()->isConfigured());
    }

    public function test_is_configured_is_false_when_only_the_client_secret_is_present(): void
    {
        config([
            'integrations.oauth_apps.googleworkspace.client_id' => '',
            'integrations.oauth_apps.googleworkspace.client_secret' => self::CLIENT_SECRET,
        ]);

        $this->assertFalse($this->provider()->isConfigured());
    }

    public function test_is_configured_is_true_when_both_client_id_and_secret_are_present(): void
    {
        $this->configureEnvironment();

        $this->assertTrue($this->provider()->isConfigured());
    }

    // ------------------------------------------------------------
    // revokeAtProvider() — SupportsDisconnectContract, a genuinely
    // different posture from Microsoft365Provider's deliberate
    // non-implementation (checkpoint3-combined-design.md §4.3).
    // ------------------------------------------------------------

    public function test_revoke_at_provider_returns_false_when_there_is_no_credential_to_revoke(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->revokeAtProvider(['connection' => $connection]));

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    public function test_revoke_at_provider_prefers_the_refresh_token_over_the_access_token_when_both_exist(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection, CredentialType::OauthAccessToken, 'the-access-token-plaintext'));
        $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection, CredentialType::OauthRefreshToken, 'the-refresh-token-plaintext'));

        Http::fake([self::TOKEN_BASE.'/revoke' => Http::response('', 200)]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->revokeAtProvider(['connection' => $connection]));

        $this->assertTrue($result);
        Http::assertSent(function (HttpClientRequest $request): bool {
            $contentType = $request->header('Content-Type')[0] ?? '';

            return str_contains($contentType, 'application/x-www-form-urlencoded')
                && str_contains($request->body(), 'token=the-refresh-token-plaintext')
                && ! str_contains($request->body(), 'the-access-token-plaintext');
        });
    }

    public function test_revoke_at_provider_falls_back_to_the_access_token_when_no_refresh_token_credential_exists(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection, CredentialType::OauthAccessToken, 'the-only-access-token'));

        Http::fake([self::TOKEN_BASE.'/revoke' => Http::response('', 200)]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->revokeAtProvider(['connection' => $connection]));

        $this->assertTrue($result);
        Http::assertSent(fn (HttpClientRequest $request): bool => str_contains($request->body(), 'token=the-only-access-token'));
    }

    public function test_revoke_at_provider_returns_false_when_google_responds_with_a_non_200_status(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection, CredentialType::OauthRefreshToken, 'the-refresh-token'));

        Http::fake([self::TOKEN_BASE.'/revoke' => Http::response(['error' => 'invalid_token'], 400)]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->revokeAtProvider(['connection' => $connection]));

        $this->assertFalse($result);
    }

    public function test_revoke_at_provider_hits_the_revoke_endpoint_never_the_token_exchange_endpoint(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection, CredentialType::OauthRefreshToken, 'the-refresh-token'));

        Http::fake([
            self::TOKEN_BASE.'/revoke' => Http::response('', 200),
            self::TOKEN_BASE.'/token' => Http::response(['error' => 'must never be called by revokeAtProvider()'], 500),
        ]);

        $this->runWithFirmContext($firm, fn () => $this->provider()->revokeAtProvider(['connection' => $connection]));

        Http::assertSent(fn (HttpClientRequest $request): bool => str_ends_with((string) $request->url(), '/revoke'));
        Http::assertNotSent(fn (HttpClientRequest $request): bool => str_ends_with((string) $request->url(), '/token'));
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function provider(): GoogleWorkspaceProvider
    {
        return app(GoogleWorkspaceProvider::class);
    }

    private function credentialService(): IntegrationCredentialService
    {
        return app(IntegrationCredentialService::class);
    }

    /**
     * Never real Google credentials/endpoints — everything here is a
     * synthetic, config()-overridden test fixture. `Google\Auth\AccessToken`
     * is left bound to the real class (never instantiated in this file's
     * scenarios — none of them touch the Gmail webhook verification
     * path) but is still swapped for a harmless double so that, even if
     * a future maintainer's edit accidentally exercised it, this file
     * could never trigger a real outbound cert-fetch call.
     */
    private function configureEnvironment(): void
    {
        config([
            'integrations.oauth_apps.googleworkspace.client_id' => self::CLIENT_ID,
            'integrations.oauth_apps.googleworkspace.client_secret' => self::CLIENT_SECRET,
            'integrations.oauth_apps.googleworkspace.pubsub_push_audience' => 'unit-test-audience',
            'integrations.oauth_apps.googleworkspace.pubsub_push_service_account_email' => 'push@unit-test.iam.gserviceaccount.com',
            'integrations.oauth_apps.googleworkspace.gmail_mailbox_routing_hmac_key' => str_repeat('k', 32),
            'integrations.oauth_apps.googleworkspace.gmail_pubsub_topic_name' => 'projects/unit-test/topics/gmail-push',
            'integrations.provider_environments.'.ProviderKey::GoogleWorkspace->value => [
                'mode' => 'sandbox',
                'sandbox_base_urls' => [
                    'token' => self::TOKEN_BASE,
                    'gmail' => self::GMAIL_BASE,
                    'calendar' => self::CALENDAR_BASE,
                    'drive' => self::DRIVE_BASE,
                ],
                'live_base_urls' => [
                    'token' => self::TOKEN_BASE,
                    'gmail' => self::GMAIL_BASE,
                    'calendar' => self::CALENDAR_BASE,
                    'drive' => self::DRIVE_BASE,
                ],
            ],
        ]);

        app()->instance(AccessToken::class, new class extends AccessToken
        {
            public function verify($token, array $options = [])
            {
                throw new \RuntimeException('AccessToken::verify() must never be reached by GoogleWorkspaceProviderOAuthTest — no scenario in this file exercises Gmail webhook verification.');
            }
        });
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function makeConnection(): array
    {
        $firm = Firm::factory()->create();
        // An active tenant encryption key is required before
        // IntegrationCredentialService::store() can encrypt anything —
        // without this, every test below that stores a credential
        // (the revokeAtProvider() tests) fails with "no active tenant
        // encryption key", unrelated to whatever behavior the test
        // itself is trying to prove.
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $provider = IntegrationProvider::query()->where('code', ProviderKey::GoogleWorkspace->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::GoogleWorkspace->value]);

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($provider)
            ->create(['external_account_id' => null]));

        return [$firm, $connection];
    }

    /**
     * Builds a base64url-encoded, UNSIGNED fake JWT (header.payload.signature)
     * carrying the given claims as its JSON payload — sufficient for
     * exchangeCodeForToken(), which only ever base64url-decodes the
     * payload segment and validates claims; it never verifies a
     * signature (this trust-boundary reasoning is identical to, and
     * explicitly scoped the same way as, Microsoft365Provider's own — the
     * token arrives directly in the back-channel HTTPS response body from
     * Google's own token endpoint, never a front-channel-relayed token).
     *
     * @param  array<string, mixed>  $claims
     */
    private function fakeIdToken(array $claims): string
    {
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $header = $encode(['alg' => 'none', 'typ' => 'JWT']);
        $payload = $encode($claims);
        $signature = rtrim(strtr(base64_encode('unsigned-test-fixture-signature'), '+/', '-_'), '=');

        return "{$header}.{$payload}.{$signature}";
    }
}
