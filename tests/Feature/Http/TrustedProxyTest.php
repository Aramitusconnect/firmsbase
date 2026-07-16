<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Proves the AWS-ALB trusted-proxy configuration in bootstrap/app.php
 * behaves correctly. The app trusts the AWS-ELB header set
 * (X-Forwarded-For/Port/Proto — NOT X-Forwarded-Host) from any source IP
 * (`at: '*'`) because the actual security boundary is the ECS task
 * security group (only the ALB's own security group may reach port 8080
 * at all, per the live staging network configuration) — not an IP
 * allowlist inside the application. See the docblock in bootstrap/app.php
 * for the full rationale.
 *
 * Because `at: '*'` trusts every source at the HTTP layer, PHPUnit cannot
 * exercise the actual network-level defense (the security group) — that
 * boundary is entirely outside the application. What these tests DO prove
 * at the application layer:
 *  - the trusted header set is honored (isSecure, URL generation, no
 *    scheme downgrade on redirect, session cookie compatibility);
 *  - the one header AWS ALB never sends and this app never trusts
 *    (X-Forwarded-Host) cannot be used to spoof the request's host, no
 *    matter what the proxy-IP policy is set to — the header-scoped defense
 *    that holds independent of the IP-trust decision.
 */
class TrustedProxyTest extends TestCase
{
    public function test_request_through_trusted_proxy_with_forwarded_https_is_detected_as_secure(): void
    {
        Route::get('/__test/is-secure', fn () => response()->json(['secure' => request()->isSecure()]));

        $response = $this->get('/__test/is-secure', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
        ]);

        $response->assertOk();
        $response->assertJson(['secure' => true]);
    }

    public function test_request_without_a_forwarded_proto_header_is_not_treated_as_secure(): void
    {
        Route::get('/__test/is-secure', fn () => response()->json(['secure' => request()->isSecure()]));

        $response = $this->get('/__test/is-secure');

        $response->assertOk();
        $response->assertJson(['secure' => false]);
    }

    public function test_generated_absolute_urls_use_https_when_forwarded_proto_is_https(): void
    {
        Route::get('/__test/url', fn () => response()->json(['url' => url('/anything')]));

        $response = $this->get('/__test/url', [
            'X-Forwarded-Proto' => 'https',
        ]);

        $response->assertOk();
        $this->assertStringStartsWith('https://', $response->json('url'));
    }

    public function test_redirects_do_not_downgrade_to_http_when_forwarded_proto_is_https(): void
    {
        Route::get('/__test/redirect', fn () => redirect('/somewhere-else'));

        $response = $this->get('/__test/redirect', [
            'X-Forwarded-Proto' => 'https',
        ]);

        $response->assertRedirect();
        $this->assertStringStartsWith('https://', $response->headers->get('Location'));
    }

    public function test_session_cookie_is_marked_secure_and_issued_normally_when_forwarded_https_and_session_secure_cookie_is_enabled(): void
    {
        // Mirrors the real runtime config: SESSION_SECURE_COOKIE=true in
        // every staging task definition (config/session.php: 'secure' =>
        // env('SESSION_SECURE_COOKIE')).
        config(['session.secure' => true]);

        Route::middleware('web')->get('/__test/session', function () {
            session(['probe' => true]);

            return 'ok';
        });

        $response = $this->get('/__test/session', [
            'X-Forwarded-Proto' => 'https',
        ]);

        $response->assertOk();

        $sessionCookieName = config('session.cookie');
        $sessionCookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === $sessionCookieName);

        $this->assertNotNull($sessionCookie, 'session cookie was not queued on the response');
        $this->assertTrue($sessionCookie->isSecure(), 'session cookie is not marked Secure despite session.secure=true');
    }

    public function test_forwarded_host_header_is_never_trusted_regardless_of_proxy_ip_policy(): void
    {
        Route::get('/__test/host', fn () => response()->json(['host' => request()->getHost()]));

        $response = $this->get('/__test/host', [
            'X-Forwarded-Host' => 'attacker.example.com',
        ]);

        $response->assertOk();
        $this->assertNotEquals('attacker.example.com', $response->json('host'));
    }
}
