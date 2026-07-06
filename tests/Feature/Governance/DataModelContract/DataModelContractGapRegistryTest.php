<?php

namespace Tests\Feature\Governance\DataModelContract;

use App\Enums\GovernanceGapSeverity;
use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

/**
 * Regression test: proves Section 26 updated the EXISTING RLS gap's
 * wording rather than creating a second, duplicate RLS gap entry.
 */
class DataModelContractGapRegistryTest extends TestCase
{
    private ComplianceGapRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComplianceGapRegistryService();
    }

    public function test_exactly_seven_gap_items_still_exist(): void
    {
        $this->assertCount(7, $this->service->all());
    }

    public function test_rls_gap_exists(): void
    {
        $this->assertTrue($this->service->isTracked('rls_prepared_not_enforced'));
    }

    public function test_rls_gap_severity_is_still_high(): void
    {
        $item = $this->service->byKey('rls_prepared_not_enforced');

        $this->assertSame(GovernanceGapSeverity::High, $item->severity);
    }

    public function test_rls_gap_notes_mention_missing_preparation_coverage_for_later_phase_tables(): void
    {
        $item = $this->service->byKey('rls_prepared_not_enforced');

        $this->assertStringContainsString('Phase 6', $item->description);
        $this->assertStringContainsString('Phase 7', $item->description);
        $this->assertStringContainsString('RowLevelSecurityCoverageMappingService', $item->description);
    }

    public function test_no_duplicate_rls_gap_exists(): void
    {
        $rlsRelatedKeys = array_filter(
            array_map(fn ($item) => $item->key, $this->service->all()),
            fn (string $key) => str_contains($key, 'rls'),
        );

        $this->assertCount(1, $rlsRelatedKeys, 'Expected exactly one RLS-related gap item, found: '.implode(', ', $rlsRelatedKeys));
    }

    public function test_no_severity_other_than_rls_was_changed(): void
    {
        $this->assertSame(GovernanceGapSeverity::High, $this->service->byKey('firm_user_2fa_missing')->severity);
        $this->assertSame(GovernanceGapSeverity::Medium, $this->service->byKey('client_portal_2fa_missing')->severity);
        $this->assertSame(GovernanceGapSeverity::Medium, $this->service->byKey('login_policy_wrappers_missing')->severity);
        $this->assertSame(GovernanceGapSeverity::Medium, $this->service->byKey('signed_document_url_service_missing')->severity);
        $this->assertSame(GovernanceGapSeverity::Low, $this->service->byKey('real_malware_scanning_engine_stubbed')->severity);
        $this->assertSame(GovernanceGapSeverity::Low, $this->service->byKey('auth_admin_override_events_generic_only')->severity);
    }
}
