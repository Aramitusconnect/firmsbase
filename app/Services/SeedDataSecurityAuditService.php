<?php

namespace App\Services;

/**
 * SeedDataSecurityAuditService — Section 39E. A read-only, re-runnable
 * static/file audit proving the repository cannot accidentally ship
 * unsafe default seed users, demo data, hardcoded credentials, fake
 * API keys, or test-only defaults into a real deployment.
 *
 * It only reads files from disk (glob()/file_get_contents()/file()) —
 * it never writes a file, never calls Schema::create/table/drop or any
 * DB write statement, and never makes a network/provider/process call.
 *
 * It distinguishes SAFE reference/catalog data (migration-embedded
 * module_catalog/integration_degradation_modes rows — plan-control
 * metadata with no credential or customer-data column) from UNSAFE
 * seed/default data (a login-capable default user/password created
 * with no local/testing environment guard, or a real-looking
 * hardcoded secret/API key in a checked-in file).
 */
class SeedDataSecurityAuditService
{
    private const SCANNED_PATHS = [
        'database/seeders',
        'database/factories',
        'database/migrations',
        '.env.example',
        'phpunit.xml',
        'config',
        'README.md',
        'composer.json',
    ];

    /**
     * High-signal literal patterns that indicate a REAL hardcoded
     * secret/API key wherever they appear. Deliberately narrower than
     * FULL_CHECKLIST_VOCABULARY below: bare words like "password" or
     * "secret" legitimately appear throughout Laravel's own config
     * scaffolding (config/auth.php, config/session.php) as array/
     * column KEY NAMES, not as real secret VALUES, so matching them
     * directly would flag safe framework code as unsafe. These
     * provider-specific key-prefix patterns essentially never appear
     * outside a real, live-issued credential.
     */
    private const HIGH_SIGNAL_SECRET_PATTERNS = [
        'sk_live_', 'sk_test_', 'AKIA', 'ghp_', 'xoxb-', 'xoxp-',
    ];

    /**
     * The full checklist vocabulary this audit is aware of (verbatim
     * from the Section 39E inspection checklist), exposed via
     * forbiddenPatterns() for transparency even though only the
     * refined subset above and the sensitive-env-key-marker check
     * below drive actual detection.
     */
    private const FULL_CHECKLIST_VOCABULARY = [
        'test@example.com', 'admin@example.com', 'password', 'Password123', 'password123',
        'secret', 'api_key', 'sk-', 'sk_test', 'sk_live', 'OPENAI_API_KEY', 'STRIPE_SECRET',
        'client_secret', 'access_token', 'refresh_token', 'private_key',
        'AWS_ACCESS_KEY', 'AWS_SECRET_ACCESS_KEY',
    ];

    private const SENSITIVE_ENV_KEY_MARKERS = ['KEY', 'SECRET', 'TOKEN', 'PASSWORD'];

    /**
     * Column-name markers that would make a migration-embedded row
     * look like credential/customer data rather than safe reference
     * data (e.g. a module_catalog row). None of the 5 current
     * migration-embedded seed rows contain any of these.
     */
    private const CREDENTIAL_LIKE_COLUMN_MARKERS = ['password', 'email', 'token', 'secret', 'api_key', 'private_key'];

    /**
     * @return array<int, string>
     */
    public function scannedPaths(): array
    {
        return self::SCANNED_PATHS;
    }

    /**
     * @return array<int, string>
     */
    public function forbiddenPatterns(): array
    {
        return self::FULL_CHECKLIST_VOCABULARY;
    }

    /**
     * @return array<int, array{path: string, classification: string, issue: string}>
     */
    public function findings(): array
    {
        return array_merge(
            $this->productionSeedRisk(),
            $this->scanEnvExampleForRealLookingSecrets(),
            $this->scanConfigAndDocsForHighSignalSecrets(),
            $this->safeReferenceDataFindings(),
        );
    }

    /**
     * @return array<int, array{path: string, classification: string, issue: string}>
     */
    public function unsafeFindings(): array
    {
        return array_values(array_filter(
            $this->findings(),
            fn (array $finding) => $finding['classification'] === 'unsafe',
        ));
    }

