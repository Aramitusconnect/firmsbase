<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Proves the AWS-ALB trusted-proxy configuration in bootstrap/app.php
 * (Request::HEADER_X_FORWARDED_AWS_ELB, at: '*') behaves correctly at the
 * APPLICATION layer only. See bootstrap/app.php for the full rationale.
 *
 * This suite deliberately covers two DIFFERENT kinds of protection, and
 * does not blur them:
 *
 * 1. Application-layer protection (this is what these tests prove):
 *    - the app trusts exactly the AWS-ELB header set — X-Forwarded-For,
 *      X-Forwarded-Port, X-Forwarded-Proto;
 *    - X-Forwarded-Host is NEVER part of that trusted set, so no request —
 *      trusted proxy or not — can use it to spoof Request::getHost();
 *    - when X-Forwarded-Proto=https is present, the app correctly reports
 *      the request as secure, generates https:// URLs, does not downgrade
 *      redirects to http, and issues a Secure session cookie without
 *      erroring;
 *    - when no X-Forwarded-Proto header is present, the app correctly
 *      falls back to treating the request as plain HTTP.
 *
 * 2. Network-layer protection (this suite does NOT and CANNOT prove this):
 *    - because `at: '*'` is configured, Laravel trusts the AWS-ELB header
 *      set from ANY source IP that reaches it at the HTTP layer — that
 *      includes X-Forwarded-Proto. A direct, unauthorized HTTP client that
 *      somehow reached this container could set X-Forwarded-Proto: https
 *      and Laravel would believe it, exactly like the ALB's own traffic.
 *    - what actually prevents an unauthorized client from ever reaching
 *      this container is the ECS task security group
 *      (sg-0db14e50ea5c5466c), which is intended to permit inbound :8080
 *      exclusively from the reviewed ALB security group — not the public
 *      internet, and not any other ENI in the VPC.
 *    - PHPUnit has no network layer to exercise and therefore cannot prove
 *      that security-group boundary holds. It must be verified LIVE
 *      against the real AWS resources (e.g. via a security-group preflight
 *      script) before the web service is ever launched — see
 *      staging-deploy/08-http-exposure-preflight.sh once the deployment
 *      package exists. If that security-group rule is ever loosened to a
 *      CIDR-based rule instead of an SG-reference rule, `at: '*'` must be
 *      revisited alongside it.
 */
class TrustedProxyTest extends TestCase
{
    public function test_request_carrying_forwarded_proto_https_is_detected_as_secure(): void
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

    /**
     * X-Forwarded-Host is excluded from Request::HEADER_X_FORWARDED_AWS_ELB,
     * so it is never trusted no matter what the request looks like — this
     * is an application-layer, header-scoped defense. It does NOT depend
     * on (and does not prove anything about) which IP addresses are
     * allowed to reach this endpoint over the network; that is a separate
     * property enforced by the ECS security group, not by this test.
     */
    public function test_forwarded_host_header_is_never_trusted_because_it_is_excluded_from_the_trusted_header_set(): void
    {
        Route::get('/__test/host', fn () => response()->json(['host' => request()->getHost()]));

        $response = $this->get('/__test/host', [
            'X-Forwarded-Host' => 'attacker.example.com',
        ]);

        $response->assertOk();
        $this->assertNotEquals('attacker.example.com', $response->json('host'));
    }
}
