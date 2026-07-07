<?php

namespace Tests\Feature\Governance\QualityGates;

use App\Enums\GovernanceGapSeverity;
use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

/**
 * QualityGateGapRegistryTest — proves Section 28 updated the EXISTING
 * RLS gap's wording (no duplicate RLS gap) and added exactly the two
 * conditionally-approved gaps confirmed by AWS inspection: seed-data
 * defaults/test-secrets are unaudited, and restore tests are
 * readiness-only.
 */
class QualityGateGapRegistryTest extends TestCase
{
    private ComplianceGapRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComplianceGapRegistryService();
    }

    public function test_rls_gap_still_exists_and_mentions_section_28_test_coverage_impact(): void
    {
        $item = $this->service->byKey('rls_prepared_not_enforced');

        $this->assertNotNull($item);
        $this->assertStringContainsString('Section 28', $item->description);
        $this->assertStringContainsString('tenant_isolation_broken_scope_caught_by_rls', $item->description);
    }

    public function test_seed_data_gap_exists_because_aws_confirmed_no_seed_audit(): void
    {
        $item = $this->service->byKey('seed_data_defaults_and_test_secrets_not_audited');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceGapSeverity::Medium, $item->severity);
        $this->assertStringContainsString('DatabaseSeeder', $item->description);
    }

    public function test_restore_tests_gap_exists_because_aws_confirmed_readiness_only(): void
    {
        $item = $this->service->byKey('restore_tests_do_not_exercise_real_restore_path');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceGapSeverity::Medium, $item->severity);
        $this->assertStringContainsString('BackupRestoreTestService', $item->description);
    }

    public function test_no_duplicate_gap_keys_exist(): void
    {
        $keys = array_map(fn ($item) => $item->key, $this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate gap key(s) found.');
    }

    public function test_no_duplicate_rls_gap_exists(): void
    {
        $rlsRelatedKeys = array_filter(
            array_map(fn ($item) => $item->key, $this->service->all()),
            fn (string $key) => str_contains($key, 'rls'),
        );

        $this->assertCount(1, $rlsRelatedKeys);
    }

    public function test_exact_gap_count_after_section_28_additions(): void
    {
        // 9 pre-existing (Section 25/26/27) + 2 new Section 28 gaps.
        $this->assertCount(11, $this->service->all());
    }
}
