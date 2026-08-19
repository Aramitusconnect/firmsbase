<?php

declare(strict_types=1);

namespace Tests\Feature\Security\Hosts;

use App\Services\CanonicalUrlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

final class TrustHostsAllowlistTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function configuredCanonicalUrls(): array
    {
        $hosts = app(CanonicalUrlService::class);

        return [
            $hosts->marketingUrl(),
            $hosts->firmAppUrl(),
            $hosts->clientPortalUrl(),
            $hosts->adminUrl(),
            $hosts->myAttorneyUrl(),
            $hosts->apiUrl(),
        ];
    }

    private function withConfiguredTrustedHosts(callable $callback): void
    {
        Request::setTrustedHosts(app(CanonicalUrlService::class)->trustedHostPatterns());

        try {
            $callback();
        } finally {
            Request::setTrustedHosts([]);
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_AWS_ELB);
        }
    }

    private function assertHostRejectedByConfiguredTrustList(string $url, array $server = []): void
    {
        $this->withConfiguredTrustedHosts(function () use ($url, $server): void {
            try {
                Request::create($url, 'GET', [], [], [], $server)->getHost();
            } catch (SuspiciousOperationException) {
                $this->addToAssertionCount(1);

                return;
            }

            $this->fail("Host in URL {$url} was not rejected by the configured trusted-host list.");
        });
    }

    public function test_each_exact_configured_canonical_host_is_accepted(): void
    {
        $this->withConfiguredTrustedHosts(function (): void {
            foreach ($this->configuredCanonicalUrls() as $url) {
                $request = Request::create(rtrim($url, '/').'/up');

                $this->assertSame(
                    parse_url($url, PHP_URL_HOST),
                    $request->getHost()
                );
            }
        });
    }

    public function test_arbitrary_host_is_rejected_with_http_400(): void
    {
        $this->assertHostRejectedByConfiguredTrustList('http://evil.example/up');

        Route::get('/__suspicious-host-render-probe', function (): never {
            throw new SuspiciousOperationException('evil.example');
        });

        $this->get('http://firmsvault.test/__suspicious-host-render-probe')
            ->assertStatus(400)
            ->assertSeeText('Bad Request');
    }

    public function test_arbitrary_subdomain_is_rejected(): void
    {
        $this->assertHostRejectedByConfiguredTrustList('http://anything.firmsvault.test/up');
    }

    public function test_x_forwarded_host_cannot_expand_the_trusted_host_set(): void
    {
        $this->withConfiguredTrustedHosts(function (): void {
            Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_AWS_ELB);

            $this->assertHostRejectedByConfiguredTrustList(
                'http://evil.example/up',
                [
                    'REMOTE_ADDR' => '127.0.0.1',
                    'HTTP_HOST' => 'evil.example',
                    'HTTP_X_FORWARDED_HOST' => 'firmsvault.test',
                ]
            );
        });
    }

    public function test_rejected_host_response_does_not_leak_supplied_host(): void
    {
        // phpunit.xml does not pin APP_DEBUG, so without this the result of
        // this test depends on whatever .env the runner happens to have: with
        // debug on, the exception page renders the exception MESSAGE — the
        // suspicious host itself — and the leak assertion below fails. Debug
        // is never on in staging or production, so pin the configuration this
        // property is actually about instead of inheriting the environment's.
        config(['app.debug' => false]);

        Route::get('/__suspicious-host-leak-probe', function (): never {
            throw new SuspiciousOperationException('attacker-controlled.example');
        });

        $response = $this->get('http://firmsvault.test/__suspicious-host-leak-probe');

        $response->assertStatus(400);

        // The framework answers a rejected host with its generic HTML error
        // page, not a bare "Bad Request" body. The property under test is that
        // the page is generic: the suspicious host must appear nowhere in the
        // response, so assert against the raw body as well as the rendered
        // text — assertDontSeeText strips tags and would miss a host echoed
        // inside an attribute or comment.
        $this->assertStringNotContainsString(
            'attacker-controlled.example',
            (string) $response->getContent(),
        );
        $response->assertDontSeeText('attacker-controlled.example');
    }

    public function test_trusted_hosts_are_unique_bare_configured_hostnames_only(): void
    {
        $expected = array_map(
            static fn (string $url): string => (string) parse_url($url, PHP_URL_HOST),
            $this->configuredCanonicalUrls(),
        );

        $trustedHosts = app(CanonicalUrlService::class)->trustedHosts();

        $this->assertSame(array_values(array_unique($expected)), $trustedHosts);

        foreach ($trustedHosts as $host) {
            $this->assertNotSame('', $host);
            $this->assertStringNotContainsString('*', $host);
            $this->assertStringStartsNotWith('^', $host);
            $this->assertStringStartsNotWith('.', $host);
        }
    }

    public function test_existing_trusted_proxy_aws_elb_header_behavior_remains_intact(): void
    {
        Route::get('/__trusted-proxy-probe', function (Request $request) {
            return response()->json([
                'secure' => $request->isSecure(),
                'scheme' => $request->getScheme(),
                'host' => $request->getHost(),
            ]);
        });

        $response = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-Host' => 'evil.example',
        ])->get('http://firmsvault.test/__trusted-proxy-probe');

        $response->assertOk();
        $response->assertJson([
            'secure' => true,
            'scheme' => 'https',
            'host' => 'firmsvault.test',
        ]);
    }
}
