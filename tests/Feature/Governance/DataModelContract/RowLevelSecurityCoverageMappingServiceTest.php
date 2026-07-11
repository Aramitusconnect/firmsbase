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
        $this->assertArrayHasKey('forced_count', $summary);
        $this->assertArrayHasKey('enforcement_active', $summary);

        $this->assertSame(count($this->service->preparedTables()), $summary['prepared_count']);
        $this->assertSame(count($this->service->tenantOwnedTables()), $summary['tenant_owned_count']);
        $this->assertSame(count($this->service->missingPreparedTables()), $summary['missing_prepared_count']);
        $this->assertSame(count($this->service->forcedTables()), $summary['forced_count']);

        // enforcement_active means "FORCE is active on every prepared
        // table" (schema-wide enforcement) — still honestly false today
        // (18 of 52 prepared tables forced), not a stale hard-coded
        // literal disconnected from any real state.
        $this->assertFalse($summary['enforcement_active']);
        $this->assertLessThan($summary['prepared_count'], $summary['forced_count']);
    }

    public function test_exact_registry_counts_reconcile(): void
    {
        // Locks in the Section 39A-4A.1 registry correction: 18 tables
        // moved from "untracked" into MISSING_PREPARED_TABLES.
        $this->assertCount(52, $this->service->preparedTables());
        $this->assertCount(61, $this->service->missingPreparedTables());
        $this->assertCount(22, $this->service->exemptTables());
        $this->assertCount(113, $this->service->tenantOwnedTables());
        $this->assertCount(18, $this->service->forcedTables());
    }

    public function test_missing_prepared_tables_includes_the_section_39a4a1_registry_gap_tables(): void
    {
        $missing = $this->service->missingPreparedTables();

        foreach ([
            'trust_approval_events', 'document_hashes', 'webhook_deliveries',
            'signature_events', 'webhook_delivery_attempts', 'webhook_secrets',
            'matter_trust_balances', 'accounting_export_lines',
            'customer_success_health_scores', 'email_sync_events',
            'fleet_migration_instance_status', 'form_review_events',
            'generated_document_events', 'implementation_projects', 'matter_expenses',
            'pdf_view_events', 'support_access_requests', 'support_access_sessions',
        ] as $table) {
            $this->assertContains($table, $missing, "{$table} must be tracked in MISSING_PREPARED_TABLES.");
        }
    }

    public function test_forced_tables_is_a_subset_of_prepared_tables(): void
    {
        $forced = $this->service->forcedTables();
        $prepared = $this->service->preparedTables();

        $this->assertNotEmpty($forced);
        $this->assertEmpty(array_diff($forced, $prepared), 'Every forced table must also be a prepared table.');
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

    public function test_is_forced_is_consistent(): void
    {
        $this->assertTrue($this->service->isForced('firm_users'));
        $this->assertTrue($this->service->isPrepared('firm_users'));

        $this->assertFalse($this->service->isForced('firm_settings'));
        $this->assertTrue($this->service->isPrepared('firm_settings'));

        $this->assertFalse($this->service->isForced('does_not_exist'));
    }

    public function test_categories_are_mutually_exclusive(): void
    {
        $prepared = $this->service->preparedTables();
        $missing = $this->service->missingPreparedTables();
        $exempt = $this->service->exemptTables();

        $this->assertEmpty(array_intersect($prepared, $missing));
        $this->assertEmpty(array_intersect($prepared, $exempt));
        $this->assertEmpty(array_intersect($missing, $exempt));
    }

    /**
     * Regression test for the exact gap this section fixed: any
     * application table with its own NOT NULL firm_id column must
     * appear in PREPARED_TABLES, MISSING_PREPARED_TABLES, or
     * EXEMPT_TABLES. A table with no firm_id column at all (indirect
     * ownership, e.g. offboarding_exports) will simply never appear in
     * this diff and therefore needs no special-casing.
     */
    public function test_every_table_with_a_not_null_firm_id_column_is_tracked_in_the_registry(): void
    {
        $rows = \Illuminate\Support\Facades\DB::select(<<<'SQL'
            select c.table_name
            from information_schema.columns c
            join information_schema.tables t
                on t.table_schema = c.table_schema
                and t.table_name = c.table_name
                and t.table_type = 'BASE TABLE'
            where c.table_schema = 'public'
              and c.column_name = 'firm_id'
              and c.is_nullable = 'NO'
            order by c.table_name
            SQL);

        $tablesWithNotNullFirmId = array_map(fn ($row) => $row->table_name, $rows);

        $union = array_merge(
            $this->service->preparedTables(),
            $this->service->missingPreparedTables(),
            $this->service->exemptTables(),
        );

        $untracked = array_values(array_diff($tablesWithNotNullFirmId, $union));

        $this->assertEmpty(
            $untracked,
            'The following tables have their own NOT NULL firm_id column but are absent from '
            .'PREPARED_TABLES/MISSING_PREPARED_TABLES/EXEMPT_TABLES in RowLevelSecurityCoverageMappingService: '
            .implode(', ', $untracked)
        );
    }
}
