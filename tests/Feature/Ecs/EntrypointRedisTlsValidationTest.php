<?php

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves docker/entrypoint.sh's fail-fast Redis-TLS CA-file validation
 * (step "1b") behaves correctly, by extracting that exact block — plus
 * the log()/fail() helpers it calls — out of the real, committed file
 * (never a hand-retyped copy) and executing it in a subshell with
 * controlled environment variables. The rest of entrypoint.sh execs a
 * role-specific command script assuming a fully containerized runtime, so
 * it cannot be run end-to-end in this test environment.
 */
class EntrypointRedisTlsValidationTest extends TestCase
{
    private function extractHelpersAndCaValidationBlock(): string
    {
        $path = base_path('docker/entrypoint.sh');
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, 'Failed to read docker/entrypoint.sh');

        $lines = explode("\n", $contents);

        [$helperStart, $helperEnd] = $this->findLineRange($lines, 'log() {', 'required_vars=(');
        [$blockStart, $blockEnd] = $this->findLineRange($lines, '# 1b. When Redis', '# 2. Defensive re-assertion');

        $helpers = implode("\n", array_slice($lines, $helperStart, $helperEnd - $helperStart));
        $block = implode("\n", array_slice($lines, $blockStart, $blockEnd - $blockStart));

        return $helpers."\n".$block;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function findLineRange(array $lines, string $startNeedle, string $endNeedle): array
    {
        $start = null;
        $end = null;

        foreach ($lines as $index => $line) {
            if ($start === null && str_contains($line, $startNeedle)) {
                $start = $index;
            }
            if ($start !== null && str_contains($line, $endNeedle)) {
                $end = $index;
                break;
            }
        }

        $this->assertNotNull($start, "Could not locate '{$startNeedle}' in docker/entrypoint.sh — has it been renamed?");
        $this->assertNotNull($end, "Could not locate '{$endNeedle}' in docker/entrypoint.sh — has it been renamed?");

        return [$start, $end];
    }

    /**
     * @param  array<string, string>  $env
     */
    private function runValidationBlock(array $env): string
    {
        $script = $this->extractHelpersAndCaValidationBlock();

        $tmpScript = tempnam(sys_get_temp_dir(), 'entrypoint_ca_check_');
        file_put_contents($tmpScript, "#!/usr/bin/env bash\nset -uo pipefail\n".$script."\necho \"REACHED_END\"\n");

        $envPrefix = '';
        foreach ($env as $key => $value) {
            $envPrefix .= escapeshellarg("{$key}={$value}").' ';
        }

        $output = shell_exec("env -u CACHE_STORE -u SESSION_DRIVER -u QUEUE_CONNECTION -u REDIS_HOST -u REDIS_TLS_CA_FILE -u SSL_CERT_FILE {$envPrefix} bash ".escapeshellarg($tmpScript).' 2>&1');
        @unlink($tmpScript);

        return (string) $output;
    }

    public function test_tls_redis_with_missing_ca_file_fails_safely(): void
    {
        $output = $this->runValidationBlock([
            'CACHE_STORE' => 'redis',
            'REDIS_HOST' => 'tls://redis.example.com',
            'REDIS_TLS_CA_FILE' => '/nonexistent/path/does-not-exist.pem',
        ]);

        $this->assertStringContainsString('FATAL', $output);
        $this->assertStringContainsString('does not exist', $output);
        $this->assertStringNotContainsString('REACHED_END', $output, 'The script must stop before reaching the end when the CA file is missing.');
    }

    public function test_tls_redis_with_unreadable_ca_file_fails_safely(): void
    {
        $unreadable = tempnam(sys_get_temp_dir(), 'unreadable_ca_');
        file_put_contents($unreadable, 'not a real cert');
        chmod($unreadable, 0000);

        try {
            // Running as root inside this sandbox would bypass the 0000
            // permission bit entirely — only assert the failure path when
            // the check can actually be exercised.
            if (posix_getuid() === 0) {
                $this->markTestSkipped('Running as root — chmod 0000 does not block reads, so this check cannot be exercised.');
            }

            $output = $this->runValidationBlock([
                'CACHE_STORE' => 'redis',
                'REDIS_HOST' => 'tls://redis.example.com',
                'REDIS_TLS_CA_FILE' => $unreadable,
            ]);

            $this->assertStringContainsString('FATAL', $output);
            $this->assertStringContainsString('not readable', $output);
            $this->assertStringNotContainsString('REACHED_END', $output);
        } finally {
            chmod($unreadable, 0644);
            @unlink($unreadable);
        }
    }

    public function test_tls_redis_with_a_valid_readable_ca_file_passes(): void
    {
        $valid = tempnam(sys_get_temp_dir(), 'valid_ca_');
        file_put_contents($valid, 'not a real cert but present and readable');

        try {
            $output = $this->runValidationBlock([
                'CACHE_STORE' => 'redis',
                'REDIS_HOST' => 'tls://redis.example.com',
                'REDIS_TLS_CA_FILE' => $valid,
            ]);

            $this->assertStringNotContainsString('FATAL', $output);
            $this->assertStringContainsString('REACHED_END', $output);
            $this->assertStringContainsString('verified present and readable', $output);
        } finally {
            @unlink($valid);
        }
    }

    public function test_tls_redis_does_not_require_redis_tls_peer_name_to_be_set(): void
    {
        $valid = tempnam(sys_get_temp_dir(), 'valid_ca_');
        file_put_contents($valid, 'not a real cert but present and readable');

        try {
            // Deliberately no REDIS_TLS_PEER_NAME — the application derives
            // it safely from REDIS_HOST, so the entrypoint must not demand it.
            $output = $this->runValidationBlock([
                'CACHE_STORE' => 'redis',
                'REDIS_HOST' => 'tls://redis.example.com',
                'REDIS_TLS_CA_FILE' => $valid,
            ]);

            $this->assertStringNotContainsString('FATAL', $output);
            $this->assertStringNotContainsString('REDIS_TLS_PEER_NAME', $output);
            $this->assertStringContainsString('REACHED_END', $output);
        } finally {
            @unlink($valid);
        }
    }

    public function test_non_tls_local_redis_does_not_require_a_ca_file_at_all(): void
    {
        $output = $this->runValidationBlock([
            'CACHE_STORE' => 'redis',
            'REDIS_HOST' => '127.0.0.1',
        ]);

        $this->assertStringNotContainsString('FATAL', $output);
        $this->assertStringContainsString('REACHED_END', $output);
    }

    public function test_when_redis_is_not_required_the_ca_check_is_skipped_entirely(): void
    {
        $output = $this->runValidationBlock([
            'REDIS_HOST' => 'tls://redis.example.com',
            // No CACHE_STORE/SESSION_DRIVER/QUEUE_CONNECTION set to redis —
            // the check must not even look at the CA file in this case.
        ]);

        $this->assertStringNotContainsString('FATAL', $output);
        $this->assertStringContainsString('REACHED_END', $output);
    }

    public function test_logs_never_contain_a_redis_password_secret_arn_or_credential_bearing_url(): void
    {
        $output = $this->runValidationBlock([
            'CACHE_STORE' => 'redis',
            'REDIS_HOST' => 'tls://redis.example.com',
            'REDIS_TLS_CA_FILE' => '/nonexistent/path/does-not-exist.pem',
            'REDIS_PASSWORD' => 'super-secret-token-value',
        ]);

        $this->assertStringNotContainsString('super-secret-token-value', $output);
        $this->assertStringNotContainsString('arn:aws:secretsmanager', $output);
    }
}
