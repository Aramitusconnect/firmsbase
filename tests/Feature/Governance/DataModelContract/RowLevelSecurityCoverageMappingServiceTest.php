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

        // FirmsVault Live Integrations Checkpoint 4 ("Plaid financial
        // evidence add-on") — client_portal_users was briefly a known,
        // disclosed transitively-scoped exception here (real FORCE RLS
        // but no direct firm_id column). That design is a confirmed
        // defect (see ClientPortalAuthenticationTest's own docblock)
        // and has been corrected: client_portal_users is now
        // reclassified System, carries no RLS at all, and is no longer
        // forced — so forced_count no longer needs any tolerance above
        // prepared_count. See test_forced_tables_is_a_subset_of_prepared_tables's
        // own docblock for the full corrected reasoning.
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
        // Narrowly updated AGAIN by FirmsVault Live Integrations
        // Checkpoint 2 ("Add Microsoft 365 integration provider") —
        // integration_provider_webhook_subscriptions (a brand-new
        // genuine tenant-owned table, RLS prepared and FORCE-activated
        // in the same migration) added directly to PREPARED_TABLES
        // (125 -> 126); tenantOwnedTables() is the union of both and
        // increases in step (125 -> 126).
        // Narrowly updated AGAIN by FirmsVault Live Integrations
        // Checkpoint 4 ("Plaid financial evidence add-on") — Matter/
        // Client-Portal track — client_portal_matter_grants (a
        // brand-new genuine tenant-owned table, RLS prepared and
        // FORCE-activated in the same migration) added directly to
        // PREPARED_TABLES (126 -> 127); tenantOwnedTables() is the
        // union of both and increases in step (126 -> 127).
        // client_portal_users (the OTHER new table this checkpoint
        // originally added, briefly with FORCE RLS) is DELIBERATELY NOT
        // counted here, and never has been — it has no firm_id column
        // of its own (isolation is transitive through client_id ->
        // clients.firm_id) and is therefore out of scope for
        // PREPARED_TABLES/MISSING_PREPARED_TABLES/EXEMPT_TABLES per this
        // registry's own scope note. It was briefly forced (a confirmed
        // defect — see ClientPortalAuthenticationTest's own docblock)
        // and is now corrected to System classification with no RLS at
        // all, identical treatment to 'users'; forcedTables()'s own
        // dynamic discovery correctly no longer reports it as forced.
        // Narrowly updated AGAIN by the same checkpoint's Plaid
        // provider-core track (financial_evidence_bank_accounts,
        // _transactions, _income_records, _liabilities,
        // _investment_records, _statements, _identity_records: +7) and
        // cost-control track (provider_billable_call_reservations,
        // provider_firm_operation_policies, provider_balance_snapshots:
        // +3) — 127 -> 137. Narrowly updated AGAIN by this checkpoint's
        // Financial Evidence Workspace/Firm-Admin/PlatformAdmin/
        // Client-Portal UI track — ten further brand-new DirectTenant
        // tables (financial_evidence_matter_requests, _client_consents,
        // _matter_authorizations, _matter_notes, _snapshots,
        // _transaction_reviews, _duplicate_transfer_flags,
        // _large_deposit_flags, _reconciliation_candidates,
        // financial_account_reclassification_requests) — 137 -> 147.
        // (client_portal_matter_grants, added by the Matter/Client-Portal
        // foundation track, is ALSO in PREPARED_TABLES, but does not
        // change this specific running total — verified directly against
        // the live registry via reflection: preparedTables() count is
        // 147, not 148, confirming no double-count/omission here.)
        // Native accounting journal (Phase A) added two more DirectTenant
        // tables (accounting_journal_entries, accounting_postings) — 147 -> 149.
        // Payment allocation splitting (Phase F) added one more DirectTenant
        // table (payment_allocations) — 149 -> 150.
        // Payment Link / QR Routing phase added two more DirectTenant
        // tables (payment_requests, payment_request_events) — 154 -> 156.
        // Mixed-Invoice Revenue Allocation pass added one more DirectTenant
        // table (payment_pending_allocations) — 156 -> 157.
        // Event-Driven Automation Engine pass added four more DirectTenant
        // tables (domain_events, automation_rules, automation_executions,
        // automation_action_executions) — 157 -> 161.
        // Predictive Matter Budget Alerts pass added four more DirectTenant
        // tables (matter_budget_templates, matter_budgets,
        // matter_budget_analyses, matter_budget_alerts) — 161 -> 167.
        $this->assertCount(167, $this->service->preparedTables());
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
        // Narrowly updated AGAIN by Phase 2 (FirmsVault Platform Admin
        // Control Center, "Integration Operations Center") —
        // integration_platform_provider_health_summaries, an ordinary
        // "no firm_id" exemption, added to EXEMPT_TABLES (27 -> 28).
        // Narrowly updated AGAIN by FirmsVault Live Integrations
        // Checkpoint 3 (Google Workspace provider —
        // checkpoint3-combined-design.md §5/§6.4.3; checkpoint3-security-review.md
        // Finding 3) — integration_gmail_mailbox_routes added to
        // EXEMPT_TABLES for the identical "genuinely has a firm_id column
        // but is documented-exempt anyway" reason as
        // integration_webhook_routing_index/integration_platform_overview_summaries
        // above (28 -> 29); see EXEMPT_TABLE_METADATA for the full
        // reasoning. This table is classified Global (never DirectTenant),
        // so it does not change tenantOwnedTables() or preparedTables().
        // Narrowly updated AGAIN by the same checkpoint's Plaid
        // provider-core track (integration_plaid_item_routes: +1) and
        // cost-control track (provider_rate_card_entries,
        // provider_kill_switches, provider_operation_default_policies,
        // provider_invoice_reconciliations: +4) — 29 -> 34. Narrowly
        // updated AGAIN by this checkpoint's Financial Evidence
        // Workspace UI track — financial_evidence_large_deposit_thresholds
        // (Global, §1.6's found-and-fixed RLS misclassification) — 34 -> 35.
        // Narrowly updated AGAIN by FirmsVault Live Integrations
        // Checkpoint 8.2 §A4 — provider_operation_attempts, the FK-free
        // durable at-most-once provider-call gate, added to EXEMPT_TABLES
        // (35 -> 36) for the same "genuinely has a firm_id column but is
        // documented-exempt anyway" reason as
        // integration_webhook_routing_index/integration_gmail_mailbox_routes/
        // integration_plaid_item_routes above: it is written on a database
        // session deliberately independent of the calling job's
        // transaction and locks, so a firm-keyed policy would require
        // tenant context on that separate session for every read —
        // including the pre-claim probe that runs before any firm context
        // necessarily exists — reintroducing exactly the cross-session
        // coupling the table exists to eliminate (Checkpoint 8.1 proved
        // that coupling deadlocks against PullSyncJob's lockForUpdate()).
        // Classified Global (never DirectTenant), so it does not change
        // tenantOwnedTables() or preparedTables().
        // Narrowly updated AGAIN by feature/ses-event-consumer —
        // notification_provider_correlations (genuinely has a firm_id
        // column, exempted for the identical pre-tenant-context-routing
        // reason as integration_webhook_routing_index) and
        // ses_event_receipts (an ordinary "no firm_id" exemption) both
        // added to EXEMPT_TABLES (36 -> 38). Both classified Global
        // (never DirectTenant), so tenantOwnedTables()/preparedTables()
        // are unaffected.
        // Narrowly updated AGAIN by post-578ee98 audit remediation
        // (finding H1) — platform_notification_correlations and
        // platform_notification_suppressions, both ordinary "no
        // firm_id" exemptions, added to EXEMPT_TABLES (38 -> 40). Both
        // classified Global, so tenantOwnedTables()/preparedTables()
        // are unaffected.
        $this->assertCount(40, $this->service->exemptTables());
        // Native accounting journal (Phase A) added two more DirectTenant
        // tables (accounting_journal_entries, accounting_postings) — 147 -> 149.
        // Payment allocation splitting (Phase F) added one more DirectTenant
        // table (payment_allocations) — 149 -> 150.
        // Payment Link / QR Routing phase added two more DirectTenant
        // tables (payment_requests, payment_request_events) — 154 -> 156.
        // Mixed-Invoice Revenue Allocation pass added one more DirectTenant
        // table (payment_pending_allocations) — 156 -> 157.
        // Event-Driven Automation Engine pass added four more DirectTenant
        // tables (domain_events, automation_rules, automation_executions,
        // automation_action_executions) — 157 -> 161.
        // Predictive Matter Budget Alerts pass added four more DirectTenant
        // tables (matter_budget_templates, matter_budgets,
        // matter_budget_analyses, matter_budget_alerts) — 161 -> 167.
        $this->assertCount(167, $this->service->tenantOwnedTables());
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

        // FirmsVault Live Integrations Checkpoint 4 ("Plaid financial
        // evidence add-on") — client_portal_users was briefly a known,
        // disclosed exception to this invariant here: it originally
        // carried real FORCE ROW LEVEL SECURITY (a subquery-shaped
        // tenant-isolation policy plus a self-lookup policy) while being
        // intentionally absent from PREPARED_TABLES (no direct firm_id
        // column of its own). That design is a confirmed defect —
        // FORCING RLS on the credential/identity table Auth::attempt()
        // must look up BY EMAIL with no context at all made client login
        // structurally impossible (see ClientPortalAuthenticationTest's
        // own docblock for the full empirical reproduction). It has
        // since been corrected: client_portal_users is now reclassified
        // System (identical treatment to 'users'), carries no RLS at
        // all, and its FORCE RLS migration has been deleted, so
        // forcedTables() no longer reports it. No known
        // transitively-scoped exception remains — every forced table in
        // this repository now has a direct firm_id column and must
        // appear in PREPARED_TABLES with no carve-out.
        $knownTransitivelyScopedForcedTables = [];

        $this->assertNotEmpty($forced);
        $this->assertEmpty(
            array_diff($forced, array_merge($prepared, $knownTransitivelyScopedForcedTables)),
            'Every forced table must also be a prepared table, unless explicitly listed as a known '
            .'transitively-scoped exception above.'
        );
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
        // Narrowly updated AGAIN by FirmsVault Live Integrations
        // Checkpoint 4 ("Plaid financial evidence add-on") — Matter/
        // Client-Portal track — client_portal_matter_grants (a
        // brand-new genuine tenant-owned table, RLS prepared and
        // FORCE-activated in the same migration) added directly to
        // PREPARED_TABLES, so it is classified DirectTenant via
        // fullTableInventory(); the DirectTenant count and the overall
        // table-inventory total both increase by one (126 -> 127).
        // client_portal_users and client_portal_password_reset_tokens
        // (the OTHER two new tables this checkpoint adds) are added
        // directly to FULL_TABLE_INVENTORY_EXTRA instead — neither has
        // a firm_id column. client_portal_users was originally
        // classified InheritedTenant with real FORCE RLS; that design
        // is a confirmed defect (see ClientPortalAuthenticationTest's
        // own docblock) and has been corrected to System, identical
        // treatment to 'users' — so BOTH tables are now classified
        // System, and the System count and overall total increase by
        // two (8 -> 10), while InheritedTenant is unaffected by this
        // checkpoint (stays 24).
        // Narrowly updated AGAIN by the same checkpoint's Plaid
        // provider-core track (+7 financial_evidence_* materializer
        // tables) and cost-control track (+3 provider_billable_call_reservations/
        // provider_firm_operation_policies/provider_balance_snapshots) —
        // 127 -> 137. Narrowly updated AGAIN by this checkpoint's
        // Financial Evidence Workspace/Firm-Admin/PlatformAdmin/
        // Client-Portal UI track — +10 further DirectTenant tables (see
        // test_exact_registry_counts_reconcile's own comment for the
        // full list) — 137 -> 147. (Verified directly against the live
        // registry via reflection: 147, not 148 — client_portal_matter_grants
        // is included in this figure already, see that comment's own note.)
        // Native accounting journal (Phase A) added two more DirectTenant
        // tables (accounting_journal_entries, accounting_postings) — 147 -> 149.
        // Payment allocation splitting (Phase F) added one more DirectTenant
        // table (payment_allocations) — 149 -> 150.
        // Payment Link / QR Routing phase added two more DirectTenant
        // tables (payment_requests, payment_request_events) — 154 -> 156.
        // Mixed-Invoice Revenue Allocation pass added one more DirectTenant
        // table (payment_pending_allocations) — 156 -> 157.
        // Event-Driven Automation Engine pass added four more DirectTenant
        // tables (domain_events, automation_rules, automation_executions,
        // automation_action_executions) — 157 -> 161.
        // Predictive Matter Budget Alerts pass added four more DirectTenant
        // tables (matter_budget_templates, matter_budgets,
        // matter_budget_analyses, matter_budget_alerts) — 161 -> 167.
        $this->assertSame(167, $summary[TenantOwnershipClassification::DirectTenant->value]);
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
        // Narrowly updated by FirmsVault Live Integrations Checkpoint 2
        // ("Add Microsoft 365 integration provider") —
        // integration_provider_webhook_subscriptions (a brand-new
        // genuine tenant-owned table, RLS prepared and FORCE-activated
        // in the same migration) added directly to PREPARED_TABLES, so
        // it is classified DirectTenant via fullTableInventory(); the
        // DirectTenant count and the overall table-inventory total both
        // increase by one (125 -> 126, 226 -> 227).
        // Narrowly updated AGAIN by FirmsVault Live Integrations
        // Checkpoint 3 ("Add Google Workspace integration provider") —
        // integration_gmail_mailbox_routes added directly to
        // FULL_TABLE_INVENTORY_EXTRA, classified Global (per its own
        // "no RLS" disclaimer note, exactly like
        // integration_webhook_routing_index/integration_platform_overview_summaries
        // above — checkpoint3-combined-design.md §5/§6.4.3;
        // checkpoint3-security-review.md Finding 3); the Global count and
        // the overall table-inventory total both increase by one
        // (50 -> 51, 227 -> 228).
        // Narrowly updated AGAIN by the same checkpoint's Plaid
        // provider-core track (integration_plaid_item_routes: +1) and
        // cost-control track (provider_rate_card_entries,
        // provider_kill_switches, provider_operation_default_policies,
        // provider_invoice_reconciliations: +4) — 51 -> 56. Narrowly
        // updated AGAIN by this checkpoint's Financial Evidence
        // Workspace UI track — financial_evidence_large_deposit_thresholds
        // (Global) — 56 -> 57. Narrowly updated AGAIN by FirmsVault Live
        // Integrations Checkpoint 8.2 §A4 — provider_operation_attempts
        // (Global, the FK-free durable at-most-once provider-call gate;
        // see test_exact_registry_counts_reconcile's own comment for the
        // full reasoning) — 57 -> 58. Narrowly updated AGAIN by the
        // Platform Firm Provisioning workflow — firm_provisioning_requests
        // (Global, the idempotency ledger for FirmProvisioningService;
        // platform-wide, requested_by_platform_admin_id-scoped, not
        // firm_id-scoped, mirroring trial_requests' own classification
        // exactly) — 58 -> 59.
        // Narrowly updated AGAIN by feature/ses-event-consumer —
        // notification_provider_correlations and ses_event_receipts,
        // both Global (see FULL_TABLE_INVENTORY_EXTRA's own notes for
        // each) — 59 -> 61.
        // Narrowly updated AGAIN by post-578ee98 audit remediation
        // (finding H1) — platform_notification_correlations and
        // platform_notification_suppressions, both Global — 61 -> 63.
        // Narrowly updated AGAIN by Mission 1B (Extreme Security
        // Hardening) — webauthn_credentials, Global (see above,
        // platform_admin_id-scoped, not firm_id) — 63 -> 64.
        $this->assertSame(64, $summary[TenantOwnershipClassification::Global->value]);
        $this->assertSame(4, $summary[TenantOwnershipClassification::Audit->value]);
        // 9 -> 10: client_portal_users' corrected System classification
        // (see the InheritedTenant/System comment above this method's
        // own client_portal_matter_grants block for the full reasoning).
        // 10 -> 11: Mission 1 (canonical reconstruction) —
        // platform_admin_password_reset_tokens added directly to
        // FULL_TABLE_INVENTORY_EXTRA, classified System (same
        // pre-tenant-context, no-RLS shape/reasoning as
        // client_portal_password_reset_tokens immediately above it in
        // the registry).
        $this->assertSame(11, $summary[TenantOwnershipClassification::System->value]);
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
        // (see above), no other bucket affected. Narrowly updated AGAIN
        // by FirmsVault Live Integrations Checkpoint 2 (226 -> 227) —
        // integration_provider_webhook_subscriptions, classified
        // DirectTenant (see above). Narrowly updated AGAIN by FirmsVault
        // Live Integrations Checkpoint 3 (227 -> 228) —
        // integration_gmail_mailbox_routes, classified Global (see
        // above), no other bucket affected. Narrowly updated AGAIN by
        // FirmsVault Live Integrations Checkpoint 4 ("Plaid financial
        // evidence add-on") — Matter/Client-Portal track —
        // client_portal_matter_grants (DirectTenant), client_portal_users
        // (System — corrected from an original, confirmed-defective
        // InheritedTenant/FORCE-RLS classification; see
        // ClientPortalAuthenticationTest's own docblock), and
        // client_portal_password_reset_tokens (System) all added in the
        // same checkpoint (228 -> 231). The reclassification itself does
        // not change this running total — it moves client_portal_users
        // between buckets (InheritedTenant -> System), not into or out
        // of the inventory.
        // Narrowly updated AGAIN by the same checkpoint's Plaid
        // provider-core track (+7 DirectTenant financial_evidence_*
        // materializer tables, +1 Global integration_plaid_item_routes:
        // 231 -> 239) and cost-control track (+3 DirectTenant
        // provider_billable_call_reservations/provider_firm_operation_policies/
        // provider_balance_snapshots, +4 Global provider_rate_card_entries/
        // provider_kill_switches/provider_operation_default_policies/
        // provider_invoice_reconciliations: 239 -> 246). Narrowly
        // updated AGAIN by this checkpoint's Financial Evidence
        // Workspace/Firm-Admin/PlatformAdmin/Client-Portal UI track —
        // +10 DirectTenant tables, +1 Global
        // (financial_evidence_large_deposit_thresholds): 246 -> 257.
        // Narrowly updated AGAIN by FirmsVault Live Integrations
        // Checkpoint 8.2 §A4 — +1 Global (provider_operation_attempts):
        // 257 -> 258. Narrowly updated AGAIN by the Platform Firm
        // Provisioning workflow — +1 Global (firm_provisioning_requests):
        // 258 -> 259. Narrowly updated AGAIN by feature/ses-event-consumer
        // — +2 Global (notification_provider_correlations,
        // ses_event_receipts): 259 -> 261. Narrowly updated AGAIN by
        // post-578ee98 audit remediation (finding H1) — +2 Global
        // (platform_notification_correlations,
        // platform_notification_suppressions): 261 -> 263. Native
        // accounting journal (Phase A) added two more DirectTenant
        // tables (accounting_journal_entries, accounting_postings):
        // 263 -> 265. Payment allocation splitting (Phase F) added one
        // more DirectTenant table (payment_allocations): 265 -> 266.
        // Phases G through L of the legal-accounting foundation
        // (payment_reversals, invoice_write_offs, accounting_periods)
        // brought the total to 269. The Accounting Integrity Hardening
        // Pass added one more DirectTenant table
        // (accounting_period_events): 269 -> 270. The Payment Link /
        // QR Routing phase added two more DirectTenant tables
        // (payment_requests, payment_request_events): 270 -> 272. No
        // other bucket affected.
        // Mixed-Invoice Revenue Allocation pass added one more DirectTenant
        // table (payment_pending_allocations): 272 -> 273.
        // Event-Driven Automation Engine pass added four more DirectTenant
        // tables: 273 -> 277.
        // Predictive Matter Budget Alerts pass added four more DirectTenant
        // tables: 277 -> 281.
        // Leverage Ratio Optimizer pass added two more DirectTenant
        // tables (task_category_role_expectations,
        // matter_leverage_recommendations): 281 -> 283.
        // Mission 1 (canonical reconstruction) added one more System
        // table (platform_admin_password_reset_tokens): 283 -> 284.
        // Narrowly updated AGAIN by Mission 1B (Extreme Security
        // Hardening) — webauthn_credentials (Global, see above) —
        // 284 -> 285.
        $this->assertSame(285, array_sum($summary));
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
