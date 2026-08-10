<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Enums\ClientPortalStatus;
use App\Http\Middleware\ApplyTenantDatabaseContext;
use App\Http\Middleware\EstablishClientPortalTenantContext;
use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\Firm;
use App\Models\User;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * ClientPortalAuthenticationTest — Checkpoint 4 ("Plaid financial
 * evidence add-on"), Client Portal authentication foundation
 * (checkpoint4-design-matter-and-client-portal.md §2). Proves the
 * client guard's authentication/authorization boundary itself: login,
 * session establishment, direct-route denial (unauthenticated and
 * wrongly-scoped-guard requests), account deactivation handling
 * (both independent denial branches — ClientPortalUser.is_active and
 * Client.portal_status), password setup/reset against the real
 * client_portal_users password broker, session protection, and audit
 * events — all against the LIVE `client-portal` Filament panel and its
 * real HTTP routes (`/portal`, `/portal/login`), not synthetic
 * shortcuts.
 *
 * Audit events reuse the SAME app-wide Login/Failed listener
 * (AppServiceProvider::registerAuthenticationAuditLogging()) already
 * proven for the web/platform_admin guards
 * (PlatformAdminLoginPanelAccessTest is the direct precedent this file
 * mirrors) — that listener is guard-agnostic (keys off
 * `$event->user instanceof User` to decide whether to resolve a
 * firm_id, and reads `$event->guard` into metadata regardless), so a
 * ClientPortalUser login is audited via the identical, already-shipped
 * mechanism, with a null firm_id (mirroring platform_admin's own
 * null-firm_id login rows) rather than any new `client_portal.*`
 * event-type string.
 *
 * CONFIRMED PRODUCTION DEFECT (see
 * test_an_active_client_with_correct_credentials_can_authenticate()
 * below, and its sibling password-reset tests): `client_portal_users`
 * carries exactly two RLS policies — `client_portal_users_tenant_isolation`
 * (requires an ALREADY-ACTIVE `app.current_firm_id`) and
 * `client_portal_users_self_lookup` (requires an ALREADY-KNOWN
 * `app.current_client_portal_user_id`, i.e. already authenticated).
 * Neither policy permits looking a row up BY EMAIL with no context at
 * all — but that is exactly what Laravel's own
 * `Auth::guard('client')->attempt()` (`EloquentUserProvider::
 * retrieveByCredentials()`) and `Password::broker('client_portal_users')
 * ->sendResetLink()/reset()` must do to find the account in the first
 * place, since login/password-reset are, by definition, the moment
 * BEFORE any context can exist. Reproduced directly and minimally: a
 * raw `DB::table('client_portal_users')->where('email', ...)->count()`
 * with genuinely no ambient context (verified via
 * `current_setting('app.current_firm_id', true)`) returns 0 for a
 * real, active, correctly-provisioned row. This means the Client
 * Portal's login form and password-reset flow — both explicitly
 * required by this checkpoint's own directive — cannot function for
 * ANY client today, regardless of credential correctness. This is a
 * distinct defect from the ActivityRelationManager one documented in
 * MatterResourceAccessTest — it is not a rendering bug, it is a
 * missing RLS carve-out for the pre-authentication, look-up-by-email
 * moment that the two-hop bootstrap (Finding 2's fix) never addressed,
 * because that fix only ever covers what happens AFTER a
 * `ClientPortalUser` id is already known. The fix (not applied here,
 * flagged for the implementer) most plausibly needs a THIRD, narrow,
 * `FOR SELECT`-only policy scoped to unauthenticated login/reset
 * lookups — analogous in spirit to how the stock `password_reset_tokens`
 * table has no RLS at all specifically because it is looked up
 * pre-authentication — or a dedicated, audited, narrowly-scoped
 * service-layer bypass for exactly this one query shape.
 */
class ClientPortalAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // Login / session establishment
    // ------------------------------------------------------------

    /**
     * Asserts the CORRECT behavior — a real, active, correctly-
     * provisioned client with the right password can log in — using
     * genuinely no pre-established ambient context (this test never
     * wraps the attempt() call itself in runWithFirmContext(), exactly
     * mirroring what a real, unauthenticated login POST from a browser
     * looks like). This currently FAILS, exposing the confirmed defect
     * this class's own docblock documents in full: `attempt()` cannot
     * find the account by email at all under client_portal_users' real
     * RLS policies with no context active.
     */
    public function test_an_active_client_with_correct_credentials_can_authenticate(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->makePortalUser($client);

        $attempted = Auth::guard('client')->attempt(['email' => $portalUser->email, 'password' => 'Sup3rSecret!Pass']);

        $this->assertTrue($attempted, 'A correctly-provisioned, active client with the right password must be able to log in.');
        $this->assertTrue(Auth::guard('client')->check());
        $this->assertSame($portalUser->id, Auth::guard('client')->id());
    }

    public function test_login_does_not_authenticate_on_the_web_guard(): void
    {
        // Guard isolation: a successful client-guard login must never
        // also authenticate the web guard. This holds regardless of
        // whether attempt() itself currently succeeds (the confirmed
        // defect above) or fails — either way, the web guard must stay
        // uncheck.
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->makePortalUser($client);

        Auth::guard('client')->attempt(['email' => $portalUser->email, 'password' => 'Sup3rSecret!Pass']);

        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_an_authenticated_active_client_can_reach_the_portal_over_http(): void
    {
        // Simulates an already-established client-guard session
        // (actingAs() injects the hydrated model directly, bypassing
        // the broken by-email lookup this class's docblock documents)
        // to isolate and prove the POST-authentication boundary —
        // canAccessPanel() + EstablishClientPortalTenantContext's
        // two-hop bootstrap — independently of the separate, confirmed
        // pre-authentication lookup defect.
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->makePortalUser($client);

        $response = $this->actingAs($portalUser, 'client')->get($this->clientPortalUrl());

        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertNotSame(500, $response->getStatusCode());
    }

    // ------------------------------------------------------------
    // Direct-route denial
    // ------------------------------------------------------------

    public function test_an_unauthenticated_request_to_the_portal_is_redirected_to_login(): void
    {
        $response = $this->get($this->clientPortalUrl());

        $response->assertRedirect($this->clientPortalUrl('/login'));
    }

    public function test_a_web_guard_authenticated_firm_user_cannot_reach_the_client_portal(): void
    {
        // Wrongly-scoped-guard denial: a real, active firm staff
        // session (web guard) must not grant Client Portal access —
        // EstablishClientPortalTenantContext explicitly reads
        // $request->user('client'), never the default guard.
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')->get($this->clientPortalUrl());

        $response->assertRedirect($this->clientPortalUrl('/login'));
    }

    /**
     * CONFIRMED PRODUCTION DEFECT, distinct from the two documented in
     * this file's and MatterResourceAccessTest's own class docblocks:
     * `routes/web.php`'s `portal/plaid/exchange` route uses the bare
     * `auth:client` middleware (Laravel's stock `Authenticate`), whose
     * default `unauthenticated()` handler redirects to `route('login')`
     * for a non-JSON request. This application has NO route named
     * `login` anywhere (every guard's login route is Filament-panel-
     * scoped and internally named, e.g. `filament.client-portal.auth.login`)
     * — so an unauthenticated request to this endpoint throws an
     * uncaught `RouteNotFoundException` and surfaces as a 500 server
     * error instead of a clean redirect/401, the ONE new, hand-written,
     * non-Filament HTTP endpoint this checkpoint added. This asserts
     * the CORRECT behavior (a graceful denial, never a 500) and
     * currently fails.
     */
    public function test_the_client_portal_plaid_exchange_route_rejects_an_unauthenticated_request_gracefully(): void
    {
        $response = $this->post($this->clientPortalUrl('/plaid/exchange'), []);

        $this->assertNotSame(500, $response->getStatusCode(), 'An unauthenticated request must be denied gracefully (redirect/401/403), never crash with a 500.');
        $this->assertContains($response->getStatusCode(), [302, 401, 403]);
    }

    public function test_the_client_portal_plaid_exchange_route_rejects_a_web_guard_session_gracefully(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')->post($this->clientPortalUrl('/plaid/exchange'), []);

        $this->assertNotSame(500, $response->getStatusCode(), 'A wrongly-scoped web-guard session must be denied gracefully, never crash with a 500.');
        $this->assertContains($response->getStatusCode(), [302, 401, 403]);
    }

    // ------------------------------------------------------------
    // Account deactivation handling — both independent branches
    // ------------------------------------------------------------

    public function test_a_deactivated_client_portal_user_fails_the_can_access_panel_gate_independently_of_credential_correctness(): void
    {
        // Laravel's SessionGuard::attempt() checks the password first,
        // independent of canAccessPanel() — the real deactivation
        // boundary is canAccessPanel() itself (also proven via the real
        // HTTP route below), not attempt(). Proven directly here,
        // independent of the separate, confirmed by-email-lookup defect
        // this class's own docblock documents (which affects attempt()
        // regardless of is_active, so is not this test's concern).
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->makePortalUser($client, ['is_active' => false]);

        $this->assertFalse($portalUser->canAccessPanel(Filament::getPanel('client-portal')));
    }

    public function test_a_deactivated_client_portal_user_is_forbidden_from_the_portal_over_http(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->makePortalUser($client, ['is_active' => false]);

        $response = $this->actingAs($portalUser, 'client')->get($this->clientPortalUrl());

        $response->assertForbidden();
    }

    public function test_a_client_with_disabled_portal_status_is_forbidden_even_with_an_active_credential(): void
    {
        // The OTHER independent branch: ClientPortalUser.is_active=true
        // but the underlying Client.portal_status is Disabled (e.g. via
        // ClientPortalService::disable()) must still deny.
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Disabled]));
        $portalUser = $this->makePortalUser($client, ['is_active' => true]);

        $response = $this->actingAs($portalUser, 'client')->get($this->clientPortalUrl());

        $response->assertForbidden();
        $this->assertFalse($portalUser->canAccessPanel(Filament::getPanel('client-portal')));
    }

    public function test_a_client_with_not_invited_portal_status_is_forbidden(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::NotInvited]));
        $portalUser = $this->makePortalUser($client, ['is_active' => true]);

        $this->assertFalse($portalUser->canAccessPanel(Filament::getPanel('client-portal')));
    }

    public function test_an_active_client_and_active_credential_together_pass_can_access_panel(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->makePortalUser($client, ['is_active' => true]);

        $this->assertTrue($portalUser->canAccessPanel(Filament::getPanel('client-portal')));
    }

    // ------------------------------------------------------------
    // Session protection
    // ------------------------------------------------------------

    public function test_the_client_portal_panel_applies_authenticate_session_middleware(): void
    {
        // Mirrors the same middleware-presence assertion this
        // checkpoint's own design draws on for "session protection" —
        // AuthenticateSession is the same session-fixation protection
        // both existing panels already carry.
        $panel = Filament::getPanel('client-portal');

        $authMiddleware = collect($panel->getAuthMiddleware())->map(fn ($m) => is_string($m) ? $m : get_class($m));
        $middleware = collect($panel->getMiddleware())->map(fn ($m) => is_string($m) ? $m : get_class($m));

        $this->assertTrue(
            $middleware->contains(AuthenticateSession::class),
            'The client-portal panel must apply AuthenticateSession, matching the firm/admin panels.'
        );
        $this->assertTrue($authMiddleware->contains(EstablishClientPortalTenantContext::class));
        $this->assertTrue($authMiddleware->contains(ApplyTenantDatabaseContext::class));
    }

    public function test_the_client_portal_panel_uses_the_client_guard_explicitly(): void
    {
        $panel = Filament::getPanel('client-portal');

        $this->assertSame('client', $panel->getAuthGuard());
    }

    // ------------------------------------------------------------
    // Audit events
    // ------------------------------------------------------------

    public function test_a_successful_client_portal_login_is_recorded_as_a_security_event(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->makePortalUser($client);

        auth('client')->login($portalUser);

        $event = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('actor_type', ClientPortalUser::class)
                ->where('actor_id', $portalUser->id)
                ->where('event_type', 'login_succeeded')
                ->first()
        );

        $this->assertNotNull($event, 'Expected a login_succeeded SecurityEvent row for the client portal user.');
        $this->assertSame('authentication', $event->category);
        $this->assertNull($event->firm_id, "A client portal user's login event must not resolve any web-guard-shaped firm_id (the generic listener only resolves firm_id for App\\Models\\User actors).");
        $metadata = json_decode($event->metadata, true);
        $this->assertSame('client', $metadata['guard'] ?? null);
    }

    public function test_a_failed_client_portal_login_is_recorded_without_the_attempted_password(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->makePortalUser($client);

        event(new Failed('client', null, ['email' => $portalUser->email, 'password' => 'wrong-guess']));

        $event = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'login_failed')
                ->where('category', 'authentication')
                ->first()
        );

        $this->assertNotNull($event);
        $metadata = json_decode($event->metadata, true);
        $this->assertSame($portalUser->email, $metadata['attempted_email'] ?? null);
        $this->assertArrayNotHasKey('password', $metadata, 'The audit log must never store the attempted password.');
        $this->assertSame('client', $metadata['guard'] ?? null);
    }

    /**
     * Asserts correct behavior via the real attempt() path (rather than
     * the direct login() call the two tests above use) — currently
     * fails for the same confirmed by-email-lookup defect this class's
     * own docblock documents, since attempt() never succeeds and the
     * Login event is therefore never dispatched.
     */
    public function test_login_actually_fires_the_illuminate_login_event_with_the_client_guard_name(): void
    {
        Event::fake([Login::class]);

        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->makePortalUser($client);

        Auth::guard('client')->attempt(['email' => $portalUser->email, 'password' => 'Sup3rSecret!Pass']);

        Event::assertDispatched(Login::class, fn (Login $event) => $event->guard === 'client' && $event->user->is($portalUser));
    }

    // ------------------------------------------------------------
    // Password setup/reset for the client guard
    // ------------------------------------------------------------

    public function test_the_client_portal_password_broker_is_registered_and_resolvable(): void
    {
        $broker = Password::broker('client_portal_users');

        $this->assertInstanceOf(PasswordBroker::class, $broker);
    }

    /**
     * Asserts correct behavior — currently fails for the same confirmed
     * by-email-lookup defect this class's own docblock documents:
     * sendResetLink() must find the account by email with no context
     * active, exactly like attempt() must.
     */
    public function test_a_password_reset_link_can_be_generated_and_a_token_row_is_written(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->makePortalUser($client);

        $status = Password::broker('client_portal_users')->sendResetLink(['email' => $portalUser->email]);

        $this->assertSame(Password::RESET_LINK_SENT, $status, 'A valid, active client portal email must receive a reset link.');

        $row = DB::table('client_portal_password_reset_tokens')->where('email', $portalUser->email)->first();
        $this->assertNotNull($row, 'A client_portal_password_reset_tokens row must be written for a valid client portal email.');
    }

    /**
     * Asserts correct behavior — currently fails for the same confirmed
     * defect: the broker's reset() call needs to find the account by
     * email (never by an ambient context this test deliberately never
     * establishes) exactly like sendResetLink()/attempt() do. Builds
     * its own fixture data under proper firm context (a legitimate test
     * setup concern, not the behavior under test) but exercises the
     * real broker call itself with no such context active, matching
     * what an actual guest submitting the reset form experiences.
     */
    public function test_a_password_can_be_reset_via_the_broker_and_the_new_password_authenticates(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->makePortalUser($client);

        // createToken() itself needs only a real, hydrated model
        // instance (not a fresh RLS-gated read) — fetched here under
        // legitimate firm context purely as test setup, mirroring how
        // a real reset email is generated by application code that DOES
        // have firm context (e.g. an admin-triggered reset), not by an
        // unauthenticated guest.
        $hydratedPortalUser = $this->runWithFirmContext($firm, fn () => $portalUser->fresh());
        $broker = Password::broker('client_portal_users');
        $rawToken = $broker->createToken($hydratedPortalUser);

        $status = $broker->reset(
            ['email' => $portalUser->email, 'password' => 'BrandNewPassw0rd!', 'password_confirmation' => 'BrandNewPassw0rd!', 'token' => $rawToken],
            function (ClientPortalUser $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        $this->assertSame(Password::PASSWORD_RESET, $status, 'A valid token and matching email must successfully reset the password.');

        $attempted = Auth::guard('client')->attempt(['email' => $portalUser->email, 'password' => 'BrandNewPassw0rd!']);
        $this->assertTrue($attempted, 'The new password set via the broker must authenticate.');
    }

    /**
     * Asserts correct behavior — currently fails, and for the SAME
     * confirmed by-email-lookup defect (the broker cannot even find the
     * account to compare the token against, so it currently returns
     * Password::INVALID_USER rather than reaching a token-specific
     * comparison at all), not a token-validation-specific gap.
     */
    public function test_password_reset_rejects_an_invalid_token(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['portal_status' => ClientPortalStatus::Active]));
        $portalUser = $this->makePortalUser($client);

        $status = Password::broker('client_portal_users')->reset(
            ['email' => $portalUser->email, 'password' => 'AnotherPassw0rd!', 'password_confirmation' => 'AnotherPassw0rd!', 'token' => 'not-a-real-token'],
            function (ClientPortalUser $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        $this->assertSame(Password::INVALID_TOKEN, $status, 'An invalid token must be rejected specifically, distinctly from an unknown account.');
    }

    public function test_the_client_portal_login_and_password_reset_request_pages_are_reachable_by_a_guest(): void
    {
        $this->get($this->clientPortalUrl('/login'))->assertOk();
        $this->get($this->clientPortalUrl('/password-reset/request'))->assertOk();
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function makePortalUser(Client $client, array $overrides = []): ClientPortalUser
    {
        return $this->runWithFirmContext($client->firm_id, fn () => ClientPortalUser::query()->create(array_merge([
            'client_id' => $client->id,
            'email' => $client->email,
            'password' => Hash::make('Sup3rSecret!Pass'),
            'is_active' => true,
        ], $overrides)));
    }
}
