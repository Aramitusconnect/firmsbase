<?php

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves config/database.php's Redis TLS stream-context logic (added to
 * fix the "SSL operation failed: certificate verify failed" staging
 * failure) in isolation, as a pure function of environment variables.
 *
 * config/database.php is evaluated once per PHP process at framework
 * boot, using whatever REDIS_* env vars were present at that moment — by
 * the time a feature test runs, `config('database.redis.*')` already
 * reflects the test process's own boot-time environment and cannot be
 * re-derived by changing env vars mid-test. Every test here instead
 * `require`s the config file fresh in a separate, tightly-controlled PHP
 * subprocess (composer autoload only, no framework boot) so each
 * environment-variable combination is exercised exactly as it would be
 * at real container startup.
 */
class RedisTlsConfigurationTest extends TestCase
{
    private const RELEVANT_ENV_VARS = [
        'REDIS_HOST',
        'REDIS_TLS_CA_FILE',
        'REDIS_TLS_PEER_NAME',
        'SSL_CERT_FILE',
    ];

    /**
     * @param  array<string, string>  $env
     * @return array<string, mixed> the resolved 'redis' config subtree
     */
    private function loadRedisConfig(array $env): array
    {
        // bootstrap/app.php (not just the Composer autoloader) is required
        // so the Application container is available — config/database.php's
        // 'sqlite' connection calls the database_path() helper, which needs
        // a bound Application instance, not merely autoloaded classes.
        $script = 'require '.escapeshellarg(base_path('vendor/autoload.php')).';'
            .'require '.escapeshellarg(base_path('bootstrap/app.php')).';'
            .'$c = require '.escapeshellarg(base_path('config/database.php')).';'
            .'echo json_encode($c["redis"]);';

        $commandParts = ['env'];

        foreach (self::RELEVANT_ENV_VARS as $var) {
            if (! array_key_exists($var, $env)) {
                $commandParts[] = '-u '.escapeshellarg($var);
            }
        }

        foreach ($env as $key => $value) {
            $commandParts[] = escapeshellarg("{$key}={$value}");
        }

        $commandParts[] = escapeshellarg(PHP_BINARY);
        $commandParts[] = '-r '.escapeshellarg($script);

        $output = shell_exec(implode(' ', $commandParts).' 2>&1');
        $decoded = json_decode((string) $output, true);

        if (! is_array($decoded)) {
            $this->fail("Failed to load an isolated redis config. Raw subprocess output:\n{$output}");
        }

        return $decoded;
    }

    public function test_tls_host_produces_a_strict_stream_context(): void
    {
        $redis = $this->loadRedisConfig([
            'REDIS_HOST' => 'tls://master.firmsbase-staging-redis.ga5wre.use1.cache.amazonaws.com',
        ]);

        $context = $redis['default']['context'];

        $this->assertIsArray($context);
        $this->assertArrayHasKey('stream', $context);
        $this->assertSame(true, $context['stream']['verify_peer']);
        $this->assertSame(true, $context['stream']['verify_peer_name']);
        $this->assertSame(false, $context['stream']['allow_self_signed']);
    }

    public function test_non_tls_redis_host_receives_no_tls_context(): void
    {
        $redis = $this->loadRedisConfig([
            'REDIS_HOST' => '127.0.0.1',
        ]);

        $this->assertNull($redis['default']['context']);
        $this->assertNull($redis['cache']['context']);
        $this->assertNull($redis['queue']['context']);
    }

    public function test_cafile_precedence_prefers_redis_tls_ca_file_over_ssl_cert_file_and_default(): void
    {
        $redis = $this->loadRedisConfig([
            'REDIS_HOST' => 'tls://redis.example.com',
            'REDIS_TLS_CA_FILE' => '/custom/redis-ca.pem',
            'SSL_CERT_FILE' => '/etc/ssl/certs/ca-certificates.crt',
        ]);

        $this->assertSame('/custom/redis-ca.pem', $redis['default']['context']['stream']['cafile']);
    }

