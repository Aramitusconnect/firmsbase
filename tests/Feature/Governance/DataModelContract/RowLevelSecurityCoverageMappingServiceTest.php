<?php

namespace Tests\Feature\Governance\DataModelContract;

use App\Services\RowLevelSecurityCoverageMappingService;
use Tests\TestCase;

class RowLevelSecurityCoverageMappingServiceTest extends TestCase
{
    private RowLevelSecurityCoverageMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RowLevelSecurityCoverageMappingService();
    }

    public function test_prepared_tables_is_non_empty(): void
    {
        $this->assertNotEmpty($this->service->preparedTables());
        $this->assertContains('firm_settings', $this->service->preparedTables());
        $this->assertContains('matters', $this->service->preparedTables());
    }

    public function test_tenant_owned_tables_is_non_empty(): void
    {
        $this->assertNotEmpty($this->service->tenantOwnedTables());
    }

    public function test_tenant_owned_tables_is_the_union_of_prepared_and_missing(): void
    {
        $union = array_unique(array_merge(
            $this->service->preparedTables(),
            $this->service->missingPreparedTables(),
        ));

        sort($union);
        $tenantOwned = $this->service->tenantOwnedTables();
        sort($tenantOwned);

        $this->assertSame($union, $tenantOwned);
    }

    public function test_missing_prepared_tables_is_non_empty(): void
    {
        $this->assertNotEmpty($this->service->missingPreparedTables());
    }

    public function test_missing_prepared_tables_includes_known_later_phase_tenant_owned_tables(): void
    {
        $missing = $this->service->missingPreparedTables();

        // Phase 12 trust accounting.
        $this->assertContains('trust_ledger_entries', $missing);
        $this->assertContains('trust_accounts', $missing);
        // Phase 14 webhooks.
        $this->assertContains('webhook_events', $missing);
        $this->assertContains('webhook_subscriptions', $missing);
        // Phase 15 AI governance.
        $this->assertContains('ai_usage_events', $missing);
        $this->assertContains('firm_ai_provider_keys', $missing);
        // Phase 17 governance.
        $this->assertContains('legal_holds', $missing);
        $this->assertContains('deletion_requests', $missing);
    }

    public function test_exempt_tables_includes_global_commercial_and_reference_tables(): void
    {
        $exempt = $this->service->exemptTables();

        $this->assertContains('organizations', $exempt);
        $this->assertContains('billing_accounts', $exempt);
        $this->assertContains('plans', $exempt);
        $this->assertContains('practice_areas', $exempt);
    }

    public function test_exempt_and_missing_tables_never_overlap(): void
    {
        $overlap = array_intersect($this->service->exemptTables(), $this->service->missingPreparedTables());

        $this->assertEmpty($overlap);
    }

    public function test_prepared_and_missing_tables_never_overlap(): void
    {
        $overlap = array_intersect($this->service->preparedTables(), $this->service->missingPreparedTables());

        $this->assertEmpty($overlap);
    }

    public function test_coverage_summary_includes_the_required_keys(): void
    {
        $summary = $this->service->coverageSummary();

        $this->assertArrayHasKey('prepared_count', $summary);
        $this->assertArrayHasKey('tenant_owned_count', $summary);
        $this->assertArrayHasKey('missing_prepared_count', $summary);
        $this->assertArrayHasKey('enforcement_active', $summary);

        $this->assertSame(count($this->service->preparedTables()), $summary['prepared_count']);
        $this->assertSame(count($this->service->tenantOwnedTables()), $summary['tenant_owned_count']);
        $this->assertSame(count($this->service->missingPreparedTables()), $summary['missing_prepared_count']);
        $this->assertFalse($summary['enforcement_active']);
    }

    public function test_is_prepared_and_is_missing_are_consistent(): void
    {
        $this->assertTrue($this->service->isPrepared('firm_settings'));
        $this->assertFalse($this->service->isMissing('firm_settings'));

        $this->assertTrue($this->service->isMissing('webhook_events'));
        $this->assertFalse($this->service->isPrepared('webhook_events'));

        $this->assertFalse($this->service->isPrepared('does_not_exist'));
        $this->assertFalse($this->service->isMissing('does_not_exist'));
    }
}
