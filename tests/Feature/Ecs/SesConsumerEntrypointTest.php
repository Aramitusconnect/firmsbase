<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves docker/entrypoint.sh's ses-consumer role dispatch and
 * role-specific required-variable validation by actually executing the
 * real, committed entrypoint.sh — never a hand-retyped copy. The only
 * modification made to the copy under test is a single, mechanical
 * substitution of the hardcoded `cd /var/www/html` line for a temporary
 * stub directory (entrypoint.sh cannot otherwise run outside the real
 * built image, matching EntrypointRedisTlsValidationTest's existing
 * rationale for not running it completely unmodified). The stub
 * directory gets the same writable_paths entrypoint.sh itself checks for,
 * plus stub docker/commands/*.sh scripts that only echo their own
 * invocation (never actually starting FrankenPHP/queue:work/the real SES
 * consumer) so dispatch can be observed without needing a full built
 * image or a live database/queue.
 */
class SesConsumerEntrypointTest extends TestCase
{
    private string $stubRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stubRoot = sys_get_temp_dir().'/entrypoint_stub_'.bin2hex(random_bytes(8));
        mkdir($this->stubRoot, 0755, true);

        foreach ([
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/testing',
            'storage/framework/views',
            'storage/logs',
            'bootstrap/cache',
            'docker/commands',
        ] as $dir) {
            mkdir("{$this->stubRoot}/{$dir}", 0755, true);
        }

        foreach (['web', 'worker', 'scheduler', 'migrate', 'maintenance', 'ses-consumer'] as $role) {
            $stub = "#!/bin/bash\necho \"STUB_DISPATCHED_TO_{$role}\"\necho \"ARGS: \$*\"\nexit 0\n";
            file_put_contents("{$this->stubRoot}/docker/commands/{$role}.sh", $stub);
            chmod("{$this->stubRoot}/docker/commands/{$role}.sh", 0755);
        }

        $real = file_get_contents(base_path('docker/entrypoint.sh'));
        $this->assertNotFalse($real);
        $modified = str_replace('cd /var/www/html', 'cd '.escapeshellarg($this->stubRoot), $real);
        $this->assertNotSame($real, $modified, 'The cd /var/www/html substitution did not apply — has entrypoint.sh changed?');

