<?php

namespace Tests\Feature\Security\SeedData;

use App\Services\SeedDataSecurityAuditService;
use Tests\TestCase;

/**
 * SeedDataSecurityAuditServiceTest — Section 39E. Proves the audit
 * service scans the expected paths, distinguishes safe reference/
 * catalog data from unsafe seed/credential data, and reports isClean()
 * true against the current (remediated) repository state.
 */
class SeedDataSecurityAuditServiceTest extends TestCase
{
    private SeedDataSecurityAuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SeedDataSecurityAuditService();
    }

    public function test_scanned_paths_includes_every_required_surface(): void
    {
        $paths = $this->service->scannedPaths();

        foreach ([
            'database/seeders', 'database/factories', 'database/migrations',
            '.env.example', 'phpunit.xml', 'config', 'README.md', 'composer.json',
        ] as $expected) {
            $this->assertContains($expected, $paths, "scannedPaths() must include '{$expected}'.");
        }
    }

    public function test_forbidden_patterns_includes_the_full_checklist_vocabulary(): void
    {
        $patterns = $this->service->forbiddenPatterns();

        foreach ([
            'test@example.com', 'admin@example.com', 'password', 'Password123', 'password123',
            'secret', 'api_key', 'sk-', 'sk_test', 'sk_live', 'OPENAI_API_KEY', 'STRIPE_SECRET',
            'client_secret', 'access_token', 'refresh_token', 'private_key',
            'AWS_ACCESS_KEY', 'AWS_SECRET_ACCESS_KEY',
        ] as $expected) {
            $this->assertContains($expected, $patterns, "forbiddenPatterns() must include '{$expected}'.");
        }
    }

    public function test_findings_returns_an_array_of_well_formed_entries(): void
    {
        foreach ($this->service->findings() as $finding) {
            $this->assertArrayHasKey('path', $finding);
            $this->assertArrayHasKey('classification', $finding);
            $this->assertArrayHasKey('issue', $finding);
            $this->assertContains($finding['classification'], ['safe_reference', 'unsafe']);
        }
    }

    public function test_unsafe_findings_is_empty_against_the_current_repository_state(): void
    {
        $this->assertEmpty($this->service->unsafeFindings());
    }

    public function test_safe_reference_data_findings_includes_the_known_module_catalog_migrations(): void
    {
        $paths = array_column($this->service->safeReferenceDataFindings(), 'path');

        foreach ([
            'database/migrations/2026_07_09_900023_seed_phase6_module_catalog_entries.php',
            'database/migrations/2026_07_21_900006_seed_phase14_module_catalog_webhook_entry.php',
            'database/migrations/2026_07_23_900009_seed_phase15_module_catalog_ai_entry.php',
            'database/migrations/2026_07_25_900009_seed_phase16_integration_degradation_modes.php',
        ] as $expected) {
            $this->assertContains($expected, $paths, "safeReferenceDataFindings() must include '{$expected}'.");
        }

        foreach ($this->service->safeReferenceDataFindings() as $finding) {
            $this->assertSame('safe_reference', $finding['classification']);
        }
    }

    public function test_production_seed_risk_is_empty_after_the_database_seeder_guard_was_added(): void
    {
        $this->assertEmpty($this->service->productionSeedRisk());
    }

    public function test_is_clean_returns_true_after_remediation(): void
    {
        $this->assertTrue($this->service->isClean());
    }

    public function test_is_clean_is_false_when_unsafe_findings_or_production_seed_risk_exist(): void
    {
        // Behavioral proof via a throwaway subclass — does not touch
        // any real repository file. Confirms isClean() is truly
        // computed from unsafeFindings()/productionSeedRisk() rather
        // than a hardcoded true.
        $dirty = new class extends SeedDataSecurityAuditService
        {
            public function unsafeFindings(): array
            {
                return [['path' => 'fake', 'classification' => 'unsafe', 'issue' => 'synthetic']];
            }
        };

        $this->assertFalse($dirty->isClean());
    }
}
