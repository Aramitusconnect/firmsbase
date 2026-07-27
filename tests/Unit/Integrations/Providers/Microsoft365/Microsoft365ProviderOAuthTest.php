<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Providers\Microsoft365;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\Microsoft365\Microsoft365Provider;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Microsoft365ProviderOAuthTest — Checkpoint 2 (FirmsVault Live
 * Integrations) test-writing pass. Proves Microsoft365Provider's OAuth
 * surface against the frozen, security-reviewed design
 * (checkpoint2-combined-design.md §3) and the three required security
 * corrections (checkpoint2-security-review.md Findings 2, 4, 10) —
 * specifically Finding 2 (ID-token `aud`/`iss` claim validation) and
 * Finding 10 (tenant-hint format validation), both directly owned by
 * this class.
 *
 * Every network-shaped call goes through Http::fake([...]) — mandatory
 * given tests/TestCase.php's suite-wide Http::preventStrayRequests()
 * guard. No real Microsoft credentials/endpoints are ever used; the
 * identity/graph base URLs and OAuth client id/secret are all
 * config()-overridden test fixtures.
 */
final class Microsoft365ProviderOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'unit-test-ms-client-id-0001';

    private const CLIENT_SECRET = 'unit-test-ms-client-secret-0001';

    private const IDENTITY_BASE = 'https://login.microsoftonline.test';

    private const GRAPH_BASE = 'https://graph.microsoft.test';

    // ------------------------------------------------------------
    // authorizationUrl()
    // ------------------------------------------------------------

    public function test_authorization_url_builds_the_organizations_endpoint_when_no_tenant_hint_is_supplied(): void
    {
        $this->configureEnvironment();

        $url = $this->provider()->authorizationUrl([
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'state' => 'raw-state-value',
            'code_challenge' => 'challenge-value',
        ]);

        $this->assertStringStartsWith(self::IDENTITY_BASE.'/organizations/oauth2/v2.0/authorize?', $url);
        $this->assertStringContainsString('client_id='.self::CLIENT_ID, $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
    }

    public function test_authorization_url_builds_a_tenant_specific_endpoint_for_a_valid_domain_hint(): void
    {
        $this->configureEnvironment();

        $url = $this->provider()->authorizationUrl([
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'state' => 'raw-state-value',
            'code_challenge' => 'challenge-value',
            'ms_tenant_hint' => 'contoso.onmicrosoft.com',
        ]);

        $this->assertStringStartsWith(self::IDENTITY_BASE.'/contoso.onmicrosoft.com/oauth2/v2.0/authorize?', $url);
    }

    public function test_authorization_url_builds_a_tenant_specific_endpoint_for_a_valid_guid_hint(): void
    {
        $this->configureEnvironment();
        $guid = '3b241101-e2bb-4255-8caf-4136c566a962';

        $url = $this->provider()->authorizationUrl([
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'state' => 'raw-state-value',
            'code_challenge' => 'challenge-value',
            'ms_tenant_hint' => $guid,
        ]);

        $this->assertStringStartsWith(self::IDENTITY_BASE."/{$guid}/oauth2/v2.0/authorize?", $url);
    }

    /**
     * Security review Finding 10 (P2, implemented beyond what was
     * strictly required per the diff review): a PRESENT but malformed
     * tenant hint must be REJECTED outright, never silently substituted
     * with 'organizations'.
     */
    public function test_authorization_url_rejects_a_malformed_tenant_hint_rather_than_silently_falling_back(): void
    {
        $this->configureEnvironment();

        $this->expectException(InvalidArgumentException::class);

        $this->provider()->authorizationUrl([
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'state' => 'raw-state-value',
            'code_challenge' => 'challenge-value',
            'ms_tenant_hint' => 'not a valid tenant hint!! ###',
        ]);
    }

    public function test_authorization_url_ignores_a_blank_tenant_hint_and_falls_back_to_organizations(): void
    {
        $this->configureEnvironment();

        $url = $this->provider()->authorizationUrl([
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'state' => 'raw-state-value',
            'code_challenge' => 'challenge-value',
            'ms_tenant_hint' => '   ',
        ]);

        $this->assertStringStartsWith(self::IDENTITY_BASE.'/organizations/oauth2/v2.0/authorize?', $url);
    }

    /**
     * checkpoint2-security-review.md Finding 7 / checkpoint2-combined-design.md
     * §2 P-6f: the provider must self-supply its own real OAuth
     * client_id from platform config — NEVER trust $params['client_id'],
     * even if a caller supplies one.
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
            'iss' => 'https://login.microsoftonline.com/tenant-xyz/v2.0',
            'tid' => 'tenant-xyz',
            'oid' => 'user-object-id-abc',
        ]);

        Http::fake([
            self::IDENTITY_BASE.'/organizations/oauth2/v2.0/token' => Http::response([
                'access_token' => 'fake-access-token',
                'refresh_token' => 'fake-refresh-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'Mail.Read Mail.Send offline_access openid profile',
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
        $this->assertSame('Mail.Read Mail.Send offline_access openid profile', $tokenSet['scope']);
        $this->assertSame('user-object-id-abc', $tokenSet['external_account_id'], 'external_account_id must be mapped from the id_token\'s oid claim.');
        $this->assertSame('tenant-xyz', $tokenSet['tenant_id'], 'tenant_id must be mapped from the id_token\'s tid claim.');

        Http::assertSent(function (HttpClientRequest $request): bool {
            $contentType = $request->header('Content-Type')[0] ?? '';

            return str_contains($contentType, 'application/x-www-form-urlencoded')
                && str_contains($request->body(), 'grant_type=authorization_code')
                && ! str_starts_with(trim($request->body()), '{');
        });
    }

    /**
     * checkpoint2-security-review.md Finding 2 (P1, required):
     * exchangeCodeForToken() must REJECT an id_token whose `aud` claim
     * does not match the platform's configured client_id — never trust
     * `tid`/`oid` from a token that fails this check.
     */
    public function test_exchange_code_for_token_rejects_an_id_token_with_a_mismatched_audience_claim(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        $idToken = $this->fakeIdToken([
            'aud' => 'a-completely-different-client-id',
            'iss' => 'https://login.microsoftonline.com/tenant-xyz/v2.0',
            'tid' => 'tenant-xyz',
            'oid' => 'user-object-id-abc',
        ]);

        Http::fake([
            self::IDENTITY_BASE.'/organizations/oauth2/v2.0/token' => Http::response([
                'access_token' => 'fake-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'Mail.Read',
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

    /**
     * checkpoint2-security-review.md Finding 2 (P1, required):
     * exchangeCodeForToken() must REJECT an id_token whose `iss` claim
     * does not match Microsoft's expected issuer template
     * (https://login.microsoftonline.com/{tid}/v2.0) for the token's OWN
     * tid — again never a soft pass.
     */
    public function test_exchange_code_for_token_rejects_an_id_token_with_a_mismatched_issuer_claim(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        $idToken = $this->fakeIdToken([
            'aud' => self::CLIENT_ID,
            'iss' => 'https://attacker-issuer.invalid/tenant-xyz/v2.0',
            'tid' => 'tenant-xyz',
            'oid' => 'user-object-id-abc',
        ]);

        Http::fake([
            self::IDENTITY_BASE.'/organizations/oauth2/v2.0/token' => Http::response([
                'access_token' => 'fake-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'Mail.Read',
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
            self::IDENTITY_BASE.'/organizations/oauth2/v2.0/token' => Http::response([
                'access_token' => 'fake-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'Mail.Read',
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

    // ------------------------------------------------------------
    // refreshToken()
    // ------------------------------------------------------------

    public function test_refresh_token_sends_a_form_encoded_request_with_the_refresh_token_grant_type(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        Http::fake([
            self::IDENTITY_BASE.'/organizations/oauth2/v2.0/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'Mail.Read Mail.Send offline_access openid profile',
            ], 200),
        ]);

        $tokenSet = $this->runWithFirmContext($firm, fn () => $this->provider()->refreshToken('old-refresh-token-plaintext', [
            'connection' => $connection,
            'requested_capabilities' => [ResourceType::Message->value],
        ]));

        $this->assertSame('new-access-token', $tokenSet['access_token']);
        $this->assertSame('new-refresh-token', $tokenSet['refresh_token']);
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

    public function test_refresh_token_throws_when_requested_capabilities_cannot_be_resolved_from_context_or_connection(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();

        // Connection has no requested_capabilities_json set (null), and
        // the caller passes none in $context either — requiredScopes()
        // must throw rather than request a broad, unscoped default.
        $this->expectException(InvalidArgumentException::class);

        $this->runWithFirmContext($firm, fn () => $this->provider()->refreshToken('old-refresh-token-plaintext', [
            'connection' => $connection,
        ]));
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
            'requested_capabilities' => [ResourceType::Message->value, ResourceType::Contact->value],
        ]);

        $this->assertContains('Mail.Read', $scopes);
        $this->assertContains('Mail.Send', $scopes);
        $this->assertContains('Contacts.Read', $scopes);
        $this->assertNotContains('Calendars.ReadWrite', $scopes, 'A capability that was not requested must not contribute its scopes.');
        $this->assertNotContains('Files.ReadWrite', $scopes, 'A capability that was not requested must not contribute its scopes.');
    }

    public function test_required_scopes_always_includes_the_offline_access_openid_profile_baseline(): void
    {
        $scopes = $this->provider()->requiredScopes(['requested_capabilities' => [ResourceType::Contact->value]]);

        foreach (['offline_access', 'openid', 'profile'] as $baseline) {
            $this->assertContains($baseline, $scopes, "Baseline scope \"{$baseline}\" must always be present.");
        }
    }

    /**
     * Calendars.ReadWrite/Files.ReadWrite already imply their narrower
     * *.Read counterpart — requiredScopes() must never return both the
     * superset and the subset scope for the same resource at once.
     */
    public function test_required_scopes_dedups_calendar_and_files_superset_read_write_pairs(): void
    {
        $scopes = $this->provider()->requiredScopes([
            'requested_capabilities' => [ResourceType::CalendarEvent->value, ResourceType::Document->value],
        ]);

        $this->assertContains('Calendars.ReadWrite', $scopes);
        $this->assertNotContains('Calendars.Read', $scopes, 'Calendars.ReadWrite already implies Calendars.Read — the narrower scope must never also be present.');
        $this->assertContains('Files.ReadWrite', $scopes);
        $this->assertNotContains('Files.Read', $scopes, 'Files.ReadWrite already implies Files.Read — the narrower scope must never also be present.');
    }

    public function test_required_scopes_ignores_a_non_string_capability_entry_rather_than_throwing(): void
    {
        $scopes = $this->provider()->requiredScopes([
            'requested_capabilities' => [ResourceType::Contact->value, 12345, null],
        ]);

        $this->assertContains('Contacts.Read', $scopes);
    }

    // ------------------------------------------------------------
    // capabilityScopeMap()
    // ------------------------------------------------------------

    public function test_capability_scope_map_returns_the_correct_per_capability_scope_arrays(): void
    {
        $map = $this->provider()->capabilityScopeMap();

        $this->assertSame([
            ResourceType::Contact->value => ['Contacts.Read'],
            ResourceType::CalendarEvent->value => ['Calendars.ReadWrite'],
            ResourceType::Message->value => ['Mail.Read', 'Mail.Send'],
            ResourceType::Document->value => ['Files.ReadWrite'],
        ], $map);
    }

    public function test_capability_scope_map_excludes_the_baseline_scopes(): void
    {
        $map = $this->provider()->capabilityScopeMap();

        foreach ($map as $scopes) {
            $this->assertNotContains('offline_access', $scopes);
            $this->assertNotContains('openid', $scopes);
            $this->assertNotContains('profile', $scopes);
        }
    }

    // ------------------------------------------------------------
    // isConfigured()
    // ------------------------------------------------------------

    public function test_is_configured_is_false_when_client_id_and_secret_are_both_missing(): void
    {
        config([
            'integrations.oauth_apps.microsoft365.client_id' => null,
            'integrations.oauth_apps.microsoft365.client_secret' => null,
        ]);

        $this->assertFalse($this->provider()->isConfigured());
    }

    public function test_is_configured_is_false_when_only_the_client_id_is_present(): void
    {
        config([
            'integrations.oauth_apps.microsoft365.client_id' => self::CLIENT_ID,
            'integrations.oauth_apps.microsoft365.client_secret' => null,
        ]);

        $this->assertFalse($this->provider()->isConfigured());
    }

    public function test_is_configured_is_false_when_only_the_client_secret_is_present(): void
    {
        config([
            'integrations.oauth_apps.microsoft365.client_id' => '',
            'integrations.oauth_apps.microsoft365.client_secret' => self::CLIENT_SECRET,
        ]);

        $this->assertFalse($this->provider()->isConfigured());
    }

    public function test_is_configured_is_true_when_both_client_id_and_secret_are_present(): void
    {
        $this->configureEnvironment();

        $this->assertTrue($this->provider()->isConfigured());
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function provider(): Microsoft365Provider
    {
        return app(Microsoft365Provider::class);
    }

    /**
     * Never real Microsoft credentials/endpoints — everything here is a
     * synthetic, config()-overridden test fixture.
     */
    private function configureEnvironment(): void
    {
        config([
            'integrations.oauth_apps.microsoft365.client_id' => self::CLIENT_ID,
            'integrations.oauth_apps.microsoft365.client_secret' => self::CLIENT_SECRET,
            'integrations.provider_environments.'.ProviderKey::Microsoft365->value => [
                'mode' => 'sandbox',
                'sandbox_base_urls' => [
                    'identity' => self::IDENTITY_BASE,
                    'graph' => self::GRAPH_BASE,
                ],
                'live_base_urls' => [
                    'identity' => self::IDENTITY_BASE,
                    'graph' => self::GRAPH_BASE,
                ],
            ],
        ]);
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function makeConnection(): array
    {
        $firm = Firm::factory()->create();
        $provider = IntegrationProvider::query()->where('code', ProviderKey::Microsoft365->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::Microsoft365->value]);

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
     * signature (per the provider's own docblock — no JWT/JWK library
     * exists in this codebase, a disclosed, deliberate non-requirement).
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
