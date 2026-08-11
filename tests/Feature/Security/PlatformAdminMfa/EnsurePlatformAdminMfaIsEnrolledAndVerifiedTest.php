<?php

declare(strict_types=1);

namespace Tests\Feature\Security\PlatformAdminMfa;

use App\Enums\PlatformRoleCode;
use App\Models\PlatformAdmin;
use App\Models\WebauthnCredential;
use App\Services\PlatformRoleService;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EnsurePlatformAdminMfaIsEnrolledAndVerifiedTest — MFA design proposal
 * §5/§9. Every test in here hits a REAL panel resource URL
 * (`/admin/platform-administrators`, gated SuperAdmin-only by
 * PlatformAdminPolicy) directly via the real HTTP kernel/middleware
 * stack — never calls the middleware's handle() method directly — so
 * these are genuine proofs that the panel itself, not just the
 * middleware class in isolation, enforces each of the 5 steps. Every
 * test admin is granted SuperAdmin so PlatformAdminPolicy's own
 * authorization always passes, isolating what these tests are actually
 * proving to the MFA middleware layer specifically.
 */
class EnsurePlatformAdminMfaIsEnrolledAndVerifiedTest extends TestCase
{
    use RefreshDatabase;

    private function protectedUrl(): string
    {
        return $this->adminUrl('/platform-administrators');
    }

