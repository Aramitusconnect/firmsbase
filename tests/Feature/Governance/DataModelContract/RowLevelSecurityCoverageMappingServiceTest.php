<?php

namespace Tests\Feature\Governance\DataModelContract;

use App\Enums\TenantOwnershipClassification;
use App\Services\RowLevelSecurityCoverageMappingService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RowLevelSecurityCoverageMappingServiceTest extends TestCase
{
    private RowLevelSecurityCoverageMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RowLevelSecurityCoverageMappingService;
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
        // Phase 15 AI governance no longer has a spot-check example
        // here: all five of its tables (ai_usage_events, ai_tool_actions,
        // firm_ai_provider_keys, ai_approval_requests, ai_approval_events)
        // were moved into PREPARED_TABLES by Section 39A-5 Wave 3.
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

        // enforcement_active must reflect the current registry state,
        // rather than a historical hard-coded rollout count.
        $this->assertSame(
            $summary['prepared_count'] === $summary['forced_count'],
            $summary['enforcement_active']
        );

        $this->assertLessThanOrEqual(
            $summary['prepared_count'],
            $summary['forced_count']
        );
    }

    public function test_exact_registry_counts_reconcile(): void
    {
        // Locks in the Section 39A-4A.1 registry correction: 18 tables
        // moved from "untracked" into MISSING_PREPARED_TABLES.
        // Narrowly updated by Section 39A-5, Checkpoint 1 —
        // customer_success_health_scores moved from
        // MISSING_PREPARED_TABLES into PREPARED_TABLES (52 -> 53,
        // 61 -> 60); tenantOwnedTables() is the union of both and is
        // unchanged (113).
        // Narrowly updated AGAIN by Section 39A-5 Wave 1 —
        // ai_retrieval_indexes, deployment_configs, and
        // firm_ai_settings moved from MISSING_PREPARED_TABLES into
        // PREPARED_TABLES (53 -> 56, 60 -> 57); tenantOwnedTables()
        // remains unchanged (113).
        // Narrowly updated AGAIN by Section 39A-5 Wave 2 —
        // email_visibility_rules, private_enterprise_settings,
        // matter_expenses, and email_message_links moved from
        // MISSING_PREPARED_TABLES into PREPARED_TABLES (56 -> 60,
        // 57 -> 53); tenantOwnedTables() remains unchanged (113).
        // Narrowly updated AGAIN by Section 39A-5 Wave 3 —
        // ai_usage_events, ai_tool_actions, firm_ai_provider_keys,
        // ai_approval_requests, and ai_approval_events moved from
        // MISSING_PREPARED_TABLES into PREPARED_TABLES (60 -> 65,
        // 53 -> 48); tenantOwnedTables() remains unchanged (113).
        $this->assertCount(76, $this->service->preparedTables());
        $this->assertCount(37, $this->service->missingPreparedTables());
        // 22 original exemptions + the Wave 1A (Section 39A-4B)
        // additions (module_catalog, readiness_scorecard_components) = 24.
        $this->assertCount(24, $this->service->exemptTables());
        $this->assertCount(113, $this->service->tenantOwnedTables());
        $forceMigrationFiles = glob(
            database_path('migrations/*_force_rls_on_*_table.php')
        ) ?: [];

        $this->assertCount(
            count($forceMigrationFiles),
            $this->service->forcedTables()
        );
    }

    public function test_missing_prepared_tables_includes_the_section_39a4a1_registry_gap_tables(): void
    {
        $missing = $this->service->missingPreparedTables();

        // customer_success_health_scores removed from this list by
        // Section 39A-5, Checkpoint 1 — it was moved into
        // PREPARED_TABLES (and given a real RLS policy + FORCE
        // activation) in the same batch, so it is no longer missing.
        // matter_expenses removed from this list by Section 39A-5
        // Wave 2 — it was moved into PREPARED_TABLES (and given a real
        // RLS policy + FORCE activation) in the same batch, so it is
        // no longer missing.
        // accounting_export_lines removed from this list by Section
        // 39A-5 Wave 4 (accounting/expense domain, 7 tables implemented
        // as one combined unit) — it was moved into PREPARED_TABLES
        // (and given a real RLS policy + FORCE activation) in that
        // batch, so it is no longer missing.
        // email_sync_events removed from this list by Section 39A-5
        // Wave 5 (email domain, 4 tables implemented as one combined
        // unit) — it was moved into PREPARED_TABLES (and given a real
        // RLS policy + FORCE activation) in that batch, so it is no
        // longer missing.
        foreach ([
            'trust_approval_events', 'document_hashes', 'webhook_deliveries',
            'signature_events', 'webhook_delivery_attempts', 'webhook_secrets',
            'matter_trust_balances',
            'fleet_migration_instance_status', 'form_review_events',
            'generated_document_events', 'implementation_projects',
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

        $this->assertTrue($this->service->isForced('firm_settings'));
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
        $rows = DB::select(<<<'SQL'
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

    public function test_forced_tables_are_discovered_from_timestamped_force_rls_migrations(): void
    {
        $migrationFiles = glob(
            database_path('migrations/*_force_rls_on_*_table.php')
        ) ?: [];

        $forcedTables = $this->service->forcedTables();

        $this->assertNotEmpty(
            $migrationFiles,
            'Expected timestamp-prefixed FORCE-RLS migrations to exist.'
        );

        $this->assertCount(
            count($migrationFiles),
            $forcedTables,
            'Every FORCE-RLS migration should be represented by forcedTables().'
        );

        $this->assertContains('firm_users', $forcedTables);
    }

    // -----------------------------------------------------------------
    // Wave 1A (Section 39A-4B) canonical 208-table inventory additions.
    // -----------------------------------------------------------------

    public function test_exempt_tables_includes_the_two_wave_1a_additions(): void
    {
        $exempt = $this->service->exemptTables();

        $this->assertContains('module_catalog', $exempt);
        $this->assertContains('readiness_scorecard_components', $exempt);
    }

    public function test_full_table_inventory_contains_every_table_exactly_once(): void
    {
        $inventory = $this->service->fullTableInventory();

        $migrationCreatedTables = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            $source = file_get_contents($path);

            if ($source === false) {
                continue;
            }

            if (preg_match_all("/Schema::create\('([a-z0-9_]+)'/", $source, $matches)) {
                foreach ($matches[1] as $table) {
                    $migrationCreatedTables[] = $table;
                }
            }
        }

        $migrationCreatedTables = array_values(array_unique($migrationCreatedTables));
        sort($migrationCreatedTables);

        $inventoryTables = array_keys($inventory);
        sort($inventoryTables);

        $this->assertSame(
            $migrationCreatedTables,
            $inventoryTables,
            'Every table created by a Schema::create() migration must appear in fullTableInventory() exactly once, and vice versa.'
        );

        // No duplicates snuck in via array key overwriting.
        $this->assertCount(count($migrationCreatedTables), $inventoryTables);
    }

    public function test_full_table_inventory_classification_counts_reconcile_to_208(): void
    {
        $summary = $this->service->classificationSummary();

        $this->assertSame(113, $summary[TenantOwnershipClassification::DirectTenant->value]);
        $this->assertSame(24, $summary[TenantOwnershipClassification::InheritedTenant->value]);
        $this->assertSame(3, $summary[TenantOwnershipClassification::Pivot->value]);
        $this->assertSame(10, $summary[TenantOwnershipClassification::Hybrid->value]);
        $this->assertSame(44, $summary[TenantOwnershipClassification::Global->value]);
        $this->assertSame(4, $summary[TenantOwnershipClassification::Audit->value]);
        $this->assertSame(8, $summary[TenantOwnershipClassification::System->value]);
        $this->assertSame(1, $summary[TenantOwnershipClassification::RootTenant->value]);
        $this->assertSame(1, $summary[TenantOwnershipClassification::Uncertain->value]);

        $this->assertSame(208, array_sum($summary));
    }

    public function test_every_direct_tenant_inherited_hybrid_and_pivot_table_has_a_non_null_ownership_path(): void
    {
        $mustHavePath = [
            TenantOwnershipClassification::DirectTenant,
            TenantOwnershipClassification::InheritedTenant,
            TenantOwnershipClassification::Hybrid,
            TenantOwnershipClassification::Pivot,
        ];

        $violations = [];

        foreach ($this->service->fullTableInventory() as $table => $item) {
            if (in_array($item->classification, $mustHavePath, true) && ($item->ownershipPath === null || $item->ownershipPath === '')) {
                $violations[] = $table;
            }
        }

        $this->assertEmpty($violations, 'Tables missing a required ownership path: '.implode(', ', $violations));
    }

    public function test_firms_is_classified_root_tenant_with_ownership_path_self(): void
    {
        $this->assertSame(
            TenantOwnershipClassification::RootTenant,
            $this->service->classificationOf('firms')
        );
        $this->assertSame('self', $this->service->ownershipPathOf('firms'));
    }

    public function test_offboarding_exports_is_classified_uncertain_with_no_invented_ownership_path(): void
    {
        $this->assertSame(
            TenantOwnershipClassification::Uncertain,
            $this->service->classificationOf('offboarding_exports')
        );
        $this->assertNull($this->service->ownershipPathOf('offboarding_exports'));

        // offboarding_exports must never be silently folded into
        // EXEMPT_TABLES — its ownership is still under investigation.
        $this->assertNotContains('offboarding_exports', $this->service->exemptTables());
    }

    public function test_every_exempt_table_has_non_empty_exempt_metadata(): void
    {
        $metadata = $this->service->exemptTableMetadata();

        $this->assertCount(count($this->service->exemptTables()), $metadata);

        foreach ($metadata as $item) {
            $this->assertNotSame('', trim($item->reason), "{$item->table} must have a non-empty reason.");
            $this->assertNotEmpty($item->expectedReaders, "{$item->table} must have at least one expected reader.");
            $this->assertNotEmpty($item->authorizedWriters, "{$item->table} must have at least one authorized writer.");
        }
    }

    public function test_new_exemptions_have_documented_reason_readers_and_writers(): void
    {
        foreach (['module_catalog', 'readiness_scorecard_components'] as $table) {
            $meta = $this->service->exemptMetadataFor($table);

            $this->assertNotNull($meta, "{$table} must have exempt metadata.");
            $this->assertNotSame('', trim($meta->reason));
            $this->assertNotEmpty($meta->expectedReaders);
            $this->assertNotEmpty($meta->authorizedWriters);
            $this->assertSame(
                TenantOwnershipClassification::Global,
                $this->service->classificationOf($table)
            );
        }
    }

    public function test_prepared_and_missing_tables_are_classified_direct_tenant(): void
    {
        foreach ($this->service->preparedTables() as $table) {
            $this->assertSame(TenantOwnershipClassification::DirectTenant, $this->service->classificationOf($table));
        }

        foreach ($this->service->missingPreparedTables() as $table) {
            $this->assertSame(TenantOwnershipClassification::DirectTenant, $this->service->classificationOf($table));
        }
    }
}
