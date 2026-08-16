<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The signup entry points added to the Firm and Client Portal login pages.
 *
 * The security-relevant assertions here are the negative ones: each button must
 * stay on its own host, and the Admin host must never grow a public
 * registration route. Platform administrators are not self-registered.
 */
class LoginSignupEntryPointTest extends TestCase
{
    use RefreshDatabase;

    private function firmHost(): string
    {
        return (string) parse_url((string) Config::get('hosts.firm_app_url'), PHP_URL_HOST);
    }

    private function clientHost(): string
    {
        return (string) parse_url((string) Config::get('hosts.client_portal_url'), PHP_URL_HOST);
    }

    private function adminHost(): string
    {
        return (string) parse_url((string) Config::get('hosts.admin_url'), PHP_URL_HOST);
    }

    public function test_firm_login_renders_the_register_your_firm_button(): void
    {
        $response = $this->get('https://'.$this->firmHost().'/login');

        $response->assertOk();
        $response->assertSee('Register your firm', escape: false);
        $response->assertSee(route('firm.register'), escape: false);
    }

    public function test_client_login_renders_the_create_client_account_button(): void
    {
        $response = $this->get('https://'.$this->clientHost().'/login');

        $response->assertOk();
        $response->assertSee('Create client account', escape: false);
        $response->assertSee(route('client-portal.register'), escape: false);
    }

    public function test_existing_login_ui_is_preserved(): void
    {
        // The change is additive: the stock form must still be intact.
        $response = $this->get('https://'.$this->firmHost().'/login');

        $response->assertOk();
        $response->assertSee('Sign in', escape: false);
        $response->assertSee('wire:submit="authenticate"', escape: false);
        $response->assertSee('password', escape: false);
    }

    public function test_firm_signup_route_stays_on_the_firm_host(): void
    {
        $this->assertStringContainsString($this->firmHost(), route('firm.register'));
        $this->assertStringNotContainsString($this->clientHost(), route('firm.register'));
        $this->assertStringNotContainsString($this->adminHost(), route('firm.register'));

        $this->get('https://'.$this->firmHost().'/register')->assertOk();
    }

    public function test_client_signup_route_stays_on_the_client_host(): void
    {
        $this->assertStringContainsString($this->clientHost(), route('client-portal.register'));
        $this->assertStringNotContainsString($this->firmHost(), route('client-portal.register'));
        $this->assertStringNotContainsString($this->adminHost(), route('client-portal.register'));

        $this->get('https://'.$this->clientHost().'/register')->assertOk();
    }

    public function test_signup_pages_do_not_claim_to_create_an_account(): void
    {
        // There is no canonical self-registration backend. These pages must be
        // honest entry points, not forms that imply an account can be made.
        foreach ([$this->firmHost(), $this->clientHost()] as $host) {
            $response = $this->get('https://'.$host.'/register');

            $response->assertOk();
            $response->assertDontSee('<input type="password"', escape: false);
            $response->assertDontSee('type="submit"', escape: false);
        }
    }

    public function test_admin_host_has_no_public_registration(): void
    {
        // The one assertion that must never regress.
        $this->get('https://'.$this->adminHost().'/register')->assertNotFound();

        $this->assertFalse(
            app('router')->has('admin.register'),
            'A public admin registration route must never exist.',
        );
    }

    public function test_signup_routes_do_not_exist_on_any_other_host(): void
    {
        // Both routes are Route::domain()-scoped, so an unrelated host simply
        // has no such route. (TrustHosts' own 400 for a spoofed Host header is
        // middleware that does not engage in the testing environment — that
        // behaviour is verified against live staging, not asserted here, so
        // this test states only what it actually proves.)
        $this->get('https://evil.example.com/register')->assertNotFound();
        $this->get('https://'.$this->adminHost().'/register')->assertNotFound();
    }

    public function test_signup_routes_are_unauthenticated_and_do_not_leak_a_session_across_hosts(): void
    {
        $firm = $this->get('https://'.$this->firmHost().'/register');
        $client = $this->get('https://'.$this->clientHost().'/register');

        $firm->assertOk();
        $client->assertOk();

        // Each host sets its own panel cookie; neither may widen to the parent.
        foreach ([$firm, $client] as $response) {
            foreach ($response->headers->getCookies() as $cookie) {
                $this->assertNotSame('.firmsvault.com', $cookie->getDomain(),
                    'Signup routes must not set a parent-domain cookie.');
            }
        }
    }
}
