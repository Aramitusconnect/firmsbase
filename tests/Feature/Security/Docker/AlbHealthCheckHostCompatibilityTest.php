<?php

declare(strict_types=1);

namespace Tests\Feature\Security\Docker;

use Tests\TestCase;

/**
 * 2026-08-14 — the ALB target group's health check sends the target's own
 * private IP:port as the Host header (AWS default, no per-check Host
 * override configured), which strict TrustHosts/CanonicalUrlService
 * correctly rejects with 400 once the six canonical staging hostnames were
 * introduced (see docker/security/ecs-image-vulnerability-exceptions.md and
 * StagingVulnerabilityExceptionGuardTest). docker/web/Caddyfile now rewrites
 * the Host header to staging.firmsvault.com ONLY when BOTH the exact path
 * (/up) and the exact ALB health-checker User-Agent match — everything else
 * keeps its real Host and TrustHosts keeps rejecting it. This is a static
 * structural guard on that Caddyfile block; the actual HTTP-behavior proof
 * (scenarios A-G: real 200/400 responses through a running container) is
 * exercised in the "Smoke-test all six roles and hardening properties" step
 * of .github/workflows/ecs-pipeline.yml, which this test cannot reach from
 * PHPUnit since the rewrite happens in Caddy/Go before PHP ever runs.
 */
class AlbHealthCheckHostCompatibilityTest extends TestCase
{
    private function caddyfileContents(): string
    {
        $path = base_path('docker/web/Caddyfile');

        $this->assertFileExists($path, 'docker/web/Caddyfile must exist for this compatibility fix to mean anything.');

        return (string) file_get_contents($path);
    }

    public function test_alb_health_matcher_requires_both_exact_path_and_exact_user_agent(): void
    {
        $source = $this->caddyfileContents();

        $this->assertMatchesRegularExpression(
            '/@alb_health\s*\{\s*path\s+\/up\s*[\r\n]+\s*header\s+User-Agent\s+ELB-HealthChecker\/2\.0\s*\}/m',
            $source,
            'The @alb_health matcher must require BOTH an exact "/up" path AND the exact ELB-HealthChecker/2.0 '
            .'User-Agent — a looser matcher (e.g. a path prefix, or User-Agent alone) would let a spoofed '
            .'User-Agent bypass TrustHosts on other routes, which is exactly what this fix must not do.'
        );
    }

    public function test_host_rewrite_targets_only_the_canonical_marketing_host(): void
    {
        $source = $this->caddyfileContents();

        $this->assertMatchesRegularExpression(
            '/request_header\s+@alb_health\s+Host\s+staging\.firmsvault\.com/',
            $source,
            'The health-check Host rewrite must target the literal canonical hostname staging.firmsvault.com, '
            .'not a wildcard, not an RFC1918 address, and not a value derived from the incoming request.'
        );
    }

    public function test_request_header_rewrite_executes_before_php_server_in_source_order(): void
    {
        $source = $this->caddyfileContents();

        $routeBlockStart = strpos($source, 'route {');
        $this->assertNotFalse($routeBlockStart, 'The health-check Host rewrite must live inside an explicit route {} '
            .'block — bare top-level directives are not guaranteed to execute in source order against php_server.');

        $requestHeaderPos = strpos($source, 'request_header @alb_health', $routeBlockStart);
        $phpServerPos = strpos($source, 'php_server', $routeBlockStart);

        $this->assertNotFalse($requestHeaderPos);
        $this->assertNotFalse($phpServerPos);
        $this->assertLessThan(
            $phpServerPos,
            $requestHeaderPos,
            'request_header @alb_health must appear before php_server inside the route {} block — a route block '
            .'executes its directives in literal source order regardless of Caddy\'s global default directive '
            .'order, which is what makes this deterministic (verified against the real FrankenPHP/Caddy binary '
            .'via `frankenphp adapt` — see the commit message for the adapted JSON proof).'
        );
    }

    public function test_no_synthetic_response_bypasses_the_real_application(): void
    {
        $source = $this->caddyfileContents();

        $this->assertDoesNotMatchRegularExpression(
            '/@alb_health[\s\S]{0,200}(respond|static_response|abort)\b/m',
            $source,
            'The /up health-check path must continue through the real php_server/Laravel runtime — a Caddy-level '
            .'synthetic "respond" would make the health check meaningless (it would never detect a genuinely '
            .'broken application).'
        );
    }

    public function test_no_rfc1918_or_wildcard_host_is_ever_trusted_by_caddy(): void
    {
        $source = $this->caddyfileContents();

        $this->assertDoesNotMatchRegularExpression(
            '/header\s+Host\s+(\*|10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/m',
            $source,
            'This fix must never match on the incoming Host header to decide trust — it matches only on path and '
            .'User-Agent, and always rewrites to one fixed canonical hostname. A matcher keyed on RFC1918 ranges '
            .'or a wildcard Host would reintroduce exactly the class of bypass this test suite exists to prevent.'
        );
    }

    public function test_admin_api_and_auto_https_remain_disabled(): void
    {
        $source = $this->caddyfileContents();

        $this->assertMatchesRegularExpression('/^\s*admin\s+off\s*$/m', $source);
        $this->assertMatchesRegularExpression('/^\s*auto_https\s+off\s*$/m', $source);
    }

    public function test_no_reverse_proxy_or_vulcain_directive_was_introduced(): void
    {
        $source = $this->caddyfileContents();

        $this->assertDoesNotMatchRegularExpression('/^\s*reverse_proxy\b/mi', $source);
        $this->assertDoesNotMatchRegularExpression('/^\s*vulcain\b/mi', $source);
    }
}
