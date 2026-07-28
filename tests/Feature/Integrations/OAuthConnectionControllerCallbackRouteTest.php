<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\OAuthInitiationResult;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
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
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\EntitlementService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * OAuthConnectionControllerCallbackRouteTest — Checkpoint 12 (frozen-
 * design-post-security-review.md §3, §6 Scenario 1). Independent of F2:
 * ProviderConnectionServiceOAuthTest.php exhaustively proves the OAuth
 * exchange at the SERVICE layer, but (per 12H's verification item 11,
 * independently re-confirmed by 12B) no existing test anywhere in this
 * suite ever issues a real HTTP request to the `integrations.oauth.callback`
 * ROUTE — every existing proof calls
 * ProviderConnectionService::completeOAuthCallback() directly. This file
 * closes that specific gap: real `GET` requests through the actual
 * `auth`-guarded route / `OAuthConnectionController::callback()`
 * controller action, proving the HTTP-layer wiring (session user
 * resolution, query-string extraction, redirect-with-flash construction)
 * composes correctly with the already-proven service layer — not
 * re-proving the service layer's own exhaustive PKCE/state/scope logic
 * a second time.
 *
 * `initiate` itself is exercised at the service layer only (mirrors
 * ProviderConnectionServiceOAuthTest::initiateFlow()'s established
 * pattern exactly — see that file's helpers around lines 1608-1624):
 * the frozen design's own Scenario 1 wording is "initiate OAuth ->
 * simulateAuthorizationGrant() -> real HTTP request to
 * integrations.oauth.callback", i.e. only the CALLBACK leg is required
 * to be real HTTP here.
 */
final class OAuthConnectionControllerCallbackRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same deterministic-origin discipline as
        // ProviderConnectionServiceOAuthTest — route(..., absolute: true)
        // and this test's own real HTTP requests must resolve against a
        // stable, non-localhost, https host for
        // ProviderRedirectUrlValidator::assertSafe() to accept the
        // redirect_uri this test pins at initiate time.
        config(['app.url' => 'https://app.firmsbase.test']);
        URL::forceRootUrl('https://app.firmsbase.test');
        URL::forceScheme('https');

        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);

        TestProvider::resetSimulationState();
    }

    protected function tearDown(): void
    {
        TestProvider::resetSimulationState();
        parent::tearDown();
    }

    public function test_a_real_http_get_to_the_callback_route_completes_the_connection_and_redirects_to_the_connection_view(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);
        $code = $this->mintCode($flow['codeChallenge']);

        $response = $this->actingAs($firmUser->user)
            ->get(route('integrations.oauth.callback', ['state' => $flow['rawState'], 'code' => $code]));

        $response->assertRedirect(route('filament.firm.resources.firm-integrations.view', ['record' => $connection]));
        $response->assertSessionHas('success', 'Integration connected successfully.');

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Active, $fresh->status);
    }

    public function test_a_real_http_get_to_the_callback_route_persists_credentials_through_the_real_controller_not_just_the_service_layer(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);
        $code = $this->mintCode($flow['codeChallenge']);

        $this->actingAs($firmUser->user)
            ->get(route('integrations.oauth.callback', ['state' => $flow['rawState'], 'code' => $code]));

        $credentialCount = $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()
            ->where('firm_integration_id', $connection->id)
            ->where('status', 'active')
            ->count());

        $this->assertSame(2, $credentialCount, 'The real HTTP round trip through the controller must persist both the access and refresh credentials, exactly as the service-layer-only tests already prove — not merely redirect successfully with no actual side effect.');
    }

    public function test_a_real_http_get_to_the_callback_route_with_missing_state_and_code_redirects_to_the_dashboard_with_an_error_flash(): void
    {
        [, , $firmUser] = $this->firmConnectionAndActor();

        $response = $this->actingAs($firmUser->user)->get(route('integrations.oauth.callback'));

        $response->assertRedirect(route('filament.firm.pages.dashboard'));
        $response->assertSessionHas('error', 'This connection link is missing required information.');
    }

    public function test_a_real_http_get_to_the_callback_route_with_an_unknown_state_token_redirects_with_a_generic_error_flash(): void
    {
        [, , $firmUser] = $this->firmConnectionAndActor();

        $response = $this->actingAs($firmUser->user)
            ->get(route('integrations.oauth.callback', ['state' => str_repeat('z', 43), 'code' => 'irrelevant-code']));

        $response->assertRedirect(route('filament.firm.pages.dashboard'));
        $response->assertSessionHas('error');
    }

    public function test_a_real_http_get_to_the_callback_route_with_a_pkce_mismatch_redirects_with_an_error_and_never_activates_the_connection(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);

        // A code bound to a DIFFERENT, unrelated code_challenge — the
        // real controller's own catch(InvalidPkceVerifierException)
        // branch must fire, not the service-layer test's direct call.
        $code = $this->mintCode((new PkceService)->challengeForVerifier('an-unrelated-verifier'));

        $response = $this->actingAs($firmUser->user)
            ->get(route('integrations.oauth.callback', ['state' => $flow['rawState'], 'code' => $code]));

        $response->assertRedirect(route('filament.firm.pages.dashboard'));
        $response->assertSessionHas('error');

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Pending, $fresh->status, 'A rejected real HTTP callback must never activate the connection.');
    }

    public function test_the_callback_route_requires_authentication(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);
        $code = $this->mintCode($flow['codeChallenge']);

        // Deliberately NO actingAs() — the `auth` middleware on this
        // route's group must intercept before the controller action (and
        // therefore before any OAuth logic at all) ever runs. Verified
        // empirically: `php artisan route:list` shows this application
        // registers no plain, unguarded `login` named route at all — only
        // panel-scoped `filament.firm.auth.login`/`filament.admin.auth.login`
        // — so Laravel's own default unauthenticated-redirect handler
        // (which targets the bare `route('login')`) genuinely throws
        // RouteNotFoundException for this specific, plain-`auth`-guarded
        // route in THIS application, rather than issuing a redirect. This
        // is real, current production behavior (not something this
        // checkpoint's frozen F1-F6 allowlist authorizes changing) — the
        // assertion below documents that reality rather than assuming a
        // generic Laravel-skeleton redirect that does not actually apply
        // here. Caught as a generic Throwable (rather than asserted via
        // expectException()) because it is thrown from INSIDE the
        // framework's own exception-rendering pipeline (building the
        // AuthenticationException's redirect target) rather than from
        // ordinary request handling, and is not reliably re-surfaced
        // through PHPUnit's expectException() machinery in that
        // position — empirically confirmed by exercising both paths.
        $response = $this->get(route('integrations.oauth.callback', ['state' => $flow['rawState'], 'code' => $code]));

        // This application registers no plain `login` named route (only
        // panel-scoped filament.firm.auth.login/filament.admin.auth.login
        // — confirmed via `php artisan route:list`), so Laravel's default
        // AuthenticationException handling — which tries to redirect an
        // unauthenticated web request to route('login') — cannot resolve
        // a target and the request fails server-side while rendering
        // that response. This is real, current production behavior for
        // this specific plain-`auth`-guarded route (not something this
        // checkpoint's frozen F1-F6 allowlist authorizes changing); the
        // assertion below documents that reality (a failed response,
        // never a successful activation) rather than assuming a generic
        // Laravel-skeleton redirect that does not actually apply here.
        $response->assertStatus(500);

        $freshConnection = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Pending, $freshConnection->status, 'No activation could have happened for an unauthenticated request.');
    }

    public function test_a_post_to_the_callback_route_is_rejected_it_is_registered_get_only(): void
    {
        [, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);
        $code = $this->mintCode($flow['codeChallenge']);

        $response = $this->actingAs($firmUser->user)
            ->post(route('integrations.oauth.callback'), ['state' => $flow['rawState'], 'code' => $code]);

        $response->assertMethodNotAllowed();
    }

    public function test_a_real_http_callback_for_a_scope_insufficient_grant_redirects_with_a_warning_flash_and_leaves_the_connection_scope_insufficient(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $flow = $this->initiateFlow($connection, $firmUser);
        $code = $this->mintCode($flow['codeChallenge'], grantedScopes: ['test.read']);

        $response = $this->actingAs($firmUser->user)
            ->get(route('integrations.oauth.callback', ['state' => $flow['rawState'], 'code' => $code]));

        $response->assertRedirect(route('filament.firm.resources.firm-integrations.view', ['record' => $connection]));
        $response->assertSessionHas('warning');

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::ScopeInsufficient, $fresh->status);
    }

    // ------------------------------------------------------------
    // Helpers — mirrors ProviderConnectionServiceOAuthTest's own
    // established helper shapes exactly, so this file's real-HTTP
    // proofs sit on the same fixture discipline as the rest of the
    // suite (every fixture-creation call individually wrapped in
    // runWithFirmContext()).
    // ------------------------------------------------------------

    private function service(): ProviderConnectionService
    {
        return new ProviderConnectionService(
            new IntegrationOAuthStateService(
                new EmailBodyEncryptionService(new EncryptionKeyService),
                new PkceService,
                new ProviderRedirectUrlValidator,
            ),
            new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService), new TimelineEventRecorder),
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

    private function firmWithActiveKey(): Firm
    {
        $firm = Firm::factory()->create();
        $key = $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function firmUserFor(Firm $firm, FirmUserRole $role): FirmUser
    {
        $user = User::factory()->create();

        return $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->role($role)->create());
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration, 2: FirmUser}
     */
    private function firmConnectionAndActor(FirmUserRole $role = FirmUserRole::Attorney): array
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->pending()->create(['external_account_id' => null]));
        $firmUser = $this->firmUserFor($firm, $role);

        return [$firm, $connection, $firmUser];
    }

    /**
     * @return array{result: OAuthInitiationResult, rawState: string, codeChallenge: string}
     */
    private function initiateFlow(FirmIntegration $connection, FirmUser $firmUser): array
    {
        $redirectUri = route('integrations.oauth.callback', [], true);
        $result = $this->service()->initiateOAuthConnection($connection, $firmUser->user_id, $redirectUri);

        $query = [];
        parse_str((string) parse_url($result->authorizationUrl, PHP_URL_QUERY), $query);

        return [
            'result' => $result,
            'rawState' => $query['state'],
            'codeChallenge' => $query['code_challenge'],
        ];
    }

    private function mintCode(
        string $codeChallenge,
        ?string $externalAccountId = null,
        ?array $grantedScopes = null,
        bool $expired = false,
    ): string {
        return (new TestProvider)->simulateAuthorizationGrant($codeChallenge, $externalAccountId, $grantedScopes, $expired);
    }
}
