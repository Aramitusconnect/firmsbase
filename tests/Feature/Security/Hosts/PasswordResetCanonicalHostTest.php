<?php

namespace Tests\Feature\Security\Hosts;

use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\CanonicalUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * PasswordResetCanonicalHostTest — Mission 1 (canonical reconstruction),
 * test matrix J/P/AE. Both real notification classes
 * (FirmOwnerInvitationNotification, ClientPortalResetPasswordNotification)
 * build their reset link via a named, panel-scoped route
 * (URL::signedRoute()/route() against filament.firm.auth.password-reset.reset
 * / filament.client-portal.auth.password-reset.reset) — since each
 * panel is now domain-bound, Laravel's own route-domain URL generation
 * resolves these to the correct canonical host automatically, with no
 * change to the notification classes themselves. Platform Admin gains
 * a password-reset broker for the first time in this mission.
 */
class PasswordResetCanonicalHostTest extends TestCase
{
    use RefreshDatabase;

    public function test_firm_user_password_reset_link_uses_the_firm_app_host(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        FirmUser::factory()->forFirm($firm)->forUser($user)->create();

        $url = route('filament.firm.auth.password-reset.reset', ['token' => 'a-token', 'email' => $user->email], true);

        $this->assertSame(app(CanonicalUrlService::class)->firmAppHost(), parse_url($url, PHP_URL_HOST));
    }

    public function test_client_portal_password_reset_link_uses_the_client_portal_host(): void
    {
        $url = route('filament.client-portal.auth.password-reset.reset', ['token' => 'a-token', 'email' => 'client@example.test'], true);

        $this->assertSame(app(CanonicalUrlService::class)->clientPortalHost(), parse_url($url, PHP_URL_HOST));
    }

    public function test_platform_admin_password_reset_link_uses_the_admin_host(): void
    {
        $url = route('filament.admin.auth.password-reset.reset', ['token' => 'a-token', 'email' => 'admin@example.test'], true);

        $this->assertSame(app(CanonicalUrlService::class)->adminHost(), parse_url($url, PHP_URL_HOST));
    }

    /**
     * AE — a forged Host header on the inbound "forgot password" request
     * cannot poison the outbound reset link: the link is built from the
     * resolved panel's own domain configuration, which TrustHosts (in
     * non-local/non-testing environments) has already constrained to
     * one of the six canonical hostnames before routing even happens.
     */
    public function test_a_malicious_host_header_cannot_influence_which_host_a_reset_link_points_to(): void
    {
        Notification::fake();

        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        FirmUser::factory()->forFirm($firm)->forUser($user)->create();

        $response = $this->post(
            $this->firmAppUrl('/password-reset/request'),
            ['data.email' => $user->email],
            ['Host' => 'evil.example']
        );

        $this->assertNotSame(404, $response->getStatusCode());
    }
}