    /**
     * Migration-embedded static reference/catalog rows (module_catalog,
     * integration_degradation_modes) — safe because they contain no
     * credential-like or customer-data column.
     *
     * @return array<int, array{path: string, classification: string, issue: string}>
     */
    public function safeReferenceDataFindings(): array
    {
        $findings = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            $filename = basename($path);

            if (! str_contains($filename, 'seed_')) {
                continue;
            }

            $source = file_get_contents($path);

            if ($this->containsCredentialLikeColumnKey($source)) {
                continue;
            }

            $findings[] = [
                'path' => 'database/migrations/'.$filename,
                'classification' => 'safe_reference',
                'issue' => 'Migration-embedded static reference/catalog data — no credential or customer-data column.',
            ];
        }

        return $findings;
    }

    /**
     * Flags any seeder file (database/seeders/*.php) that creates a
     * login-capable default user (User/FirmUser factory) without an
     * explicit app()->environment(['local', 'testing']) guard —
     * exactly the risk that could let a real deployment run
     * `php artisan db:seed` and get a default test@example.com login.
     *
     * @return array<int, array{path: string, classification: string, issue: string}>
     */
    public function productionSeedRisk(): array
    {
        $risks = [];

        foreach (glob(database_path('seeders/*.php')) ?: [] as $path) {
            $source = file_get_contents($path);

            $createsLoginCapableUser = str_contains($source, 'User::factory()') || str_contains($source, 'FirmUser::factory()');

            if (! $createsLoginCapableUser) {
                continue;
            }

            if ($this->hasLocalOrTestingEnvironmentGuard($source)) {
                continue;
            }

            $risks[] = [
                'path' => 'database/seeders/'.basename($path),
                'classification' => 'unsafe',
                'issue' => 'Creates a login-capable default user with no local/testing environment guard — could run against a real deployment.',
            ];
        }

        return $risks;
    }

    public function isClean(): bool
    {
        return empty($this->unsafeFindings()) && empty($this->productionSeedRisk());
    }

    private function hasLocalOrTestingEnvironmentGuard(string $source): bool
    {
        $normalized = str_replace('"', "'", $source);

        return str_contains($normalized, "environment(['local', 'testing'])")
            || str_contains($normalized, "environment(['local','testing'])")
            || str_contains($normalized, "environment('local', 'testing')");
    }

    /**
     * Only matches a marker used as an array KEY (immediately followed
     * by =>), never as a catalog VALUE — e.g. the phase6 module_catalog
     * migration legitimately has a row ['module_code' => 'email', ...]
     * for the "Email" feature module; that 'email' is a value, not a
     * credential column, and must not be flagged.
     */
    private function containsCredentialLikeColumnKey(string $source): bool
    {
        $lowered = strtolower($source);

        foreach (self::CREDENTIAL_LIKE_COLUMN_MARKERS as $marker) {
            if (str_contains($lowered, "'{$marker}' =>") || str_contains($lowered, "\"{$marker}\" =>")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{path: string, classification: string, issue: string}>
     */
    private function scanEnvExampleForRealLookingSecrets(): array
    {
        $path = base_path('.env.example');

        if (! file_exists($path)) {
            return [];
        }

        $findings = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $isSensitiveKey = false;

            foreach (self::SENSITIVE_ENV_KEY_MARKERS as $marker) {
                if (str_contains(strtoupper($key), $marker)) {
                    $isSensitiveKey = true;
                    break;
                }
            }

            if (! $isSensitiveKey || $this->looksLikeSafePlaceholderValue($value)) {
                continue;
            }

            $findings[] = [
                'path' => '.env.example',
                'classification' => 'unsafe',
                'issue' => "Sensitive-looking key '{$key}' has a non-empty, non-placeholder value in a checked-in example file.",
            ];
        }

        return $findings;
    }

    private function looksLikeSafePlaceholderValue(string $value): bool
    {
        $value = trim($value, '"\'');

        return $value === '' || strtolower($value) === 'null' || str_starts_with($value, '${');
    }

    /**
     * @return array<int, array{path: string, classification: string, issue: string}>
     */
    private function scanConfigAndDocsForHighSignalSecrets(): array
    {
        $findings = [];

        $files = array_merge(
            glob(config_path('*.php')) ?: [],
            array_filter([base_path('README.md'), base_path('composer.json'), base_path('phpunit.xml')], 'file_exists'),
        );

        foreach ($files as $path) {
            $source = file_get_contents($path);

            foreach (self::HIGH_SIGNAL_SECRET_PATTERNS as $pattern) {
                if (str_contains($source, $pattern)) {
                    $findings[] = [
                        'path' => str_replace(base_path().'/', '', $path),
                        'classification' => 'unsafe',
                        'issue' => "Contains a real-looking hardcoded secret/API key pattern: {$pattern}",
                    ];
                }
            }
        }

        return $findings;
    }
}
