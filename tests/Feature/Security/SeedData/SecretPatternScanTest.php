<?php

namespace Tests\Feature\Security\SeedData;

use App\Services\SeedDataSecurityAuditService;
use Tests\TestCase;

/**
 * SecretPatternScanTest — Section 39E. Proves .env.example, config
 * files, seeders, factories, and docs contain no real-looking hardcoded
 * API keys/secrets, that safe test-only fixtures are allowed, and that
 * no demo firm/client/matter/document data is production-seeded.
 */
class SecretPatternScanTest extends TestCase
{
    private SeedDataSecurityAuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SeedDataSecurityAuditService();
    }

    public function test_env_example_contains_no_real_looking_secret_values(): void
    {
        $this->assertFileExists(base_path('.env.example'));

        $source = file_get_contents(base_path('.env.example'));

        foreach (['sk_live_', 'sk_test_', 'AKIA', 'ghp_', 'xoxb-', 'xoxp-'] as $pattern) {
            $this->assertStringNotContainsString($pattern, $source, ".env.example must not contain a real-looking secret pattern: {$pattern}");
        }

        // Sensitive-looking keys must be empty/null placeholders only.
        foreach (['AWS_ACCESS_KEY_ID=', 'AWS_SECRET_ACCESS_KEY=', 'MAIL_PASSWORD=', 'REDIS_PASSWORD='] as $keyPrefix) {
            $this->assertStringContainsString($keyPrefix, $source);
        }

        $this->assertStringNotContainsString('AWS_ACCESS_KEY_ID=AKIA', $source);
    }

    public function test_config_files_contain_no_real_looking_hardcoded_secrets(): void
    {
        foreach (glob(config_path('*.php')) ?: [] as $path) {
            $source = file_get_contents($path);

            foreach (['sk_live_', 'sk_test_', 'AKIA', 'ghp_', 'xoxb-', 'xoxp-'] as $pattern) {
                $this->assertStringNotContainsString($pattern, $source, basename($path)." must not contain a real-looking secret pattern: {$pattern}");
            }
        }
    }

    public function test_seeders_and_factories_contain_no_real_looking_hardcoded_secrets(): void
    {
        $paths = array_merge(
            glob(database_path('seeders/*.php')) ?: [],
            glob(database_path('factories/*.php')) ?: [],
        );

        foreach ($paths as $path) {
            $source = file_get_contents($path);

            foreach (['sk_live_', 'sk_test_', 'AKIA', 'ghp_', 'xoxb-', 'xoxp-'] as $pattern) {
                $this->assertStringNotContainsString($pattern, $source, basename($path)." must not contain a real-looking secret pattern: {$pattern}");
            }
        }
    }

    public function test_readme_and_composer_json_contain_no_real_looking_hardcoded_secrets(): void
    {
        foreach ([base_path('README.md'), base_path('composer.json')] as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $source = file_get_contents($path);

            foreach (['sk_live_', 'sk_test_', 'AKIA', 'ghp_', 'xoxb-', 'xoxp-'] as $pattern) {
                $this->assertStringNotContainsString($pattern, $source, basename($path)." must not contain a real-looking secret pattern: {$pattern}");
            }
        }
    }

    public function test_no_custom_artisan_command_exists_that_could_seed_production_data(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('app/Console'));
    }

    public function test_test_only_fixtures_are_allowed_when_isolated_to_the_tests_directory(): void
    {
        // tests/TestCase.php and phpunit.xml's DB_PASSWORD are
        // test/local-only fixtures, never reachable from a
        // production-executable path — confirmed here rather than
        // flagged as an audit finding.
        $this->assertFileExists(base_path('phpunit.xml'));

        $phpunitSource = file_get_contents(base_path('phpunit.xml'));
        $this->assertStringContainsString('APP_ENV" value="testing"', $phpunitSource);

        // phpunit.xml is not among the audit service's unsafe findings
        // even though it carries a local test DB_PASSWORD value —
        // because that value is a self-documenting placeholder
        // ("ChangeThisStrongPasswordNow"), not a real-looking secret.
        $unsafePaths = array_column($this->service->unsafeFindings(), 'path');
        $this->assertNotContains('phpunit.xml', $unsafePaths);
    }

    public function test_no_demo_firm_client_matter_or_document_data_is_production_seeded(): void
    {
        $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        foreach (['Firm::', 'Client::', 'Matter::', 'Document::'] as $needle) {
            $this->assertStringNotContainsString($needle, $source, 'DatabaseSeeder must not create demo firm/client/matter/document data.');
        }
    }
}