        file_put_contents("{$this->stubRoot}/entrypoint_under_test.sh", $modified);
        chmod("{$this->stubRoot}/entrypoint_under_test.sh", 0755);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->stubRoot);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "{$dir}/{$item}";
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @param  array<string, string>  $env
     */
    private function runEntrypoint(string $role, array $env = []): string
    {
        $baseEnv = [
            'APP_KEY' => 'base64:test',
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_DATABASE' => 'test',
            'DB_USERNAME' => 'test',
            'DB_PASSWORD' => 'test',
        ];
        $merged = array_merge($baseEnv, $env);

        $envPrefix = '';
        foreach ($merged as $key => $value) {
            $envPrefix .= escapeshellarg("{$key}={$value}").' ';
        }

        $path = escapeshellarg((string) getenv('PATH'));

        return (string) shell_exec(
            "env -i PATH={$path} {$envPrefix} bash ".escapeshellarg("{$this->stubRoot}/entrypoint_under_test.sh").' '.$role.' 2>&1'
        );
    }

    // ------------------------------------------------------------
    // Role dispatch
    // ------------------------------------------------------------

    public function test_ses_consumer_is_a_recognized_role(): void
    {
        $output = $this->runEntrypoint('ses-consumer', [
            'SES_EVENTS_QUEUE_URL' => 'https://sqs.us-east-1.amazonaws.com/603013471426/firmsvault-staging-ses-events',
            'PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY' => 'test-hmac-key',
        ]);

        $this->assertStringNotContainsString('unknown role', $output);
        $this->assertStringNotContainsString('FATAL', $output);
    }

    public function test_ses_consumer_dispatches_its_own_command_script(): void
    {
        $output = $this->runEntrypoint('ses-consumer', [
            'SES_EVENTS_QUEUE_URL' => 'https://sqs.us-east-1.amazonaws.com/603013471426/firmsvault-staging-ses-events',
            'PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY' => 'test-hmac-key',
        ]);

        $this->assertStringContainsString('STUB_DISPATCHED_TO_ses-consumer', $output);
    }

    public function test_unknown_role_is_rejected_distinctly_from_ses_consumer(): void
    {
        $output = $this->runEntrypoint('bogus-role-xyz');

        $this->assertStringContainsString('FATAL', $output);
        $this->assertStringContainsString('unknown role', $output);
        $this->assertStringNotContainsString('STUB_DISPATCHED_TO', $output);
    }

    public function test_missing_role_error_lists_ses_consumer_as_a_valid_option(): void
    {
        $output = $this->runEntrypoint('');

        $this->assertStringContainsString('FATAL', $output);
        $this->assertStringContainsString('ses-consumer', $output, 'The no-role-given error text listing valid roles must mention ses-consumer.');
    }

    // ------------------------------------------------------------
    // Role-specific required-variable validation
    // ------------------------------------------------------------

    public function test_ses_consumer_fails_fast_when_the_queue_url_is_missing(): void
    {
        $output = $this->runEntrypoint('ses-consumer', [
            'PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY' => 'test-hmac-key',
        ]);

        $this->assertStringContainsString('FATAL', $output);
        $this->assertStringContainsString('SES_EVENTS_QUEUE_URL', $output);
        $this->assertStringNotContainsString('STUB_DISPATCHED_TO_ses-consumer', $output, 'Must fail before dispatch, not after.');
    }

    public function test_ses_consumer_fails_fast_when_the_hmac_secret_is_missing(): void
    {
        $output = $this->runEntrypoint('ses-consumer', [
            'SES_EVENTS_QUEUE_URL' => 'https://sqs.us-east-1.amazonaws.com/603013471426/firmsvault-staging-ses-events',
        ]);

        $this->assertStringContainsString('FATAL', $output);
        $this->assertStringContainsString('PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY', $output);
        $this->assertStringNotContainsString('STUB_DISPATCHED_TO_ses-consumer', $output);
    }

    public function test_web_fails_fast_when_the_hmac_secret_is_missing(): void
    {
        $output = $this->runEntrypoint('web', []);

        $this->assertStringContainsString('FATAL', $output);
        $this->assertStringContainsString('PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY', $output);
        $this->assertStringNotContainsString('STUB_DISPATCHED_TO_web', $output);
    }

    public function test_web_does_not_require_the_ses_events_queue_url(): void
    {
        $output = $this->runEntrypoint('web', [
            'PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY' => 'test-hmac-key',
        ]);

        $this->assertStringNotContainsString('FATAL', $output);
        $this->assertStringContainsString('STUB_DISPATCHED_TO_web', $output);
    }

    public function test_unrelated_roles_do_not_require_the_hmac_secret_or_queue_url(): void
    {
        foreach (['worker', 'scheduler', 'migrate', 'maintenance'] as $role) {
            $output = $this->runEntrypoint($role, []);

            $this->assertStringNotContainsString('FATAL', $output, "{$role} should not require PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY or SES_EVENTS_QUEUE_URL.");
            $this->assertStringContainsString("STUB_DISPATCHED_TO_{$role}", $output);
        }
    }

    public function test_no_secret_value_appears_in_entrypoint_output(): void
    {
        $output = $this->runEntrypoint('ses-consumer', [
            'SES_EVENTS_QUEUE_URL' => 'https://sqs.us-east-1.amazonaws.com/603013471426/firmsvault-staging-ses-events',
            'PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY' => 'super-secret-hmac-value-xyz',
            'DB_PASSWORD' => 'super-secret-db-password',
        ]);

        $this->assertStringNotContainsString('super-secret-hmac-value-xyz', $output);
        $this->assertStringNotContainsString('super-secret-db-password', $output);
    }

    // ------------------------------------------------------------
    // docker/commands/ses-consumer.sh content — static assertions
    // (actually executing it would try to start a real, unbounded
    // long-polling consumer, which is not appropriate for a unit test).
    // ------------------------------------------------------------

    public function test_ses_consumer_script_uses_exec_and_invokes_the_real_command(): void
    {
        $script = file_get_contents(base_path('docker/commands/ses-consumer.sh'));
        $this->assertNotFalse($script);

        $this->assertStringContainsString('#!/bin/bash', $script);
        $this->assertStringContainsString('set -euo pipefail', $script);
        $this->assertMatchesRegularExpression('/\bexec\s+php artisan ses:consume-events\b/', $script);
        $this->assertStringContainsString('--no-interaction', $script);
    }

    public function test_ses_consumer_script_does_not_run_migrations_or_config_cache_or_queue_work(): void
    {
        $script = file_get_contents(base_path('docker/commands/ses-consumer.sh'));
        $this->assertNotFalse($script);

        // Substring-match only ACTUAL artisan invocations (`php artisan
        // <command>`), not this file's own explanatory comments, which
        // legitimately mention e.g. "Deliberately NOT `queue:work`" to
        // document why.
        $this->assertDoesNotMatchRegularExpression('/php artisan migrate\b/', $script);
        $this->assertDoesNotMatchRegularExpression('/php artisan config:cache\b/', $script);
        $this->assertDoesNotMatchRegularExpression('/php artisan queue:work\b/', $script);
        $this->assertDoesNotMatchRegularExpression('/php artisan queue:listen\b/', $script);
    }

    public function test_ses_consumer_script_is_executable(): void
    {
        $this->assertTrue(is_executable(base_path('docker/commands/ses-consumer.sh')));
    }

    public function test_ses_consumer_script_never_prints_a_secret_or_config_value(): void
    {
        $script = file_get_contents(base_path('docker/commands/ses-consumer.sh'));
        $this->assertNotFalse($script);

        $this->assertStringNotContainsString('echo $', $script);
        $this->assertStringNotContainsString('echo "$', $script);
    }
}