    public function test_cafile_precedence_falls_back_to_ssl_cert_file_when_redis_tls_ca_file_is_absent(): void
    {
        $redis = $this->loadRedisConfig([
            'REDIS_HOST' => 'tls://redis.example.com',
            'SSL_CERT_FILE' => '/opt/custom-bundle.pem',
        ]);

        $this->assertSame('/opt/custom-bundle.pem', $redis['default']['context']['stream']['cafile']);
    }

    public function test_cafile_precedence_falls_back_to_the_default_path_when_neither_env_var_is_set(): void
    {
        $redis = $this->loadRedisConfig([
            'REDIS_HOST' => 'tls://redis.example.com',
        ]);

        $this->assertSame('/etc/ssl/certs/ca-certificates.crt', $redis['default']['context']['stream']['cafile']);
    }

    public function test_peer_name_is_derived_from_redis_host_without_the_tls_prefix(): void
    {
        $redis = $this->loadRedisConfig([
            'REDIS_HOST' => 'tls://master.firmsbase-staging-redis.ga5wre.use1.cache.amazonaws.com',
        ]);

        $peerName = $redis['default']['context']['stream']['peer_name'];

        $this->assertSame('master.firmsbase-staging-redis.ga5wre.use1.cache.amazonaws.com', $peerName);
        $this->assertStringNotContainsString('tls://', $peerName);
    }

    public function test_redis_tls_peer_name_env_var_overrides_the_derived_peer_name(): void
    {
        $redis = $this->loadRedisConfig([
            'REDIS_HOST' => 'tls://master.firmsbase-staging-redis.ga5wre.use1.cache.amazonaws.com',
            'REDIS_TLS_PEER_NAME' => 'explicit-override.example.com',
        ]);

        $this->assertSame('explicit-override.example.com', $redis['default']['context']['stream']['peer_name']);
    }

    public function test_default_cache_and_queue_connections_receive_the_identical_context(): void
    {
        $redis = $this->loadRedisConfig([
            'REDIS_HOST' => 'tls://master.firmsbase-staging-redis.ga5wre.use1.cache.amazonaws.com',
            'REDIS_TLS_CA_FILE' => '/custom/redis-ca.pem',
        ]);

        $this->assertSame($redis['default']['context'], $redis['cache']['context']);
        $this->assertSame($redis['default']['context'], $redis['queue']['context']);
        $this->assertNotNull($redis['default']['context']);
    }

    public function test_verify_peer_cannot_silently_become_false(): void
    {
        $redis = $this->loadRedisConfig([
            'REDIS_HOST' => 'tls://redis.example.com',
        ]);

        $stream = $redis['default']['context']['stream'];

        $this->assertArrayHasKey('verify_peer', $stream);
        $this->assertNotSame(false, $stream['verify_peer']);
        $this->assertTrue($stream['verify_peer']);

        $this->assertArrayHasKey('verify_peer_name', $stream);
        $this->assertNotSame(false, $stream['verify_peer_name']);
        $this->assertTrue($stream['verify_peer_name']);
    }

    public function test_self_signed_certificates_cannot_silently_become_allowed(): void
    {
        $redis = $this->loadRedisConfig([
            'REDIS_HOST' => 'tls://redis.example.com',
        ]);

        $stream = $redis['default']['context']['stream'];

        $this->assertArrayHasKey('allow_self_signed', $stream);
        $this->assertNotSame(true, $stream['allow_self_signed']);
        $this->assertFalse($stream['allow_self_signed']);
    }

    public function test_the_source_file_never_hardcodes_a_permissive_tls_flag(): void
    {
        // Belt-and-braces static check alongside the runtime assertions
        // above: the source itself must never contain the literal
        // permissive forms, regardless of which env vars a future test run
        // happens to set.
        $contents = file_get_contents(base_path('config/database.php'));

        $this->assertStringNotContainsString('verify_peer_name\' => false', $contents);
        $this->assertStringNotContainsString('verify_peer\' => false', $contents);
        $this->assertStringNotContainsString('allow_self_signed\' => true', $contents);
    }
}
