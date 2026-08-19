<?php

declare(strict_types=1);

namespace Tests\Feature\Security\Docker;

use Tests\TestCase;

final class AlbHealthCheckHostCompatibilityTest extends TestCase
{
    private function caddyfileContents(): string
    {
        $path = base_path('docker/web/Caddyfile');

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function webCommandContents(): string
    {
        $path = base_path('docker/commands/web.sh');

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_caddy_matcher_requires_both_exact_up_path_and_exact_alb_user_agent(): void
    {
        $this->assertMatchesRegularExpression(
            '/@alb_health\s*\{\s*path\s+\/up\s*[\r\n]+\s*header\s+User-Agent\s+ELB-HealthChecker\/2\.0\s*\}/m',
            $this->caddyfileContents()
        );
    }

    public function test_caddy_host_rewrite_uses_environment_derived_healthcheck_host(): void
    {
        $this->assertMatchesRegularExpression(
            '/request_header\s+@alb_health\s+Host\s+\{\$FIRMSVAULT_ALB_HEALTHCHECK_HOST\}/',
            $this->caddyfileContents()
        );
    }

    public function test_caddyfile_contains_no_hardcoded_deployed_hostname(): void
    {
        $source = $this->caddyfileContents();

        $this->assertStringNotContainsString('staging.firmsvault.com', $source);
        $this->assertStringNotContainsString('app.firmsvault.com', $source);
        $this->assertStringNotContainsString('firmsvault.com', $source);
    }

    public function test_rewrite_executes_before_php_server_and_does_not_synthesize_health_response(): void
    {
        $source = $this->caddyfileContents();
        $routeStart = strpos($source, 'route {');

        $this->assertNotFalse($routeStart);

        $rewritePosition = strpos($source, 'request_header @alb_health', $routeStart);
        $phpServerPosition = strpos($source, 'php_server', $routeStart);

        $this->assertNotFalse($rewritePosition);
        $this->assertNotFalse($phpServerPosition);
        $this->assertLessThan($phpServerPosition, $rewritePosition);
        $this->assertDoesNotMatchRegularExpression('/@alb_health[\s\S]{0,200}\brespond\b/m', $source);
    }

    public function test_web_command_derives_healthcheck_host_from_marketing_url(): void
    {
        $source = $this->webCommandContents();

        $this->assertStringContainsString('MARKETING_URL', $source);
        $this->assertStringContainsString('parse_url($url, PHP_URL_HOST)', $source);
        $this->assertStringContainsString('export FIRMSVAULT_ALB_HEALTHCHECK_HOST="$healthcheck_host"', $source);
        $this->assertStringNotContainsString('FIRMSVAULT_ALB_HEALTHCHECK_HOST=staging.firmsvault.com', $source);
    }

    public function test_web_command_fails_closed_for_missing_or_invalid_marketing_url(): void
    {
        $source = $this->webCommandContents();

        $this->assertStringContainsString('if [[ -z "${MARKETING_URL:-}" ]]; then', $source);
        $this->assertStringContainsString('fail "MARKETING_URL is required for the web role"', $source);
        $this->assertStringContainsString('MARKETING_URL must be a valid URL with a hostname for the web role', $source);
        $this->assertStringContainsString('FILTER_VALIDATE_DOMAIN', $source);
    }
}
