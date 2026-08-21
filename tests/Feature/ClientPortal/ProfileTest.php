<?php

declare(strict_types=1);

namespace Tests\Feature\ClientPortal;

use App\Filament\ClientPortal\Pages\Profile;
use App\Http\Middleware\EstablishPanelAuthGuardDefault;
use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\Firm;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ProfileTest (Client Portal) — PORTAL-007. Proves the "Log Out of
 * Other Sessions" header Action added to Profile.php:
 *   1. requires the correct CURRENT password (a wrong one never
 *      rotates the stored password hash);
 *   2. on a correct password, actually calls
 *      `Auth::guard('client')->logoutOtherDevices()` — proven by the
 *      stored password hash changing to a NEW value that still
 *      validates against the SAME plaintext password;
 *   3. that hash rotation is the exact mechanism
 *      `Filament\Http\Middleware\AuthenticateSession` (already wired
 *      into ClientPortalPanelProvider) uses to invalidate every OTHER
 *      session while leaving the session that performed the rotation
 *      itself active — proven directly against the real middleware,
 *      mirroring `GuardAwareAuthenticateSessionTest`'s own established
 *      "build two Requests, run them through the real middleware
 *      pipeline" technique, extended here to TWO independent session
 *      stores standing in for two separate devices/browsers.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_other_sessions_requires_the_correct_current_password(): void
    {
        [, $portalUser] = $this->makeClientAndPortalUser('CorrectPassword!123');
        $originalHash = $portalUser->password;

        Auth::guard('client')->login($portalUser);

        $test = Livewire::test(Profile::class);
        $test->callAction('logoutOtherSessions', data: ['currentPassword' => 'TotallyWrongPassword!999']);
        $test->assertNotified('Incorrect password');

        $this->assertSame(
            $originalHash,
            $portalUser->fresh()->password,
            'A wrong current password must never rotate the stored password hash.'
        );
    }

    public function test_logout_other_sessions_rotates_the_password_hash_on_a_correct_current_password(): void
    {
        [, $portalUser] = $this->makeClientAndPortalUser('CorrectPassword!123');
        $originalHash = $portalUser->password;

        Auth::guard('client')->login($portalUser);

        $test = Livewire::test(Profile::class);
        $test->callAction('logoutOtherSessions', data: ['currentPassword' => 'CorrectPassword!123']);
        $test->assertNotified('Logged out of all other sessions');

        $newHash = $portalUser->fresh()->password;

        $this->assertNotSame(
            $originalHash,
            $newHash,
            'A correct current password must rotate the stored password hash — the exact mechanism AuthenticateSession uses to invalidate every OTHER session.'
        );
        $this->assertTrue(
            Hash::check('CorrectPassword!123', $newHash),
            'The rotated hash must still validate against the SAME plaintext password — logoutOtherDevices() only rehashes, it never changes the password value itself.'
        );
    }

    public function test_a_session_holding_the_pre_logout_password_hash_is_invalidated_while_the_acting_session_survives(): void
    {
        [, $portalUser] = $this->makeClientAndPortalUser('CorrectPassword!123');

        // "Session A" — the browser tab that will perform the
        // logout-other-sessions action itself (must stay signed in).
        $sessionA = new Store('session_a', new ArraySessionHandler(120));
        $sessionA->start();
        $requestA = Request::create('/client-portal/profile', 'GET');
        $requestA->setLaravelSession($sessionA);
        $requestA->setUserResolver(fn () => Auth::guard('client')->user());

        // "Session B" — a second, already-authenticated device that
        // never touches the logout action at all.
        $sessionB = new Store('session_b', new ArraySessionHandler(120));
        $sessionB->start();
        $requestB = Request::create('/client-portal/profile', 'GET');
        $requestB->setLaravelSession($sessionB);
        $requestB->setUserResolver(fn () => Auth::guard('client')->user());

        Auth::guard('client')->setUser($portalUser);

        // Both sessions observe the SAME (pre-logout) password hash on
        // their first request, exactly as two already-logged-in
        // devices would.
        $this->runThroughAuthenticateSession($requestA);
        $this->runThroughAuthenticateSession($requestB);
        $this->assertTrue($sessionA->has('password_hash_client'));
        $this->assertTrue($sessionB->has('password_hash_client'));
        $this->assertSame($sessionA->get('password_hash_client'), $sessionB->get('password_hash_client'));

        // Session A performs the actual "logout other sessions"
        // mechanism (the same guard call Profile::getHeaderActions()
        // makes). AuthenticateSession's own tap()-after-next() step —
        // proven independently by GuardAwareAuthenticateSessionTest —
        // refreshes session A's stored hash to the new one within this
        // same request/response cycle.
        (new Pipeline($this->app))
            ->send($requestA)
            ->through([
                EstablishPanelAuthGuardDefault::class.':client',
                AuthenticateSession::class,
            ])
            ->then(function () {
                Auth::guard('client')->logoutOtherDevices('CorrectPassword!123');

                return new Response('ok');
            });

        // Session A's own next request still succeeds — it was
        // transparently refreshed to the new hash.
        $responseA = $this->runThroughAuthenticateSession($requestA);
        $this->assertInstanceOf(Response::class, $responseA);

        // Session B still carries the now-stale pre-logout hash and is
        // invalidated on its very next request — exactly the "log out
        // of every OTHER session, keep this one" guarantee PORTAL-007
        // asks for.
        $this->expectException(AuthenticationException::class);
        $this->runThroughAuthenticateSession($requestB);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array{0: Client, 1: ClientPortalUser}
     */
    private function makeClientAndPortalUser(string $password): array
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $portalUser = $this->runWithFirmContext($firm, fn () => ClientPortalUser::query()->create([
            'client_id' => $client->id,
            'email' => $client->email,
            'password' => Hash::make($password),
            'is_active' => true,
        ]));

        return [$client, $portalUser];
    }

    private function runThroughAuthenticateSession(Request $request): mixed
    {
        return (new Pipeline($this->app))
            ->send($request)
            ->through([
                EstablishPanelAuthGuardDefault::class.':client',
                AuthenticateSession::class,
            ])
            ->then(fn () => new Response('ok'));
    }
}