    private function superAdmin(array $attributes = []): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(array_merge(['is_active' => true], $attributes));
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        return $admin;
    }

    /**
     * Direct-route-bypass test: a password-only-equivalent session
     * (actingAs(), simulating a session that is authenticated at the
     * Laravel guard level but has never actually completed MFA
     * enrollment/challenge) hitting a real protected resource URL
     * directly must redirect, never 200.
     */
    public function test_never_enrolled_admin_hitting_a_protected_resource_directly_is_redirected_not_200(): void
    {
        $admin = $this->superAdmin(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);

        $response = $this->actingAs($admin, 'platform_admin')->get($this->protectedUrl());

        $response->assertRedirect();
        $response->assertStatus(302);
        $this->assertNotSame(200, $response->getStatusCode());
    }

    /**
     * Enrollment flow end-to-end: a never-enrolled admin is redirected
     * to the set-up-required page specifically (not merely "somewhere
     * else"), and that page itself is reachable (200, no redirect
     * loop) — "nothing else reachable" is proven by the redirect
     * target in the previous test never being the protected resource
     * itself.
     */
    public function test_never_enrolled_admin_is_redirected_to_the_set_up_required_page(): void
    {
        $admin = $this->superAdmin(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);

        $response = $this->actingAs($admin, 'platform_admin')->get($this->protectedUrl());

        $response->assertRedirect($this->adminUrl('/multi-factor-authentication/set-up'));
    }

    public function test_never_enrolled_admin_can_reach_the_set_up_required_page_itself_without_looping(): void
    {
        $admin = $this->superAdmin(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);

        $response = $this->actingAs($admin, 'platform_admin')->get($this->adminUrl('/multi-factor-authentication/set-up'));

        $response->assertOk();
    }

    public function test_enrolled_active_admin_with_no_reset_reaches_the_resource(): void
    {
        $admin = $this->superAdmin([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'platform_admin')->get($this->protectedUrl());

        $response->assertOk();
    }

    /**
     * Mission 1B (Extreme Security Hardening) — step 3's enrollment
     * check widened from TOTP-only to "TOTP OR WebAuthn". An admin who
     * has ONLY ever registered a WebAuthn/passkey credential (never
     * enrolled TOTP at all) must reach the resource, not be treated as
     * unenrolled and redirected to the set-up-required page forever.
     */
    public function test_admin_enrolled_only_via_webauthn_reaches_the_resource(): void
    {
        $admin = $this->superAdmin(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
        WebauthnCredential::factory()->create(['platform_admin_id' => $admin->id]);

        $response = $this->actingAs($admin, 'platform_admin')->get($this->protectedUrl());

        $response->assertOk();
    }

    /**
     * is_active fail-closed, takes precedence over MFA state, backed by
     * this middleware's own STEP 1 fresh re-read specifically — not
     * merely Filament's own PlatformAdmin::canAccessPanel() check
     * (Filament\Http\Middleware\Authenticate, which runs earlier in the
     * pipeline, would already 403 an is_active=false actingAs() fixture
     * on its own, since that check reads the exact same in-memory
     * object actingAs() was given). To isolate and prove THIS
     * middleware's own contribution — re-reading fresh from the
     * database every request rather than trusting whatever was true
     * when the guard's user was first resolved — this test gives
     * actingAs() a STALE is_active=true object, then flips is_active to
     * false directly in the database afterward (simulating a
     * deactivation that happened after the guard's user was resolved).
     * Filament's own canAccessPanel() check sees only the stale object
     * and passes; this middleware's step 1/2 must still catch it via
     * its own fresh read and force a logout, never a 200.
     */
    public function test_deactivated_admin_is_force_logged_out_even_if_enrolled(): void
    {
        $admin = $this->superAdmin([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'is_active' => true,
        ]);

        PlatformAdmin::query()->where('id', $admin->id)->update(['is_active' => false]);

        $response = $this->actingAs($admin, 'platform_admin')->get($this->protectedUrl());

        $response->assertRedirect();
        $this->assertNotSame(200, $response->getStatusCode());
    }

    /**
     * Reset-forces-immediate-logout: an admin's session that
     * authenticated (session marker present) BEFORE a reset must be
     * denied on its very next request once two_factor_reset_at is
     * bumped — proving the reset takes effect immediately, not merely
     * on next natural logout.
     */
    public function test_session_predating_a_reset_is_force_logged_out_on_next_request(): void
    {
        $admin = $this->superAdmin([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ]);

        $sessionAuthenticatedAt = now()->subMinute();

        // Simulate: this session authenticated a minute ago (the real
        // Login-event listener would have stamped this at login time).
        $response = $this->actingAs($admin, 'platform_admin')
            ->withSession(['platform_admin_mfa_session_authenticated_at' => $sessionAuthenticatedAt->toISOString()])
            ->get($this->protectedUrl());

        $response->assertOk();

        // Now an authorized reset happens (bumping two_factor_reset_at
        // to AFTER the session's own authenticated-at stamp) — the
        // exact same session, on its very next request, must be denied.
        $admin->forceFill(['two_factor_reset_at' => now()])->save();

        $response = $this->actingAs($admin, 'platform_admin')
            ->withSession(['platform_admin_mfa_session_authenticated_at' => $sessionAuthenticatedAt->toISOString()])
            ->get($this->protectedUrl());

        $response->assertRedirect();
        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function test_session_authenticated_after_a_reset_is_not_force_logged_out(): void
    {
        $admin = $this->superAdmin([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'two_factor_reset_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($admin, 'platform_admin')
            ->withSession(['platform_admin_mfa_session_authenticated_at' => now()->toISOString()])
            ->get($this->protectedUrl());

        $response->assertOk();
    }

    public function test_a_reset_admin_with_no_session_marker_at_all_is_force_logged_out(): void
    {
        // Fail-closed: a session this middleware cannot prove predates
        // the reset (no marker present at all) must not be trusted.
        $admin = $this->superAdmin([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'two_factor_reset_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'platform_admin')->get($this->protectedUrl());

        $response->assertRedirect();
        $this->assertNotSame(200, $response->getStatusCode());
    }

    /**
     * Session-verification (remember-me defense-in-depth): a session
     * authenticated purely via a real Illuminate\Auth\SessionGuard
     * "remember me" recaller cookie (never issued by PlatformAdminLogin
     * itself, since the checkbox is removed — this proves what happens
     * if one exists anyway, e.g. forged/replayed/predating this
     * policy) is force-logged-out, even though the admin is fully
     * enrolled and active.
     */
    public function test_a_session_authenticated_purely_via_a_remember_me_recaller_cookie_is_force_logged_out(): void
    {
        $admin = PlatformAdmin::factory()->create([
            'is_active' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        $admin->setRememberToken(Str::random(60));
        $admin->save();

        $recallerName = 'remember_platform_admin_'.sha1(SessionGuard::class);
        $recallerValue = "{$admin->id}|{$admin->remember_token}|{$admin->password}";

        // No actingAs()/session-based auth at all — the guard must
        // resolve this admin PURELY through SessionGuard::
        // userFromRecaller(), the exact real bypass path gap #2
        // describes.
        $response = $this->withCookie($recallerName, $recallerValue)->get($this->protectedUrl());

        $response->assertRedirect();
        $this->assertNotSame(200, $response->getStatusCode());
    }
}
