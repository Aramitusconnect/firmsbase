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

    /**
     * Stage B Checkpoint 9 — integration_usage_records must be tracked
     * as PREPARED (RLS prepared + FORCE-activated in the same
     * migration), isForced() must independently confirm it via the
     * dynamic forcedTables() discovery mechanism (not merely the
     * hardcoded PREPARED_TABLES array), and it must never appear in
     * MISSING_PREPARED_TABLES or EXEMPT_TABLES.
     */
    public function test_integration_usage_records_is_prepared_and_forced_and_never_missing_or_exempt(): void
    {
        $this->assertContains('integration_usage_records', $this->service->preparedTables());
        $this->assertTrue($this->service->isPrepared('integration_usage_records'));
        $this->assertTrue($this->service->isForced('integration_usage_records'));
        $this->assertNotContains('integration_usage_records', $this->service->missingPreparedTables());
        $this->assertNotContains('integration_usage_records', $this->service->exemptTables());
        $this->assertSame(
            TenantOwnershipClassification::DirectTenant,
            $this->service->classificationOf('integration_usage_records')
        );
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

    public function test_missing_prepared_tables_is_now_empty_after_wave_11(): void
    {
        // MISSING_PREPARED_TABLES held tenant-owned tables awaiting RLS
        // preparation throughout this entire rollout. Section 39A-5
        // Wave 11 (webhooks domain, 5 tables) closed the last remaining
        // entries (webhook_subscriptions, webhook_events, webhook_secrets,
        // webhook_deliveries, webhook_delivery_attempts) — this is the
        // FINAL wave of the 60-table rollout, so this array is now
        // genuinely empty. Per the constant's own docblock, an empty
        // array today does not mean it can never be used again — a
        // future, genuinely new tenant-owned table would still need to
        // be added here first.
        $this->assertEmpty($this->service->missingPreparedTables());
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
        // Narrowly updated AGAIN by Section 39A-5 Wave 7 —
        // signature_requests, signature_request_recipients,
        // signature_events, and signature_certificates moved from
        // MISSING_PREPARED_TABLES into PREPARED_TABLES (82 -> 86,
        // 31 -> 27); tenantOwnedTables() remains unchanged (113).
        // Narrowly updated AGAIN by Section 39A-5 Wave 8 —
        // legal_holds, deletion_requests, key_destruction_requests,
        // support_access_requests, support_access_sessions, and
        // deployment_health_checks moved from MISSING_PREPARED_TABLES
        // into PREPARED_TABLES (86 -> 92, 27 -> 21); tenantOwnedTables()
        // remains unchanged (113).
        // Narrowly updated AGAIN by Section 39A-5 Wave 9 —
        // export_jobs, migration_projects, import_batches,
        // implementation_projects, fleet_migration_instance_status, and
        // offboarding_requests moved from MISSING_PREPARED_TABLES into
        // PREPARED_TABLES (92 -> 98, 21 -> 15); tenantOwnedTables()
        // remains unchanged (113).
        // Narrowly updated AGAIN by Section 39A-5 Wave 10 — trust_accounts,
        // trust_ledgers, trust_balances, matter_trust_balances,
        // trust_ledger_entries, trust_approval_events,
        // trust_chargeback_events, trust_reconciliations,
        // trust_refund_requests, and trust_transfer_requests moved from
        // MISSING_PREPARED_TABLES into PREPARED_TABLES (98 -> 108,
        // 15 -> 5); tenantOwnedTables() remains unchanged (113).
        // Narrowly updated AGAIN by Section 39A-5 Wave 11 — the FINAL
        // wave of the 60-table rollout — webhook_subscriptions,
        // webhook_events, webhook_secrets, webhook_deliveries, and
        // webhook_delivery_attempts moved from MISSING_PREPARED_TABLES
        // into PREPARED_TABLES (108 -> 113, 5 -> 0); tenantOwnedTables()
        // remains unchanged (113). MISSING_PREPARED_TABLES is now empty.
        // Narrowly updated by Stage B Checkpoint 3 of the FirmsBase
        // Integration Platform mission — firm_integrations (a brand-new
        // genuine tenant-owned table, RLS prepared and FORCE-activated
        // in the same migration, NOT part of the old 60-table rollout
        // above) added directly to PREPARED_TABLES (113 -> 114);
        // tenantOwnedTables() is the union of both and increases in step
        // (113 -> 114).
        // Narrowly updated AGAIN by Stage B Checkpoint 4 of the FirmsBase
        // Integration Platform mission — integration_credentials (a
        // brand-new genuine tenant-owned table, RLS prepared and
        // FORCE-activated in the same migration) added directly to
        // PREPARED_TABLES (114 -> 115); tenantOwnedTables() is the union
        // of both and increases in step (114 -> 115).
        // Narrowly updated AGAIN by Stage B Checkpoint 5 of the FirmsBase
        // Integration Platform mission — integration_oauth_states (a
        // brand-new genuine tenant-owned table, RLS prepared and
        // FORCE-activated in the same migration) added directly to
        // PREPARED_TABLES (115 -> 116); tenantOwnedTables() is the union
        // of both and increases in step (115 -> 116).
        // Narrowly updated AGAIN by Stage B Checkpoint 6 of the FirmsBase
        // Integration Platform mission ("Transactional Outbox and Sync
        // Persistence Foundation") — integration_sync_runs,
        // integration_sync_items, integration_external_mappings,
        // integration_sync_cursors, integration_conflicts, and
        // integration_outbox_events (six brand-new genuine tenant-owned
        // tables, each RLS prepared and FORCE-activated in its own
        // combined migration) added directly to PREPARED_TABLES
        // (116 -> 122); tenantOwnedTables() is the union of both and
        // increases in step (116 -> 122).
        // Narrowly updated AGAIN by Stage B Checkpoint 7 of the FirmsBase
        // Integration Platform mission ("Inbound Webhook Security") —
        // integration_inbound_webhook_events (a brand-new genuine
        // tenant-owned table, RLS prepared and FORCE-activated in the
        // same migration) added directly to PREPARED_TABLES (122 -> 123);
        // tenantOwnedTables() is the union of both and increases in step
        // (122 -> 123). The other two Checkpoint 7 tables
        // (integration_webhook_receipts, integration_webhook_routing_index)
        // are correctly NOT in PREPARED_TABLES — both are RLS-exempt
        // (see EXEMPT_TABLES and FULL_TABLE_INVENTORY_EXTRA below).
        // Narrowly updated AGAIN by Stage B Checkpoint 8 of the FirmsBase
        // Integration Platform mission — integration_connection_health
        // (a brand-new genuine tenant-owned table, RLS prepared and
        // FORCE-activated in the same migration) added directly to
        // PREPARED_TABLES (123 -> 124); tenantOwnedTables() is the union
        // of both and increases in step (123 -> 124).
        // Narrowly updated AGAIN by Stage B Checkpoint 9 of the FirmsBase
        // Integration Platform mission ("Usage, Audit, Retention, Access,
        // and Governance") — integration_usage_records (a brand-new
        // genuine tenant-owned table, RLS prepared and FORCE-activated in
        // the same migration) added directly to PREPARED_TABLES
        // (124 -> 125); tenantOwnedTables() is the union of both and
        // increases in step (124 -> 125).
        $this->assertCount(125, $this->service->preparedTables());
        $this->assertCount(0, $this->service->missingPreparedTables());
        // 22 original exemptions + the Wave 1A (Section 39A-4B)
        // additions (module_catalog, readiness_scorecard_components) = 24.
        // Narrowly updated by Stage B Checkpoint 2 of the FirmsBase
        // Integration Platform mission — integration_providers (a new
        // Global, no-RLS, seeded-only reference catalog, exactly
        // analogous to module_catalog) added to the exemption registry
        // (24 -> 25).
        // Narrowly updated AGAIN by a Stage B Checkpoint 7
        // registry-classification correction — integration_webhook_routing_index
        // promoted into EXEMPT_TABLES (25 -> 26). Unlike every other
        // EXEMPT_TABLES entry, this table genuinely carries a NOT NULL
        // firm_id column; it is exempt from RLS for a documented,
        // independently-reviewed reason (no secret/credential material;
        // must be queryable pre-tenant-context to bootstrap
        // inbound-webhook identity resolution; its firm_id is a
        // non-authoritative routing pointer, never treated as
        // authoritative on its own — real tenant context is always
        // re-derived under ordinary RLS afterward) rather than because
        // it lacks a firm_id column — see EXEMPT_TABLE_METADATA for the
        // full reasoning. This correction does not change
        // tenantOwnedTables() or preparedTables(): the table was already
        // classified Global (never DirectTenant) both before and after
        // this correction — only its EXEMPT_TABLES bookkeeping
        // membership changes.
        // Narrowly updated AGAIN by Stage B Checkpoint 11 of the
        // FirmsBase Integration Platform mission — integration_platform_
        // overview_summaries (a brand-new Global, no-RLS, sole-writer
        // summary table backing the SuperAdmin overview list) added to
        // EXEMPT_TABLES for the same "genuinely has a firm_id column but
        // is documented-exempt anyway" reason as
        // integration_webhook_routing_index above (26 -> 27); see
        // EXEMPT_TABLE_METADATA for the full reasoning. This table is
        // classified Global (never DirectTenant), so it does not change
        // tenantOwnedTables() or preparedTables().
        $this->assertCount(28, $this->service->exemptTables());
        $this->assertCount(125, $this->service->tenantOwnedTables());
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
        // document_hashes, form_review_events, generated_document_events,
        // and pdf_view_events removed from this list by Section 39A-5
        // Wave 6 (documents/forms domain, 6 tables implemented as one
        // combined unit) — each was moved into PREPARED_TABLES (and
        // given a real RLS policy + FORCE activation) in that batch, so
        // none is any longer missing.
        // signature_events removed from this list by Section 39A-5
        // Wave 7 (e-signature domain, 4 tables implemented as one
        // combined unit) — it was moved into PREPARED_TABLES (and given
        // a real RLS policy + FORCE activation) in that batch, so it is
        // no longer missing.
        // support_access_requests and support_access_sessions removed
        // from this list by Section 39A-5 Wave 8 (governance/support/
        // platform domain, 6 tables implemented as one combined unit)
        // — both moved into PREPARED_TABLES (and given a real RLS
        // policy + FORCE activation) in that batch, so neither is any
        // longer missing.
        // fleet_migration_instance_status and implementation_projects
        // removed from this list by Section 39A-5 Wave 9 (migration/
        // export domain, 6 tables implemented as one combined unit) —
        // both moved into PREPARED_TABLES (and given a real RLS policy
        // + FORCE activation) in that batch, so neither is any longer
        // missing.
        // trust_approval_events and matter_trust_balances removed from
        // this list by Section 39A-5 Wave 10 (trust accounting domain,
        // 10 tables implemented as one combined unit) — both moved into
        // PREPARED_TABLES (and given a real RLS policy + FORCE
        // activation) in that batch, so neither is any longer missing.
        // webhook_deliveries, webhook_delivery_attempts, and
        // webhook_secrets — the last remaining entries this test ever
        // spot-checked — removed from this list by Section 39A-5 Wave 11
        // (webhooks domain, 5 tables implemented as one combined unit,
        // the FINAL wave of the 60-table rollout). MISSING_PREPARED_TABLES
        // is now empty; see test_missing_prepared_tables_is_now_empty_after_wave_11().
        $this->assertEmpty($this->service->missingPreparedTables());
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

        // webhook_events moved from missing to prepared in Section
        // 39A-5 Wave 11 (the final wave) — MISSING_PREPARED_TABLES is
        // now empty, so there is no longer a live example of isMissing()
        // returning true for a real tenant-owned table. The underlying
        // check (a plain in_array() against an empty array) needs no
        // positive example to remain correct.
        $this->assertTrue($this->service->isPrepared('webhook_events'));
        $this->assertFalse($this->service->isMissing('webhook_events'));

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

        // Narrowly updated by Stage B Checkpoint 3 of the FirmsBase
        // Integration Platform mission — firm_integrations (a brand-new
        // genuine tenant-owned table, RLS prepared and FORCE-activated
        // in the same migration) added directly to PREPARED_TABLES, so
        // it is classified DirectTenant via fullTableInventory(); the
        // DirectTenant count and the overall table-inventory total both
        // increase by one (113 -> 114, 209 -> 210).
        // Narrowly updated AGAIN by Stage B Checkpoint 4 of the FirmsBase
        // Integration Platform mission — integration_credentials (a
        // brand-new genuine tenant-owned table, RLS prepared and
        // FORCE-activated in the same migration) added directly to
        // PREPARED_TABLES, so it is classified DirectTenant via
        // fullTableInventory(); the DirectTenant count and the overall
        // table-inventory total both increase by one (114 -> 115,
        // 210 -> 211).
        // Narrowly updated AGAIN by Stage B Checkpoint 5 of the FirmsBase
        // Integration Platform mission — integration_oauth_states (a
        // brand-new genuine tenant-owned table, RLS prepared and
        // FORCE-activated in the same migration) added directly to
        // PREPARED_TABLES, so it is classified DirectTenant via
        // fullTableInventory(); the DirectTenant count and the overall
        // table-inventory total both increase by one (115 -> 116,
        // 211 -> 212).
        // Narrowly updated AGAIN by Stage B Checkpoint 6 of the FirmsBase
        // Integration Platform mission — integration_sync_runs,
        // integration_sync_items, integration_external_mappings,
        // integration_sync_cursors, integration_conflicts, and
        // integration_outbox_events (six brand-new genuine tenant-owned
        // tables, each RLS prepared and FORCE-activated in its own
        // combined migration) added directly to PREPARED_TABLES, so each
        // is classified DirectTenant via fullTableInventory(); the
        // DirectTenant count and the overall table-inventory total both
        // increase by six (116 -> 122, 212 -> 218).
        // Narrowly updated AGAIN by Stage B Checkpoint 7 of the FirmsBase
        // Integration Platform mission ("Inbound Webhook Security") —
        // integration_inbound_webhook_events (a brand-new genuine
        // tenant-owned table, RLS prepared and FORCE-activated in the
        // same migration) added directly to PREPARED_TABLES, so it is
        // classified DirectTenant via fullTableInventory(); the
        // DirectTenant count and the overall table-inventory total both
        // increase by one (122 -> 123, 218 -> 219).
        // Narrowly updated AGAIN by Stage B Checkpoint 8 of the
        // FirmsBase Integration Platform mission —
        // integration_connection_health (a brand-new genuine
        // tenant-owned table, RLS prepared and FORCE-activated in the
        // same migration) added directly to PREPARED_TABLES, so it is
        // classified DirectTenant via fullTableInventory(); the
        // DirectTenant count and the overall table-inventory total both
        // increase by one (123 -> 124, 219 -> 220).
        // Narrowly updated AGAIN by Stage B Checkpoint 9 of the
        // FirmsBase Integration Platform mission ("Usage, Audit,
        // Retention, Access, and Governance") — integration_usage_records
        // (a brand-new genuine tenant-owned table, RLS prepared and
        // FORCE-activated in the same migration) added directly to
        // PREPARED_TABLES, so it is classified DirectTenant via
        // fullTableInventory(); the DirectTenant count and the overall
        // table-inventory total both increase by one (124 -> 125,
        // 220 -> 221).
        $this->assertSame(125, $summary[TenantOwnershipClassification::DirectTenant->value]);
        $this->assertSame(24, $summary[TenantOwnershipClassification::InheritedTenant->value]);
        $this->assertSame(3, $summary[TenantOwnershipClassification::Pivot->value]);
        $this->assertSame(10, $summary[TenantOwnershipClassification::Hybrid->value]);
        // Narrowly updated by Stage B Checkpoint 2 of the FirmsBase
        // Integration Platform mission — integration_providers (a new
        // Global, no-RLS, seeded-only reference catalog, exactly
        // analogous to module_catalog) added to the exemption registry;
        // it is classified Global, so the Global count and the overall
        // table-inventory total both increase by one (44 -> 45,
        // 208 -> 209).
        // Narrowly updated AGAIN by Stage B Checkpoint 7 of the FirmsBase
        // Integration Platform mission — integration_webhook_routing_index
        // and integration_webhook_receipts (both Global in
        // FULL_TABLE_INVENTORY_EXTRA, per each table's own "no RLS"
        // disclaimer note) added directly to that inventory; the Global
        // count and the overall table-inventory total both increase by
        // two (45 -> 47, 219 -> 221). NOTE: integration_webhook_routing_index's
        // subsequent promotion into EXEMPT_TABLES (a registry-bookkeeping
        // correction — see test_exact_registry_counts_reconcile) does
        // NOT change its classification here: it was Global before that
        // correction and remains Global after it, since EXEMPT_TABLES
        // membership and FULL_TABLE_INVENTORY_EXTRA classification are
        // tracked independently and classificationSummary() only reads
        // the latter.
        // Narrowly updated AGAIN by Stage B Checkpoint 11 of the
        // FirmsBase Integration Platform mission —
        // integration_platform_overview_summaries added directly to
        // FULL_TABLE_INVENTORY_EXTRA, classified Global (per its own
        // "no RLS" disclaimer note, exactly like
        // integration_webhook_routing_index/integration_webhook_receipts
        // above); the Global count and the overall table-inventory total
        // both increase by one (47 -> 48, 223 -> 224).
        // Narrowly updated AGAIN by Phase 2 of the FirmsVault Platform
        // Admin Control Center mission ("Integration Operations
        // Center") — integration_platform_provider_health_summaries
        // added directly to FULL_TABLE_INVENTORY_EXTRA, classified
        // Global (an ordinary "no firm_id" exemption — unlike
        // integration_platform_overview_summaries immediately above,
        // this table carries no firm_id column at all); the Global
        // count and the overall table-inventory total both increase by
        // one (48 -> 49, 224 -> 225).
        // Narrowly updated by FirmsVault Live Integrations Checkpoint 1
        // ("Harden FirmsVault live-provider runtime") —
        // integration_webhook_verification_failures added directly to
        // FULL_TABLE_INVENTORY_EXTRA, classified Global (a platform,
        // pre-tenant counter table structurally incapable of holding a
        // tenant-identifying column, per its own "no RLS" disclaimer
        // note — same reasoning as integration_webhook_receipts
        // immediately above it in the registry); the Global count and
        // the overall table-inventory total both increase by one
        // (49 -> 50, 225 -> 226).
        $this->assertSame(50, $summary[TenantOwnershipClassification::Global->value]);
        $this->assertSame(4, $summary[TenantOwnershipClassification::Audit->value]);
        $this->assertSame(8, $summary[TenantOwnershipClassification::System->value]);
        $this->assertSame(1, $summary[TenantOwnershipClassification::RootTenant->value]);
        $this->assertSame(1, $summary[TenantOwnershipClassification::Uncertain->value]);

        // Overall total increases in step with the DirectTenant bump
        // above (221 -> 222) — Stage B Checkpoint 8 adds exactly one
        // new table to the inventory (integration_connection_health),
        // classified DirectTenant, with no other classification bucket
        // affected. Narrowly updated AGAIN by Stage B Checkpoint 9
        // (222 -> 223) — integration_usage_records, same reasoning,
        // classified DirectTenant, no other bucket affected. Narrowly
        // updated AGAIN by Stage B Checkpoint 11 (223 -> 224) —
        // integration_platform_overview_summaries, classified Global (see
        // above), no other bucket affected. Narrowly updated AGAIN by
        // Phase 2 (224 -> 225) — integration_platform_provider_health_summaries,
        // classified Global (see above), no other bucket affected.
        // Narrowly updated AGAIN by FirmsVault Live Integrations
        // Checkpoint 1 (225 -> 226) —
        // integration_webhook_verification_failures, classified Global
        // (see above), no other bucket affected.
        $this->assertSame(226, array_sum($summary));
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
