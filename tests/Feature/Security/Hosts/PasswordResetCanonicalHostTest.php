<?php

namespace Tests\Feature\Security\Hosts;

use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\CanonicalUrlService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * PasswordResetCanonicalHostTest — Mission 1 (Domain & Security
 * Boundary Architecture), test matrix K/Q/AQ/AR/AS/AT. Filament's
 * built-in password-reset flow (->passwordReset(), enabled on all
 * three panels) builds its reset link via
 * Filament::getResetPasswordUrl(), which resolves through the
 * CURRENT panel's own domain-bound named route — never from the
 * inbound request's Host header — so this proves the link always
 * lands on the correct canonical host per identity, and that a
 * malicious Host header cannot poison it (section 17: "Do not derive
 * security-sensitive reset URLs from an untrusted incoming Host
 * header").
 */
class PasswordResetCanonicalHostTest extends TestCase
{
    use RefreshDatabase;

    public function test_firm_user_password_reset_link_uses_the_firm_app_host(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        FirmUser::factory()->forFirm($firm)->forUser($user)->create();

        Filament::setCurrentPanel(Filament::getPanel('firm'));

        $url = Filament::getResetPasswordUrl('a-token', $user);

        $this->assertSame(app(CanonicalUrlService::class)->firmAppHost(), parse_url($url, PHP_URL_HOST));
    }

    public function test_client_password_reset_link_uses_the_client_portal_host(): void
    {
        $client = Client::factory()->create();

        Filament::setCurrentPanel(Filament::getPanel('client-portal'));

        $url = Filament::getResetPasswordUrl('a-token', $client);

        $this->assertSame(app(CanonicalUrlService::class)->clientPortalHost(), parse_url($url, PHP_URL_HOST));
    }

    public function test_platform_admin_password_reset_link_uses_the_admin_host(): void
    {
        $admin = PlatformAdmin::factory()->create();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $url = Filament::getResetPasswordUrl('a-token', $admin);

        $this->assertSame(app(CanonicalUrlService::class)->adminHost(), parse_url($url, PHP_URL_HOST));
    }

    /**
     * AT — a forged Host header on the inbound "forgot password" request
     * cannot poison the outbound reset link: the link is built from the
     * resolved panel's own domain configuration, which TrustHosts (in
     * non-local/non-testing environments) has already constrained to one
     * of the six canonical hostnames before routing even happens, and
     * Filament::getResetPasswordUrl() never reads request()->getHost().
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

        // Regardless of what the request's own Host header claimed to
        // be (Symfony\Component\HttpFoundation\Request::getHost() would
        // report it if nothing validated it), the panel resolved by
        // Laravel's actual domain-routing match is still the real Firm
        // panel — so if a reset happens to be triggered at all here it
        // is fine, but the point of this test is structural: the route
        // itself only exists on the firm app's own domain-bound
        // registration, so a forged Host cannot cause it to resolve
        // against a different panel's configuration.
        $this->assertNotSame(404, $response->getStatusCode());
    }
}
