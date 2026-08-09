<?php

namespace App\Services;

use App\Enums\TenantOwnershipClassification;
use App\ValueObjects\ExemptTableMetadata;
use App\ValueObjects\TenantTableInventoryItem;

/**
 * RowLevelSecurityCoverageMappingService — a static, declarative
 * registry of which tenant-owned tables have PostgreSQL row-level
 * security PREPARED (ENABLE ROW LEVEL SECURITY + CREATE POLICY),
 * which are intentionally exempt (global/organization/platform-level,
 * no firm tenancy boundary), and which tenant-owned tables are still
 * MISSING that preparation. Built entirely from direct inspection of
 * database/migrations and app/Models — this service never introspects
 * the live database, never runs SQL, and never activates RLS itself.
 *
 * RLS enforcement (FORCE ROW LEVEL SECURITY + SET LOCAL
 * app.current_firm_id session middleware) is PARTIALLY active: as of
 * Section 39A-3K, FORCE ROW LEVEL SECURITY had been activated on 18 of
 * the 52 prepared tables, and further sections keep forcing more of
 * them one batch at a time. Rather than hardcode a count that a
 * still-active rollout would immediately outdate again,
 * forcedTables() below is derived at call time from every
 * database/migrations/*_force_rls_on_*_table.php migration present in
 * the repository — see forcedTables() for how. SET LOCAL
 * app.current_firm_id wiring (TenantContextService) is exercised by
 * every forced table's write paths. This is real, partial enforcement
 * — it is NOT schema-wide: prepared tables that have no FORCE
 * migration yet are still inert for the app's own database connection
 * (table-owner role is exempt from non-forced RLS), and the currently
 * inventoried unprepared tables in MISSING_PREPARED_TABLES have no RLS
 * policy of any kind yet — see missingPreparedTables() for the live
 * count, rather than hardcoding it here where it would go stale as
 * MISSING_PREPARED_TABLES changes. Do not read this docblock as "RLS
 * is fully enforced" — it is not.
 *
 * Source of truth: the 6 RLS-preparation migrations
 * (2026_07_04_500001 through 2026_07_09_900024) cover only Phases 1-6.
 * No RLS-preparation migration exists for anything after Phase 6 —
 * every tenant-owned table introduced from Phase 7 onward (email,
 * forms, e-signature, accounting/expenses, trust accounting, webhooks,
 * AI governance, Phase 16/17 deployment/license/governance tables,
 * plus the 18 tables added to MISSING_PREPARED_TABLES by Section
 * 39A-4A.1 after an inventory sweep found them absent from this
 * registry despite meeting the identical direct-firm_id evidentiary
 * bar as every other entry) has no RLS policy at all.
 *
 * Scope boundary: this registry tracks only tables with their own
 * firm_id column. Indirectly tenant-owned tables (ownership via a
 * foreign key to a tenant-scoped parent row, e.g. offboarding_exports
 * -> offboarding_requests.firm_id, deletion_approvals ->
 * deletion_requests.firm_id, key_destruction_approvals ->
 * key_destruction_requests.firm_id) are intentionally out of scope for
 * PREPARED_TABLES/MISSING_PREPARED_TABLES/EXEMPT_TABLES — they cannot
 * take the standard `firm_id = current_setting(...)` policy template
 * used throughout this registry and would instead require a
 * structurally different EXISTS-against-parent policy design, which is
 * a separate, unaddressed architectural question, not silently folded
 * in here.
 *
 * Wave 1A addition (Section 39A-4B): the narrow firm_id-scoped registry
 * above (PREPARED_TABLES/MISSING_PREPARED_TABLES/EXEMPT_TABLES) is
 * unchanged in shape and existing values — nothing above this point
 * was rewritten. fullTableInventory() below is a wholly additive,
 * wider structure sitting on top of it: it classifies literally every
 * one of the repository's 208 Schema::create'd tables (see every
 * database/migrations/*.php file) with a
 * TenantOwnershipClassification, an ownership path (the FK chain that
 * establishes tenant ownership, "self" for the root case, or null/
 * unresolved for Uncertain), and a short note. Every DirectTenant
 * table below is exactly PREPARED_TABLES union MISSING_PREPARED_TABLES
 * (113 tables); every Global table below includes all of EXEMPT_TABLES
 * (24, after this section's two additions) plus 20 further
 * platform-wide tables this section classified but did NOT add to
 * EXEMPT_TABLES (out of scope for this section — EXEMPT_TABLES is
 * reserved for tables a human has explicitly approved as an RLS
 * exemption with documented reason/readers/writers, not merely
 * "classified Global"). `firms` is RootTenant with ownership path
 * "self" (see its own entry below for its eventual RLS predicate).
 * `offboarding_exports` is Uncertain and deliberately carries no
 * ownership path — see its entry for why.
 */
class RowLevelSecurityCoverageMappingService
{
    /**
     * Tables with ENABLE ROW LEVEL SECURITY + a firm_id-matching
     * CREATE POLICY, per the 6 RLS-preparation migrations (Phases 1-6).
     *
     * @var array<int, string>
     */
    private const PREPARED_TABLES = [
        // Phase 1 — 2026_07_04_500001_prepare_row_level_security_for_tenant_tables.php
        'firm_settings', 'firm_users', 'security_events', 'firm_licenses',
        'firm_entitlements', 'firm_entitlement_events', 'activation_checklists',
        'tenant_encryption_keys', 'client_communication_preferences',
        'communication_consents', 'communication_consent_events',
        // Phase 2 — 2026_07_05_600024_extend_row_level_security_to_phase_2_tenant_tables.php
        'lead_sources', 'consultation_outcomes', 'clients', 'firm_leads',
        'consultations', 'contacts', 'parties', 'matters', 'firm_practice_areas',
        'installed_template_packs', 'intake_submissions', 'conflict_check_runs',
        'timeline_events',
        // Phase 3 — 2026_07_06_700012_extend_row_level_security_to_phase_3_tenant_tables.php
        'employee_rates', 'time_tracking_sessions', 'time_entries', 'invoices',
        'payment_plans', 'payments', 'payment_plan_events', 'payment_classification_events',
        // Phase 4 — 2026_07_07_800016_extend_row_level_security_to_phase_4_tenant_tables.php
        'documents', 'document_requests', 'tasks', 'deadlines', 'calendar_events',
        'notification_events', 'notification_templates', 'document_chase_rules',
        'document_chase_events', 'matter_readiness_scores', 'readiness_score_events',
        // Phase 5 — 2026_07_08_900008_extend_row_level_security_to_phase_5_tenant_tables.php
        'firm_activation_events', 'health_checks', 'backup_restore_tests',
        'incident_events', 'maintenance_windows', 'pilot_feedback_items',
        // Phase 6 — 2026_07_09_900024_extend_row_level_security_to_phase_6_tenant_tables.php
        'seat_allocations', 'template_upgrade_previews', 'template_upgrade_logs',
        // Section 39A-5, Checkpoint 1 — 2026_08_26_940001_prepare_row_level_security_and_force_rls_on_customer_success_health_scores_table.php.
        // Unlike every table above, this one had NO prior RLS preparation
        // migration; the policy was created and forced in the same
        // migration (see that migration's own docblock). Moved here from
        // MISSING_PREPARED_TABLES below, not merely appended — the table
        // no longer belongs in that array at all.
        'customer_success_health_scores',
        // Section 39A-5 Wave 1 (this batch) — same combined
        // prepare+force shape as Checkpoint 1 above, three independent
        // tables activated together as the first parallelizable wave
        // of the 60-table 39A-5 rollout:
        // 2026_08_27_950001_prepare_row_level_security_and_force_rls_on_ai_retrieval_indexes_table.php,
        // 2026_08_27_950002_prepare_row_level_security_and_force_rls_on_deployment_configs_table.php,
        // 2026_08_27_950003_prepare_row_level_security_and_force_rls_on_firm_ai_settings_table.php.
        // All three moved here from MISSING_PREPARED_TABLES below.
        'ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings',
        // Section 39A-5 Wave 2 — same combined prepare+force shape,
        // four independent tables activated together:
        // 2026_08_27_950004_prepare_row_level_security_and_force_rls_on_email_message_links_table.php,
        // 2026_08_27_950005_prepare_row_level_security_and_force_rls_on_email_visibility_rules_table.php,
        // 2026_08_27_950011_prepare_row_level_security_and_force_rls_on_private_enterprise_settings_table.php,
        // 2026_08_27_950012_prepare_row_level_security_and_force_rls_on_matter_expenses_table.php.
        // All four moved here from MISSING_PREPARED_TABLES below.
        'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links',
        // Section 39A-5 Wave 3 (AI governance domain) — five tables:
        // four independent (ai_usage_events, ai_tool_actions,
        // firm_ai_provider_keys) plus a combined pair
        // (ai_approval_requests, ai_approval_events) implemented as
        // one unit since both are written exclusively by
        // AiApprovalWorkflowService:
        // 2026_08_27_950013_prepare_row_level_security_and_force_rls_on_ai_usage_events_table.php,
        // 2026_08_27_950014_prepare_row_level_security_and_force_rls_on_ai_tool_actions_table.php,
        // 2026_08_27_950015_prepare_row_level_security_and_force_rls_on_firm_ai_provider_keys_table.php,
        // 2026_08_27_950016_prepare_row_level_security_and_force_rls_on_ai_approval_requests_table.php,
        // 2026_08_27_950017_prepare_row_level_security_and_force_rls_on_ai_approval_events_table.php.
        // All five moved here from MISSING_PREPARED_TABLES below.
        'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events',
        // Section 39A-5 Wave 4 (accounting/expense domain) — seven
        // tables implemented and committed as ONE combined unit (not
        // seven independent checkpoints), since Phase 1/2 read-only
        // analysis confirmed they share a single writer group with no
        // safe split point (AccountingExportLineBuilderService::
        // buildForBatch() alone touches 3 of the 7 in one call path):
        // 2026_08_27_950018_prepare_row_level_security_and_force_rls_on_chart_of_accounts_table.php,
        // 2026_08_27_950019_prepare_row_level_security_and_force_rls_on_expense_categories_table.php,
        // 2026_08_27_950020_prepare_row_level_security_and_force_rls_on_expenses_table.php,
        // 2026_08_27_950021_prepare_row_level_security_and_force_rls_on_expense_receipts_table.php,
        // 2026_08_27_950022_prepare_row_level_security_and_force_rls_on_expense_approvals_table.php,
        // 2026_08_27_950023_prepare_row_level_security_and_force_rls_on_accounting_export_batches_table.php,
        // 2026_08_27_950024_prepare_row_level_security_and_force_rls_on_accounting_export_lines_table.php.
        // All seven moved here from MISSING_PREPARED_TABLES below.
        'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts',
        'expense_approvals', 'accounting_export_batches', 'accounting_export_lines',
        // Section 39A-5 Wave 5 (email domain) — four tables implemented
        // and committed as ONE combined unit (not four independent
        // checkpoints), since Phase 1/2 read-only analysis confirmed
        // they share a single writer group with no safe split point
        // (EmailSyncService::sync()/captureMessage() alone touches 3-4
        // of the 4 in one un-transacted call path):
        // 2026_08_27_950025_prepare_row_level_security_and_force_rls_on_email_accounts_table.php,
        // 2026_08_27_950026_prepare_row_level_security_and_force_rls_on_email_messages_table.php,
        // 2026_08_27_950027_prepare_row_level_security_and_force_rls_on_email_attachments_table.php,
        // 2026_08_27_950028_prepare_row_level_security_and_force_rls_on_email_sync_events_table.php.
        // All four moved here from MISSING_PREPARED_TABLES below.
        'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events',
        // Section 39A-5 Wave 6 (documents/forms domain) — six tables
        // implemented and committed as ONE combined unit (not six
        // independent checkpoints), since Phase 1/2 read-only analysis
        // confirmed two tightly-coupled review-workflow pairs
        // (form_drafts+form_review_events via FormReviewService;
        // generated_documents+generated_document_events via
        // DocumentReviewService) plus two looser singletons
        // (document_hashes, pdf_view_events) with cross-domain read
        // dependencies on generated_documents:
        // 2026_08_27_950029_prepare_row_level_security_and_force_rls_on_generated_documents_table.php,
        // 2026_08_27_950030_prepare_row_level_security_and_force_rls_on_form_drafts_table.php,
        // 2026_08_27_950031_prepare_row_level_security_and_force_rls_on_generated_document_events_table.php,
        // 2026_08_27_950032_prepare_row_level_security_and_force_rls_on_form_review_events_table.php,
        // 2026_08_27_950033_prepare_row_level_security_and_force_rls_on_document_hashes_table.php,
        // 2026_08_27_950034_prepare_row_level_security_and_force_rls_on_pdf_view_events_table.php.
        // All six moved here from MISSING_PREPARED_TABLES below.
        'generated_documents', 'form_drafts', 'generated_document_events',
        'form_review_events', 'document_hashes', 'pdf_view_events',
        // Section 39A-5 Wave 7 (e-signature domain) — four tables
        // implemented and committed as ONE combined unit (not four
        // independent checkpoints), since Phase 1/2 read-only analysis
        // confirmed they all originate from a single prior commit and
        // SignatureCertificateService::generate() alone touches
        // signature_events and signature_certificates in one call path:
        // 2026_08_27_950035_prepare_row_level_security_and_force_rls_on_signature_requests_table.php,
        // 2026_08_27_950036_prepare_row_level_security_and_force_rls_on_signature_request_recipients_table.php,
        // 2026_08_27_950037_prepare_row_level_security_and_force_rls_on_signature_events_table.php,
        // 2026_08_27_950038_prepare_row_level_security_and_force_rls_on_signature_certificates_table.php.
        // All four moved here from MISSING_PREPARED_TABLES below.
        'signature_requests', 'signature_request_recipients', 'signature_events', 'signature_certificates',
        // Section 39A-5 Wave 8 (governance/support/platform domain) —
        // six tables implemented and committed as ONE combined unit
        // (not six independent checkpoints), since Phase 1/2 read-only
        // analysis confirmed legal_holds is a shared clearance-gate
        // read dependency for both deletion_requests and
        // key_destruction_requests, and support_access_requests/
        // support_access_sessions share a direct parent-child writer
        // relationship (closed with a real composite FK, not just
        // documented):
        // 2026_08_28_960001_prepare_row_level_security_and_force_rls_on_legal_holds_table.php,
        // 2026_08_28_960002_prepare_row_level_security_and_force_rls_on_deletion_requests_table.php,
        // 2026_08_28_960003_prepare_row_level_security_and_force_rls_on_key_destruction_requests_table.php,
        // 2026_08_28_960004_prepare_row_level_security_and_force_rls_on_support_access_requests_table.php,
        // 2026_08_28_960005_prepare_row_level_security_and_force_rls_on_support_access_sessions_table.php,
        // 2026_08_28_960006_prepare_row_level_security_and_force_rls_on_deployment_health_checks_table.php.
        // All six moved here from MISSING_PREPARED_TABLES below.
        'legal_holds', 'deletion_requests', 'key_destruction_requests',
        'support_access_requests', 'support_access_sessions', 'deployment_health_checks',
        // Section 39A-5 Wave 9 (migration/export domain) — six tables
        // implemented and committed as ONE combined unit (not six
        // independent checkpoints). Phase 1/2 read-only analysis found
        // no shared writer coupling between the six, but Phase 4
        // security review required a full per-firm-loop-and-merge
        // rewrite of FleetMigrationOrchestrationService (fixing a
        // genuine fail-open cross-firm authorization bug in its
        // completion gate) and a mandatory ImplementationTaskService
        // signature change (complete()/skip()/block() now take an
        // explicit ImplementationProject parameter, since
        // implementation_tasks has no firm_id of its own to key a wrap
        // on):
        // 2026_08_29_970001_prepare_row_level_security_and_force_rls_on_export_jobs_table.php,
        // 2026_08_29_970002_prepare_row_level_security_and_force_rls_on_migration_projects_table.php,
        // 2026_08_29_970003_prepare_row_level_security_and_force_rls_on_import_batches_table.php,
        // 2026_08_29_970004_prepare_row_level_security_and_force_rls_on_implementation_projects_table.php,
        // 2026_08_29_970005_prepare_row_level_security_and_force_rls_on_fleet_migration_instance_status_table.php,
        // 2026_08_29_970006_prepare_row_level_security_and_force_rls_on_offboarding_requests_table.php.
        // All six moved here from MISSING_PREPARED_TABLES below.
        'export_jobs', 'migration_projects', 'import_batches',
        'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests',
        // Section 39A-5 Wave 10 (trust accounting domain) — ten tables
        // implemented and committed as ONE combined unit, more strongly
        // than any prior wave: every table participates in a shared
        // TrustConcurrencyLockService::withLockedBalances() locking
        // primitive or an FK-linked authorization chain, so no safe
        // split point existed. Fixed a genuine fail-open bug in
        // TrustReconciliationService::run() (a silently-empty ledger
        // relation under missing context could record a false
        // "Balanced" result) and a universal pre-flight-gate gap in
        // TrustEligibilityService::hasApprovedTrustSetup() (read by
        // ~25 call sites across 7 services):
        // 2026_08_30_980001_prepare_row_level_security_and_force_rls_on_trust_accounts_table.php,
        // 2026_08_30_980002_prepare_row_level_security_and_force_rls_on_trust_ledgers_table.php,
        // 2026_08_30_980003_prepare_row_level_security_and_force_rls_on_trust_balances_table.php,
        // 2026_08_30_980004_prepare_row_level_security_and_force_rls_on_matter_trust_balances_table.php,
        // 2026_08_30_980005_prepare_row_level_security_and_force_rls_on_trust_ledger_entries_table.php,
        // 2026_08_30_980006_prepare_row_level_security_and_force_rls_on_trust_approval_events_table.php,
        // 2026_08_30_980007_prepare_row_level_security_and_force_rls_on_trust_chargeback_events_table.php,
        // 2026_08_30_980008_prepare_row_level_security_and_force_rls_on_trust_reconciliations_table.php,
        // 2026_08_30_980009_prepare_row_level_security_and_force_rls_on_trust_refund_requests_table.php,
        // 2026_08_30_980010_prepare_row_level_security_and_force_rls_on_trust_transfer_requests_table.php.
        // All ten moved here from MISSING_PREPARED_TABLES below.
        'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances',
        'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events',
        'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests',
        // Section 39A-5 Wave 11 — the eleventh and FINAL coordinated
        // multi-table wave of the 60-table rollout, covering the
        // webhooks domain. Fixed a decoy wrap in
        // WebhookEventRecorderService::record() (only the payload
        // builder was tenant-scoped; the actual event/subscription
        // read/delivery-fan-out writes were not) and a completely
        // unwrapped WebhookDispatchJob::handle() (fixed by passing firm
        // identity explicitly via a new constructor argument, after an
        // initial proposed fix deriving it from a pre-context read of
        // the RLS-gated table itself was caught and rejected in
        // independent security review as circular):
        // 2026_08_31_990001_prepare_row_level_security_and_force_rls_on_webhook_subscriptions_table.php,
        // 2026_08_31_990002_prepare_row_level_security_and_force_rls_on_webhook_events_table.php,
        // 2026_08_31_990003_prepare_row_level_security_and_force_rls_on_webhook_secrets_table.php,
        // 2026_08_31_990004_prepare_row_level_security_and_force_rls_on_webhook_deliveries_table.php,
        // 2026_08_31_990005_prepare_row_level_security_and_force_rls_on_webhook_delivery_attempts_table.php.
        // All five moved here from MISSING_PREPARED_TABLES below, which
        // is now EMPTY — this closes the 60-table rollout in full.
        'webhook_subscriptions', 'webhook_events', 'webhook_secrets',
        'webhook_deliveries', 'webhook_delivery_attempts',
        // Stage B Checkpoint 3 (FirmsBase Integration Platform mission,
        // NOT part of the old 60-table Section 39A-5 rollout above,
        // which is fully closed) — firm_integrations, a brand-new
        // genuine tenant-owned table (own NOT NULL firm_id column) with
        // RLS prepared and FORCE-activated in the very same migration,
        // following the identical combined prepare+force shape used
        // throughout the 39A-5 rollout:
        // 2026_09_02_020002_prepare_row_level_security_and_force_rls_on_firm_integrations_table.php.
        // This table was never in MISSING_PREPARED_TABLES — it is added
        // directly here since prepare and force happened together.
        'firm_integrations',
        // Stage B Checkpoint 4 (FirmsBase Integration Platform mission)
        // — integration_credentials, a brand-new genuine tenant-owned
        // table (own NOT NULL firm_id column, plus a real composite FK
        // to firm_integrations(firm_id, id)) with RLS prepared and
        // FORCE-activated in the very same migration, following the
        // identical combined prepare+force shape used throughout this
        // rollout:
        // 2026_09_03_030002_prepare_row_level_security_and_force_rls_on_integration_credentials_table.php.
        // This table was never in MISSING_PREPARED_TABLES — it is added
        // directly here since prepare and force happened together.
        'integration_credentials',
        // Stage B Checkpoint 5 (FirmsBase Integration Platform mission)
        // — integration_oauth_states, a brand-new genuine tenant-owned
        // table (own NOT NULL firm_id column, plus a real composite FK
        // to firm_integrations(firm_id, id)) with RLS prepared and
        // FORCE-activated in the very same migration, following the
        // identical combined prepare+force shape used throughout this
        // rollout. This table ALSO carries one additional, narrow,
        // FOR-SELECT-only self-lookup policy
        // (integration_oauth_states_self_lookup, byte-for-byte the
        // proven firm_users_self_lookup shape) layered underneath the
        // standard tenant policy, required to bootstrap the OAuth
        // callback lookup before any firm context can be known — see:
        // 2026_09_04_040002_prepare_row_level_security_and_force_rls_on_integration_oauth_states_table.php.
        // This table was never in MISSING_PREPARED_TABLES — it is added
        // directly here since prepare and force happened together.
        'integration_oauth_states',
        // Stage B Checkpoint 6 (FirmsBase Integration Platform mission,
        // "Transactional Outbox and Sync Persistence Foundation") — six
        // brand-new genuine tenant-owned tables (each with its own
        // NOT NULL firm_id column, plus real composite FKs to
        // firm_integrations(firm_id, id) and, for the internal
        // sync_runs -> sync_items -> conflicts chain, to each other),
        // each with RLS prepared and FORCE-activated in its own
        // combined migration, following the identical combined
        // prepare+force shape used throughout this rollout:
        // 2026_09_05_050002_prepare_row_level_security_and_force_rls_on_integration_sync_runs_table.php,
        // 2026_09_05_051002_prepare_row_level_security_and_force_rls_on_integration_sync_items_table.php,
        // 2026_09_05_052002_prepare_row_level_security_and_force_rls_on_integration_external_mappings_table.php,
        // 2026_09_05_053002_prepare_row_level_security_and_force_rls_on_integration_sync_cursors_table.php,
        // 2026_09_05_054002_prepare_row_level_security_and_force_rls_on_integration_conflicts_table.php,
        // 2026_09_05_055002_prepare_row_level_security_and_force_rls_on_integration_outbox_events_table.php.
        // None of these six tables was ever in MISSING_PREPARED_TABLES —
        // each is added directly here since prepare and force happened
        // together, matching every prior Stage B checkpoint's identical
        // convention.
        'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings',
        'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events',
        // Stage B Checkpoint 7 (FirmsBase Integration Platform mission,
        // "Inbound Webhook Security") — integration_inbound_webhook_events,
        // a brand-new genuine tenant-owned table (own NOT NULL firm_id
        // column, plus a real composite FK to firm_integrations(firm_id,
        // id)) with RLS prepared and FORCE-activated in the very same
        // migration, following the identical combined prepare+force
        // shape used throughout this rollout:
        // 2026_09_06_060004_prepare_row_level_security_and_force_rls_on_integration_inbound_webhook_events_table.php.
        // This table was never in MISSING_PREPARED_TABLES — it is added
        // directly here since prepare and force happened together. The
        // other two Checkpoint 7 tables (integration_webhook_receipts,
        // integration_webhook_routing_index) deliberately receive NO RLS
        // at all — see FULL_TABLE_INVENTORY_EXTRA below for their
        // Global classification and explicit disclaimer notes.
        'integration_inbound_webhook_events',
        // Stage B Checkpoint 8 (FirmsBase Integration Platform mission)
        // — integration_connection_health, a brand-new genuine
        // tenant-owned table (own NOT NULL firm_id column, plus a real
        // composite FK to firm_integrations(firm_id, id)) with RLS
        // prepared and FORCE-activated in the very same migration,
        // following the identical combined prepare+force shape used
        // throughout this rollout:
        // 2026_09_07_070002_prepare_row_level_security_and_force_rls_on_integration_connection_health_table.php.
        // This table was never in MISSING_PREPARED_TABLES — it is added
        // directly here since prepare and force happened together.
        'integration_connection_health',
        // Stage B Checkpoint 9 (FirmsBase Integration Platform mission,
        // "Usage, Audit, Retention, Access, and Governance") —
        // integration_usage_records, a brand-new genuine tenant-owned
        // table (own NOT NULL firm_id column, plus a real composite FK
        // to firm_integrations(firm_id, id)) with RLS prepared and
        // FORCE-activated in the very same migration, following the
        // identical combined prepare+force shape used throughout this
        // rollout:
        // 2026_09_08_080002_prepare_row_level_security_and_force_rls_on_integration_usage_records_table.php.
        // This table was never in MISSING_PREPARED_TABLES — it is added
        // directly here since prepare and force happened together.
        'integration_usage_records',
        // FirmsVault Live Integrations, Checkpoint 2 ("Add Microsoft
        // 365 integration provider") — integration_provider_webhook_subscriptions,
        // a brand-new genuine tenant-owned table (own NOT NULL firm_id
        // column, plus a real composite FK to firm_integrations(firm_id,
        // id)), the durable home for a real provider webhook
        // subscription's state (id/expiry/resource scope), with RLS
        // prepared and FORCE-activated in the very same migration,
        // following the identical combined prepare+force shape used
        // throughout this rollout:
        // 2026_09_22_160002_prepare_row_level_security_and_force_rls_on_integration_provider_webhook_subscriptions_table.php.
        // This table was never in MISSING_PREPARED_TABLES — it is added
        // directly here since prepare and force happened together.
        'integration_provider_webhook_subscriptions',
        // FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial
        // evidence add-on") — client_portal_matter_grants, a brand-new
        // genuine tenant-owned table (own NOT NULL firm_id column) — the
        // explicit client-to-matter portal-visibility grant
        // (checkpoint4-combined-design.md §5/§10;
        // checkpoint4-design-matter-and-client-portal.md §2.6.3), with
        // RLS prepared and FORCE-activated in the very same migration,
        // following the identical combined prepare+force shape used
        // throughout this rollout:
        // 2026_09_24_180005_prepare_row_level_security_and_force_rls_on_client_portal_matter_grants_table.php.
        // This table was never in MISSING_PREPARED_TABLES — it is added
        // directly here since prepare and force happened together.
        // NOTE: client_portal_users (the OTHER new table this checkpoint
        // originally added as FORCE-RLS) is DELIBERATELY NOT added
        // here, and never has been — it has no firm_id column of its
        // own (isolation is transitive through client_id ->
        // clients.firm_id). It was briefly classified InheritedTenant
        // with real FORCE RLS in an earlier draft of this checkpoint;
        // that draft is a confirmed defect (see
        // ClientPortalAuthenticationTest's own docblock), since FORCING
        // RLS on the credential/identity table Auth::attempt() must
        // look up BY EMAIL with no context at all makes client login
        // structurally impossible. It is now classified System instead
        // (identical treatment to 'users' below) — see this table's own
        // create-migration docblock's "WHY THIS TABLE HAS NO RLS"
        // section for the full corrected reasoning.
        'client_portal_matter_grants',
        // FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial
        // evidence add-on", cost-control/billing track) —
        // provider_billable_call_reservations, provider_firm_operation_policies,
        // and provider_balance_snapshots, three brand-new genuine
        // tenant-owned tables (own NOT NULL firm_id column, plus a real
        // composite FK to firm_integrations(firm_id, id) for the first
        // and third), with RLS prepared and FORCE-activated in the very
        // same migration each, following the identical combined
        // prepare+force shape used throughout this rollout:
        // 2026_09_24_500003_prepare_row_level_security_and_force_rls_on_provider_billable_call_reservations_table.php,
        // 2026_09_24_500007_prepare_row_level_security_and_force_rls_on_provider_firm_operation_policies_table.php,
        // 2026_09_24_500009_prepare_row_level_security_and_force_rls_on_provider_balance_snapshots_table.php.
        // None of these three was ever in MISSING_PREPARED_TABLES — each
        // is added directly here since prepare and force happened
        // together. See EXEMPT_TABLES/EXEMPT_TABLE_METADATA below for
        // this checkpoint's four sibling GLOBAL/no-RLS tables
        // (provider_rate_card_entries, provider_kill_switches,
        // provider_operation_default_policies,
        // provider_invoice_reconciliations) — deliberately NOT listed
        // here, since none carries a firm_id column
        // (checkpoint4-combined-design.md §1.7/§1.8/§10).
        'provider_billable_call_reservations', 'provider_firm_operation_policies',
        'provider_balance_snapshots',
        // FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial
        // evidence add-on", Plaid provider-core track) — the seven new
        // resource-materializer tables, each a brand-new genuine
        // tenant-owned table (own NOT NULL firm_id column, plus a real
        // composite FK to firm_integrations(firm_id, id)), with RLS
        // prepared and FORCE-activated in the very same migration each,
        // following the identical combined prepare+force shape used
        // throughout this rollout (checkpoint4-design-workspace-and-admin-ui.md
        // §1.2, schema authoritative source; checkpoint4-combined-design.md
        // §1.1.3/§7, implementation ownership reassigned to this track):
        // 2026_09_24_180004_prepare_row_level_security_and_force_rls_on_financial_evidence_bank_accounts_table.php,
        // 2026_09_24_180006_prepare_row_level_security_and_force_rls_on_financial_evidence_transactions_table.php,
        // 2026_09_24_180008_prepare_row_level_security_and_force_rls_on_financial_evidence_income_records_table.php,
        // 2026_09_24_180010_prepare_row_level_security_and_force_rls_on_financial_evidence_liabilities_table.php,
        // 2026_09_24_180012_prepare_row_level_security_and_force_rls_on_financial_evidence_investment_records_table.php,
        // 2026_09_24_180014_prepare_row_level_security_and_force_rls_on_financial_evidence_statements_table.php,
        // 2026_09_24_180016_prepare_row_level_security_and_force_rls_on_financial_evidence_identity_records_table.php.
        // None of these seven was ever in MISSING_PREPARED_TABLES — each
        // is added directly here since prepare and force happened
        // together. See EXEMPT_TABLES/FULL_TABLE_INVENTORY_EXTRA below
        // for this checkpoint's separate, Global/no-RLS
        // integration_plaid_item_routes table — deliberately NOT listed
        // here, since it must remain queryable before any tenant context
        // exists.
        'financial_evidence_bank_accounts', 'financial_evidence_transactions',
        'financial_evidence_income_records', 'financial_evidence_liabilities',
        'financial_evidence_investment_records', 'financial_evidence_statements',
        'financial_evidence_identity_records',
        // FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial
        // evidence add-on", Financial Evidence Workspace/Firm-Admin/
        // PlatformAdmin/Client-Portal UI track) — nine further brand-new
        // genuine tenant-owned tables (own NOT NULL firm_id column),
        // each with RLS prepared and FORCE-activated in the very same
        // migration, following the identical combined prepare+force
        // shape used throughout this rollout
        // (checkpoint4-design-workspace-and-admin-ui.md §1.4/§1.6.1/§1.7/
        // §1.8/§4.1/§4.6/§5; checkpoint4-combined-design.md §9.4/§10):
        // 2026_09_25_190002_prepare_row_level_security_and_force_rls_on_financial_evidence_matter_requests_table.php,
        // 2026_09_25_190004_prepare_row_level_security_and_force_rls_on_financial_evidence_client_consents_table.php,
        // 2026_09_25_190006_prepare_row_level_security_and_force_rls_on_financial_evidence_matter_authorizations_table.php,
        // 2026_09_25_190008_prepare_row_level_security_and_force_rls_on_financial_evidence_matter_notes_table.php,
        // 2026_09_25_190010_prepare_row_level_security_and_force_rls_on_financial_evidence_snapshots_table.php,
        // 2026_09_25_190012_prepare_row_level_security_and_force_rls_on_financial_evidence_transaction_reviews_table.php,
        // 2026_09_25_190014_prepare_row_level_security_and_force_rls_on_financial_evidence_duplicate_transfer_flags_table.php,
        // 2026_09_25_190016_prepare_row_level_security_and_force_rls_on_financial_evidence_large_deposit_flags_table.php,
        // 2026_09_25_190019_prepare_row_level_security_and_force_rls_on_financial_evidence_reconciliation_candidates_table.php,
        // 2026_09_25_190021_prepare_row_level_security_and_force_rls_on_financial_account_reclassification_requests_table.php.
        // None of these nine was ever in MISSING_PREPARED_TABLES — each is
        // added directly here since prepare and force happened together.
        // `financial_evidence_large_deposit_thresholds` is DELIBERATELY
        // NOT listed here — it carries no firm_id column at all (a
        // Global, no-RLS table mirroring `provider_rate_card_entries`'s
        // own platform_default/firm_override scope-precedence pattern,
        // checkpoint4-combined-design.md §1.6) — see EXEMPT_TABLES/
        // EXEMPT_TABLE_METADATA below.
        'financial_evidence_matter_requests', 'financial_evidence_client_consents',
        'financial_evidence_matter_authorizations', 'financial_evidence_matter_notes',
        'financial_evidence_snapshots', 'financial_evidence_transaction_reviews',
        'financial_evidence_duplicate_transfer_flags', 'financial_evidence_large_deposit_flags',
        'financial_evidence_reconciliation_candidates', 'financial_account_reclassification_requests',
        // Native accounting journal (Phase A of the legal-accounting
        // foundation) — two tables, prepared and forced together in
        // one release:
        // 2026_10_25_100003_prepare_row_level_security_and_force_rls_on_accounting_journal_entries_table.php,
        // 2026_10_25_100004_prepare_row_level_security_and_force_rls_on_accounting_postings_table.php.
        // Neither was ever in MISSING_PREPARED_TABLES — both added
        // directly here since prepare and force happened together.
        'accounting_journal_entries', 'accounting_postings',
    ];

    /**
     * Tenant-owned tables (direct, non-nullable firm_id representing
     * genuine per-firm data ownership) introduced from Phase 7 onward,
     * confirmed via their model's use of BelongsToTenant (or, for
     * trust_ledger_entries, a confirmed firm_id column despite not
     * using the trait) — with NO corresponding RLS-preparation
     * migration found anywhere in the repository.
     *
     * Section 39A-4A.1 added 18 tables to this array
     * (accounting_export_lines, customer_success_health_scores,
     * document_hashes, email_sync_events, fleet_migration_instance_status,
     * form_review_events, generated_document_events,
     * implementation_projects, matter_expenses, matter_trust_balances,
     * pdf_view_events, signature_events, support_access_requests,
     * support_access_sessions, trust_approval_events,
     * webhook_deliveries, webhook_delivery_attempts, webhook_secrets)
     * after an inventory sweep confirmed each has its own NOT NULL
     * firm_id column, relrowsecurity=false live, and zero RLS policies
     * — the identical evidentiary bar already used for every
     * pre-existing entry in this array. This is a pure registry-
     * tracking correction: none of these 18 tables' live RLS state was
     * changed by that section.
     *
     * Section 39A-5, Checkpoint 1 removed customer_success_health_scores
     * from this array (moved to PREPARED_TABLES above) — it now has a
     * real RLS policy and is FORCE-enforced; see that constant's own
     * docblock note.
     *
     * Section 39A-5 Wave 1 removed ai_retrieval_indexes,
     * deployment_configs, and firm_ai_settings from this array (moved
     * to PREPARED_TABLES above) — the first coordinated multi-table
     * wave of the 39A-5 uncovered-table rollout, chosen for minimal,
     * mutually-independent blast radius.
     *
     * Section 39A-5 Wave 2 removed email_visibility_rules,
     * private_enterprise_settings, matter_expenses, and
     * email_message_links from this array (moved to PREPARED_TABLES
     * above) — the second coordinated multi-table wave.
     *
     * Section 39A-5 Wave 3 removed ai_usage_events, ai_tool_actions,
     * firm_ai_provider_keys, ai_approval_requests, and
     * ai_approval_events from this array (moved to PREPARED_TABLES
     * above) — the third coordinated multi-table wave, covering the
     * AI governance domain.
     *
     * Section 39A-5 Wave 4 removed chart_of_accounts, expense_categories,
     * expenses, expense_receipts, expense_approvals,
     * accounting_export_batches, and accounting_export_lines from this
     * array (moved to PREPARED_TABLES above) — the fourth coordinated
     * multi-table wave, covering the accounting/expense domain,
     * implemented as one combined unit rather than seven independent
     * checkpoints.
     *
     * Section 39A-5 Wave 5 removed email_accounts, email_messages,
     * email_attachments, and email_sync_events from this array (moved
     * to PREPARED_TABLES above) — the fifth coordinated multi-table
     * wave, covering the email domain, implemented as one combined unit
     * rather than four independent checkpoints.
     *
     * Section 39A-5 Wave 6 removed generated_documents, form_drafts,
     * generated_document_events, form_review_events, document_hashes,
     * and pdf_view_events from this array (moved to PREPARED_TABLES
     * above) — the sixth coordinated multi-table wave, covering the
     * documents/forms domain, implemented as one combined unit rather
     * than six independent checkpoints.
     *
     * Section 39A-5 Wave 7 removed signature_requests,
     * signature_request_recipients, signature_events, and
     * signature_certificates from this array (moved to PREPARED_TABLES
     * above) — the seventh coordinated multi-table wave, covering the
     * e-signature domain, implemented as one combined unit rather than
     * four independent checkpoints.
     *
     * Section 39A-5 Wave 8 removed legal_holds, deletion_requests,
     * key_destruction_requests, support_access_requests,
     * support_access_sessions, and deployment_health_checks from this
     * array (moved to PREPARED_TABLES above) — the eighth coordinated
     * multi-table wave, covering the governance/support/platform
     * domain, implemented as one combined unit rather than six
     * independent checkpoints.
     *
     * Section 39A-5 Wave 9 removed export_jobs, migration_projects,
     * import_batches, implementation_projects,
     * fleet_migration_instance_status, and offboarding_requests from
     * this array (moved to PREPARED_TABLES above) — the ninth
     * coordinated multi-table wave, covering the migration/export
     * domain, implemented as one combined unit rather than six
     * independent checkpoints.
     *
     * Section 39A-5 Wave 10 removed trust_accounts, trust_ledgers,
     * trust_balances, matter_trust_balances, trust_ledger_entries,
     * trust_approval_events, trust_chargeback_events,
     * trust_reconciliations, trust_refund_requests, and
     * trust_transfer_requests from this array (moved to PREPARED_TABLES
     * above) — the tenth coordinated multi-table wave, covering the
     * trust accounting domain, the largest and most tightly-coupled
     * group in this rollout, implemented as one combined unit rather
     * than ten independent checkpoints.
     *
     * Section 39A-5 Wave 11 removed webhook_subscriptions,
     * webhook_events, webhook_secrets, webhook_deliveries, and
     * webhook_delivery_attempts from this array (moved to
     * PREPARED_TABLES above) — the eleventh and FINAL coordinated
     * multi-table wave of the entire 60-table rollout. This array is
     * now EMPTY: every tenant-owned table identified at the start of
     * this rollout now has real RLS preparation and FORCE activation.
     * A future genuinely-new tenant-owned table (e.g. from an
     * as-yet-unbuilt feature) would still need to be added here first,
     * per this class's own docblock — an empty array today does not
     * mean this array can never be used again, only that the known
     * backlog as of this rollout is fully closed.
     *
     * @var array<int, string>
     */
    private const MISSING_PREPARED_TABLES = [
    ];

    /**
     * Global/organization/platform-level tables intentionally exempt
     * from firm-keyed RLS — they either have no firm_id at all, or
     * (usage_rollups, platform_invoice_lines) carry a NULLABLE firm_id
     * for attribution only, where the real ownership boundary is
     * billing_account_id, not firm_id (reasoning taken directly from
     * the Phase 6 RLS migration's own doc comment).
     *
     * Wave 1A (Section 39A-4B) appended two more exemptions at the end
     * of this array, approved by the human: module_catalog and
     * readiness_scorecard_components. Both were confirmed by direct
     * migration inspection (database/migrations/2026_07_04_300001_
     * create_module_catalog_table.php and
     * 2026_07_07_800013_create_readiness_scorecard_components_table.php)
     * to have no firm_id or any other firm-referencing column at all —
     * see RowLevelSecurityNoTenantColumnStructuralTest for the static,
     * migration-schema-parsing proof of this, and
     * EXEMPT_TABLE_METADATA below for each one's documented reason,
     * expected readers, and authorized writers. The original 22
     * entries above are untouched — this addition only appends.
     *
     * Stage B Checkpoint 2 of the FirmsBase Integration Platform
     * mission appended one further exemption at the end of this
     * array: integration_providers. Confirmed by direct inspection of
     * database/migrations/2026_09_01_010001_create_integration_providers_table.php
     * to carry no firm_id or any other firm-referencing column at all
     * — it is a platform-wide, seeded-only reference catalog exactly
     * analogous to module_catalog above. See EXEMPT_TABLE_METADATA
     * below for its documented reason, expected readers, and
     * authorized writers. The 24 entries above are untouched — this
     * addition only appends.
     *
     * Stage B Checkpoint 7 registry-classification correction
     * (post-implementation gap found by the pre-commit full test
     * suite, test_every_table_with_a_not_null_firm_id_column_is_tracked_in_the_registry)
     * appended one further exemption at the end of this array:
     * integration_webhook_routing_index. UNLIKE every other entry in
     * this array, this table is NOT a "no firm_id column" exemption —
     * direct inspection of
     * database/migrations/2026_09_06_060001_create_integration_webhook_routing_index_table.php
     * confirms it carries a genuine NOT NULL firm_id column
     * ($table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete()).
     * It is exempted anyway, for a documented, independently-reviewed
     * reason distinct from the "Global, no firm_id" pattern above: see
     * EXEMPT_TABLE_METADATA below for the full reason (no secret/
     * credential material; must be queryable before any tenant context
     * exists, to bootstrap inbound-webhook identity resolution; its
     * firm_id is a non-authoritative routing pointer only — real
     * authorization is always re-derived afterward under ordinary,
     * unmodified RLS via TenantContextService::runWithFirmContext()).
     * The 25 entries above are untouched — this addition only appends.
     *
     * Checkpoint 11 (FirmsBase Integration Platform mission, "SuperAdmin
     * Integration Oversight and Governance"; reviews/checkpoint-11/
     * frozen-design-post-security-review.md §5) appended one further
     * exemption at the end of this array: integration_platform_overview_summaries.
     * Like integration_webhook_routing_index, this is NOT a "no firm_id"
     * exemption — direct inspection of
     * database/migrations/2026_09_09_090001_create_integration_platform_overview_summaries_table.php
     * confirms it carries a genuine NOT NULL, UNIQUE firm_id column
     * ($table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete()->unique()).
     * It is exempted anyway, for a documented reason: it backs an
     * always-visible, cross-firm SuperAdmin overview list that must
     * remain readable without a per-request, per-firm RLS context-switch
     * cost, over a firm population of undocumented/unbounded size; every
     * column is a sanitized count/status/timestamp snapshot, never raw
     * resource content, a secret, or credential material; and there is
     * exactly one writer (the scheduled per-firm summary-refresh job via
     * App\Services\IntegrationPlatformOverviewSummaryService::refreshForFirm()).
     * See EXEMPT_TABLE_METADATA below for the full reason/readers/
     * writers. The 26 entries above are untouched — this addition only
     * appends.
     *
     * Phase 2 (FirmsVault Platform Admin Control Center, "Integration
     * Operations Center") appended one further exemption at the end of
     * this array: integration_platform_provider_health_summaries. UNLIKE
     * integration_platform_overview_summaries/integration_webhook_routing_index,
     * this IS an ordinary "no firm_id" exemption — direct inspection of
     * database/migrations/2026_09_11_110001_create_integration_platform_provider_health_summaries_table.php
     * confirms it carries no firm_id column at all (a per-provider
     * cross-firm rollup, not a per-firm row) — structurally identical in
     * shape to `integration_providers` (the table it foreign-keys to).
     * See EXEMPT_TABLE_METADATA below for the full reason/readers/
     * writers. The 27 entries above are untouched — this addition only
     * appends.
     *
     * FirmsVault Live Integrations, Checkpoint 3 ("Add Google Workspace
     * integration provider") appended one further exemption at the end
     * of this array: integration_gmail_mailbox_routes
     * (checkpoint3-combined-design.md §5/§6.4.3;
     * checkpoint3-security-review.md Finding 3, required — the identical
     * registry-omission class of defect integration_webhook_routing_index
     * itself already required a post-implementation correction for).
     * Like integration_webhook_routing_index and
     * integration_platform_overview_summaries, this is NOT a "no firm_id"
     * exemption — direct inspection of
     * database/migrations/2026_09_23_170001_create_integration_gmail_mailbox_routes_table.php
     * confirms it carries a genuine NOT NULL firm_id column
     * ($table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete()).
     * It is exempted anyway, for the identical structural reason
     * integration_webhook_routing_index itself carries no RLS: it must be
     * queryable during GmailMailboxRoutingService::resolveByMailbox()'s
     * pre-tenant-context routing step, before any firm identity exists on
     * the inbound Gmail Pub/Sub webhook request. See EXEMPT_TABLE_METADATA
     * below for the full reason/readers/writers. The 28 entries above are
     * untouched — this addition only appends.
     *
     * @var array<int, string>
     */
    private const EXEMPT_TABLES = [
        'organizations', 'billing_accounts', 'plans', 'plan_modules', 'plan_limits',
        'org_licenses', 'seat_pools', 'license_events',
        'platform_subscriptions', 'platform_subscription_items', 'platform_invoices',
        'platform_payments', 'platform_refunds', 'platform_payment_attempts',
        'platform_billing_events', 'platform_invoice_lines', 'usage_rollups',
        'practice_areas', 'matter_types', 'template_packs', 'template_pack_versions',
        'intake_templates',
        // Wave 1A (Section 39A-4B) additions — see docblock above.
        'module_catalog', 'readiness_scorecard_components',
        // Stage B Checkpoint 2 (FirmsBase Integration Platform mission)
        // addition — see docblock above.
        'integration_providers',
        // Stage B Checkpoint 7 registry-classification correction
        // addition — see docblock above. Has a real NOT NULL firm_id
        // column (unlike every other entry in this array); exempted
        // for a documented, independently-reviewed reason instead —
        // see EXEMPT_TABLE_METADATA below.
        'integration_webhook_routing_index',
        // Checkpoint 11 (FirmsBase Integration Platform mission) addition
        // — see docblock above. Has a real NOT NULL, UNIQUE firm_id
        // column (like integration_webhook_routing_index, unlike every
        // "no firm_id" entry above); exempted for a documented,
        // independently-reviewed reason instead — see
        // EXEMPT_TABLE_METADATA below.
        'integration_platform_overview_summaries',
        // Phase 2 (FirmsVault Platform Admin Control Center) addition —
        // see docblock above. An ordinary "no firm_id" exemption, unlike
        // the two entries immediately above.
        'integration_platform_provider_health_summaries',
        // FirmsVault Live Integrations, Checkpoint 3 (Google Workspace
        // provider) addition — see docblock above. Has a real NOT NULL
        // firm_id column (like integration_webhook_routing_index, unlike
        // every "no firm_id" entry above); exempted for a documented,
        // independently-reviewed reason instead — see
        // EXEMPT_TABLE_METADATA below.
        'integration_gmail_mailbox_routes',
        // FirmsVault Live Integrations, Checkpoint 4 ("Plaid Financial
        // Evidence add-on", cost-control/billing track) additions —
        // checkpoint4-design-cost-control.md §1.1/§4.1/§6;
        // checkpoint4-combined-design.md §1.7/§1.8/§10. All four are
        // ordinary "no firm_id at all" exemptions (structurally
        // identical to `integration_providers`/`module_catalog`) —
        // platform-admin-authored reference/incident-response/
        // reconciliation data, never firm-panel-writable. See
        // EXEMPT_TABLE_METADATA below for each one's full reason/
        // readers/writers.
        'provider_rate_card_entries', 'provider_kill_switches',
        'provider_operation_default_policies', 'provider_invoice_reconciliations',
        // FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial
        // evidence add-on", Plaid provider-core track) addition —
        // integration_plaid_item_routes, checkpoint4-combined-design.md
        // §1.1.1 (binding "Option B");
        // checkpoint4-design-plaid-provider-core.md §11.2;
        // checkpoint4-security-review.md Finding 7 (confirmed
        // safe/sufficient). Has a real NOT NULL firm_id column (like
        // integration_webhook_routing_index/integration_gmail_mailbox_routes,
        // unlike every "no firm_id" entry above); exempted for a
        // documented, structurally-identical reason instead — see
        // EXEMPT_TABLE_METADATA below.
        'integration_plaid_item_routes',
        // FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial
        // evidence add-on", Financial Evidence Workspace UI track)
        // addition — financial_evidence_large_deposit_thresholds,
        // checkpoint4-combined-design.md §1.6 (found-and-fixed RLS
        // misclassification: the source doc's own blanket "all Direct
        // BelongsToTenant + FORCE RLS" rule conflicted with its own
        // explicit statement that this table's scope shape is identical
        // to provider_rate_card_entries' platform_default/firm_override
        // pattern; resolved by following the more-precedented Global/
        // no-RLS option). An ordinary "no firm_id at all" exemption
        // (structurally identical to provider_rate_card_entries) — its
        // nullable scope_id merely POINTS AT a firm for firm_override
        // rows without the row itself being tenant-owned. See
        // EXEMPT_TABLE_METADATA below.
        'financial_evidence_large_deposit_thresholds',
        // FirmsVault Live Integrations, Checkpoint 8.2 §A4 addition —
        // provider_operation_attempts, the FK-FREE durable at-most-once
        // provider-call gate. Carries a real NOT NULL firm_id scalar
        // (like integration_webhook_routing_index/
        // integration_gmail_mailbox_routes/integration_plaid_item_routes,
        // unlike every "no firm_id" entry above), exempted for a
        // documented reason: it is written on a database session
        // deliberately INDEPENDENT of whatever transaction/row locks a
        // caller holds, so a firm-keyed RLS policy would require tenant
        // context pushed on that separate session for every read —
        // including the pre-claim probe that runs before any firm
        // context is necessarily established — reintroducing exactly the
        // cross-session coupling this table exists to eliminate.
        // Checkpoint 8.1's rejected design proved that coupling
        // deadlocks against PullSyncJob's lockForUpdate() on
        // firm_integrations. Tenant attribution is preserved via the
        // firm_id scalar, which every query in
        // ProviderOperationAttemptService filters on explicitly, and
        // these rows are operational evidence only — never a source of
        // truth for money owed (the authoritative billing rows keep
        // their real FKs and RLS on the ordinary connection). See
        // EXEMPT_TABLE_METADATA below and the create migration's own
        // docblock.
        'provider_operation_attempts',
        // feature/ses-event-consumer addition — notification_provider_correlations,
        // the outbound-send correlation ledger the SES bounce/complaint
        // consumer uses to resolve an inbound event back to the correct
        // firm. Has a real NOT NULL firm_id column (like
        // integration_webhook_routing_index/integration_gmail_mailbox_routes),
        // exempted for the identical structural reason: it must be
        // queryable during SesEventConsumerService's pre-tenant-context
        // firm-resolution step, before app.current_firm_id can be set.
        // See EXEMPT_TABLE_METADATA below.
        'notification_provider_correlations',
        // feature/ses-event-consumer addition — ses_event_receipts, the
        // idempotency ledger for the SES bounce/complaint consumer. An
        // ordinary "no firm_id at all" exemption (structurally identical
        // to integration_platform_provider_health_summaries) — one row
        // per already-processed SES event, never a per-firm row. See
        // EXEMPT_TABLE_METADATA below.
        'ses_event_receipts',
        // post-578ee98 audit remediation (finding H1) — platform-scope
        // correlation/suppression ledgers for governed real sends that
        // cannot resolve a firm. Ordinary "no firm_id at all"
        // exemptions, identical shape to ses_event_receipts. See
        // EXEMPT_TABLE_METADATA below.
        'platform_notification_correlations',
        'platform_notification_suppressions',
    ];

    /**
     * Wave 1A canonical inventory (Section 39A-4B): every one of the
     * 208 repository tables that is NOT DirectTenant (i.e. not already
     * in PREPARED_TABLES/MISSING_PREPARED_TABLES). Built entirely from
     * direct database/migrations inspection — every ownership_path
     * below traces a real foreignId()/constrained() FK found in the
     * cited migration file, never a live-database query.
     *
     * 95 entries: 1 RootTenant (firms) + 1 Uncertain
     * (offboarding_exports) + 24 InheritedTenant + 3 Pivot +
     * 10 Hybrid + 44 Global (the 22 EXEMPT_TABLES entries plus the 20
     * further platform-wide tables classified Global here but not
     * added to EXEMPT_TABLES) + 4 Audit + 8 System.
     * fullTableInventory() below merges this with a DirectTenant entry
     * synthesized for every PREPARED_TABLES/MISSING_PREPARED_TABLES
     * table (113), for 95 + 113 = 208 total.
     *
     * @var array<string, array{classification: TenantOwnershipClassification, ownership_path: ?string, notes: string}>
     */
    private const FULL_TABLE_INVENTORY_EXTRA = [
        // --- RootTenant (1) ---------------------------------------
        'firms' => [
            'classification' => TenantOwnershipClassification::RootTenant,
            'ownership_path' => 'self',
            'notes' => 'The tenant row itself — no child firm_id column exists because '
                .'firms.id IS the tenant identity. PK confirmed via $table->id() in '
                .'database/migrations/2026_07_04_100003_create_firms_table.php: bigint '
                .'(bigserial), auto-incrementing, column name `id` — not a string/uuid '
                .'PK (firms.uuid exists but is not the primary key). Its eventual RLS '
                .'policy predicate (NOT implemented or migrated in this task — deferred '
                .'to a later wave pending platform-admin bypass design) would be '
                ."`id = current_setting('app.current_firm_id', true)::bigint` — a direct "
                .'PK match, no recursive parent lookup, unlike every DirectTenant table\'s '
                .'`firm_id = current_setting(...)::bigint` predicate.',
        ],
        // Platform Firm Provisioning workflow addition. Global, no
        // firm_id — mirrors trial_requests' own reasoning exactly: a
        // firm does not yet exist (or, for a concurrent racing
        // submission, may still be mid-creation) at the point this
        // idempotency-ledger row is claimed. firm_id/owner_user_id are
        // nullable FKs populated only once the referenced Firm/User
        // rows actually exist.
        'firm_provisioning_requests' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Idempotency ledger for FirmProvisioningService::provision(); platform-wide, requested_by_platform_admin_id-scoped, not firm_id-scoped.',
        ],

        // --- Uncertain (1) ------------------------------------------
        'offboarding_exports' => [
            'classification' => TenantOwnershipClassification::Uncertain,
            'ownership_path' => null,
            'notes' => 'Ownership genuinely unresolved: both offboarding_request_id and '
                .'export_job_id are nullable (see database/migrations/2026_07_28_900004_'
                .'create_offboarding_exports_table.php), so neither FK reliably '
                .'establishes tenant ownership for every row. Do not infer an ownership '
                .'path here and do not add this table to EXEMPT_TABLES or any RLS policy '
                .'— a separate, parallel investigation owns this table (see Wave 1F '
                .'investigation). This entry intentionally records Uncertain, not a '
                .'guess.',
        ],

        // --- InheritedTenant (24) -------------------------------------
        'access_review_items' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'access_review_id -> access_reviews.firm_id (nullable/Hybrid parent)',
            'notes' => 'No firm_id of its own; scoped transitively through access_review_id.',
        ],
        'accounting_export_errors' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'accounting_export_line_id -> accounting_export_lines.firm_id',
            'notes' => 'No firm_id of its own (append-only error log); scoped transitively '
                .'through accounting_export_line_id.',
        ],
        'activation_checklist_items' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'activation_checklist_id -> activation_checklists.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through activation_checklist_id.',
        ],
        'conflict_check_results' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'conflict_check_run_id -> conflict_check_runs.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through conflict_check_run_id.',
        ],
        'deletion_approvals' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'deletion_request_id -> deletion_requests.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through deletion_request_id.',
        ],
        'document_request_items' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'document_request_id -> document_requests.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through document_request_id.',
        ],
        'document_versions' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'document_id -> documents.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through document_id.',
        ],
        'email_oauth_tokens' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'email_account_id -> email_accounts.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through email_account_id.',
        ],
        'export_files' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'export_job_id -> export_jobs.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through export_job_id.',
        ],
        'form_draft_values' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'form_draft_id -> form_drafts.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through form_draft_id.',
        ],
        'form_missing_data_items' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'form_draft_id -> form_drafts.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through form_draft_id.',
        ],
        'form_review_checklist_items' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'form_draft_id -> form_drafts.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through form_draft_id.',
        ],
        'implementation_tasks' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'implementation_project_id -> implementation_projects.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through implementation_project_id.',
        ],
        'import_audit_events' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'import_batch_id -> import_batches.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through import_batch_id.',
        ],
        'import_errors' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'import_row_id -> import_rows.import_batch_id -> import_batches.firm_id',
            'notes' => 'No firm_id of its own; two-hop transitive scoping through import_row_id.',
        ],
        'import_mappings' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'import_batch_id -> import_batches.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through import_batch_id.',
        ],
        'import_rollback_records' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'import_batch_id -> import_batches.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through import_batch_id.',
        ],
        'import_rows' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'import_batch_id -> import_batches.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through import_batch_id.',
        ],
        'invoice_lines' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'invoice_id -> invoices.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through invoice_id, '
                .'same pattern as Phase 2\'s matter_parties.',
        ],
        'key_destruction_approvals' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'key_destruction_request_id -> key_destruction_requests.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through key_destruction_request_id.',
        ],
        'manual_payment_records' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'payment_id -> payments.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through payment_id.',
        ],
        'matter_assignments' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'matter_id -> matters.firm_id',
            'notes' => 'Staffing/assignment history record (role, is_lead, assigned_at, '
                .'removed_at) — no firm_id of its own; scoped transitively through matter_id.',
        ],
        // client_portal_users was briefly classified InheritedTenant
        // here, with a note describing real FORCE ROW LEVEL SECURITY —
        // that was a confirmed defect (FORCING RLS on the credential
        // table Auth::attempt() must look up BY EMAIL with no context
        // at all makes client login structurally impossible; see
        // ClientPortalAuthenticationTest's own docblock for the full
        // empirical reproduction). It is now classified System instead,
        // immediately alongside 'users' below (identical role, one
        // level down) — see this table's own create-migration
        // docblock's "WHY THIS TABLE HAS NO RLS" section for the full
        // corrected reasoning.
        'payment_plan_installments' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'payment_plan_id -> payment_plans.firm_id',
            'notes' => 'No firm_id of its own; scoped transitively through payment_plan_id.',
        ],
        'document_template_versions' => [
            'classification' => TenantOwnershipClassification::InheritedTenant,
            'ownership_path' => 'document_template_id -> document_templates.firm_id (nullable/Hybrid parent)',
            'notes' => 'No firm_id of its own; scoped transitively through document_template_id, '
                .'whose own firm_id is nullable (Hybrid) — a version inherits whatever scope '
                .'its parent template currently has.',
        ],

        // --- Pivot (3) ------------------------------------------------
        'matter_parties' => [
            'classification' => TenantOwnershipClassification::Pivot,
            'ownership_path' => 'matter_id -> matters.firm_id',
            'notes' => 'Many-to-many bridge between matters and parties (relationship_type/'
                .'is_opposing/is_related are bridge attributes, not a distinct owned '
                .'entity). No firm_id of its own.',
        ],
        'task_dependencies' => [
            'classification' => TenantOwnershipClassification::Pivot,
            'ownership_path' => 'task_id -> tasks.firm_id',
            'notes' => 'Self-referencing bridge over tasks (task_id, blocked_by_task_id) '
                .'expressing a dependency graph. No firm_id of its own.',
        ],
        'api_key_scopes' => [
            'classification' => TenantOwnershipClassification::Pivot,
            'ownership_path' => 'api_key_id -> api_keys.firm_id (nullable/Hybrid parent)',
            'notes' => 'Grant row over the fixed ApiKeyScopeCode enum, one row per '
                .'(api_key_id, scope_code). No firm_id of its own.',
        ],

        // --- Hybrid (10) — table carries its own NULLABLE firm_id ------
        'access_reviews' => [
            'classification' => TenantOwnershipClassification::Hybrid,
            'ownership_path' => 'self (own nullable firm_id column)',
            'notes' => 'firm_id is null for platform-scope reviews (e.g. platform admins, '
                .'API keys) and set for firm-scope reviews (e.g. firm admins, employee roles).',
        ],
        'announcements' => [
            'classification' => TenantOwnershipClassification::Hybrid,
            'ownership_path' => 'self (own nullable firm_id column)',
            'notes' => 'firm_id, plan_id, and module_code are all nullable — null on every '
                .'one of them means a broadcast/global announcement.',
        ],
        'api_keys' => [
            'classification' => TenantOwnershipClassification::Hybrid,
            'ownership_path' => 'self (own nullable firm_id column)',
            'notes' => 'firm_id is nullable — platform-type API keys carry no firm_id at all.',
        ],
        'api_requests' => [
            'classification' => TenantOwnershipClassification::Hybrid,
            'ownership_path' => 'self (own nullable firm_id column)',
            'notes' => 'firm_id is nullable, mirroring api_keys — platform-scope requests '
                .'carry no firm_id.',
        ],
        'data_processing_records' => [
            'classification' => TenantOwnershipClassification::Hybrid,
            'ownership_path' => 'self (own nullable firm_id column)',
            'notes' => 'firm_id is nullable — a record can be a firm-specific processing '
                .'record or a platform-level one (vendor/subprocessor/retention-policy '
                .'level).',
        ],
        'document_templates' => [
            'classification' => TenantOwnershipClassification::Hybrid,
            'ownership_path' => 'self (own nullable firm_id column)',
            'notes' => 'firm_id is nullable: null = global platform template, set = a '
                .'firm\'s own custom template.',
        ],
        'license_files' => [
            'classification' => TenantOwnershipClassification::Hybrid,
            'ownership_path' => 'self (own nullable firm_id column; organization_id also nullable)',
            'notes' => 'Both a firm-level artifact (firm_id + firm_license_id) and an '
                .'organization-level artifact (organization_id + org_license_id) — dual, '
                .'mutually-exclusive-in-practice nullable ownership columns.',
        ],
        'license_validation_events' => [
            'classification' => TenantOwnershipClassification::Hybrid,
            'ownership_path' => 'self (own nullable firm_id column)',
            'notes' => 'Append-only validation log; carries its own nullable firm_id '
                .'alongside license_file_id, mirroring license_files\' own hybrid scope.',
        ],
        'product_analytics_events' => [
            'classification' => TenantOwnershipClassification::Hybrid,
            'ownership_path' => 'self (own nullable firm_id column)',
            'notes' => 'firm_id is nullable — an event may or may not be attributable to a firm.',
        ],
        'retention_policies' => [
            'classification' => TenantOwnershipClassification::Hybrid,
            'ownership_path' => 'self (own nullable firm_id column)',
            'notes' => 'firm_id null = platform default retention policy; firm_id set = a '
                .'firm-specific override of that default.',
        ],

        // --- Global (44 total: 22 already in EXEMPT_TABLES + 22 more) --
        // The 22 tables already in EXEMPT_TABLES (unchanged contents):
        'organizations' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Multi-firm parent account entity — organizations own firms, not '
                .'the reverse. See EXEMPT_TABLE_METADATA for reason/readers/writers.',
        ],
        'billing_accounts' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Commercial billing account, potentially shared across firms under '
                .'one organization. See EXEMPT_TABLE_METADATA.',
        ],
        'plans' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform-wide commercial plan catalog. See EXEMPT_TABLE_METADATA.',
        ],
        'plan_modules' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Plan-to-module_catalog grant row, scoped to plan_id not firm_id. '
                .'See EXEMPT_TABLE_METADATA.',
        ],
        'plan_limits' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Plan-level limit configuration, scoped to plan_id not firm_id. '
                .'See EXEMPT_TABLE_METADATA.',
        ],
        'org_licenses' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Organization-level license record, scoped to organization_id not '
                .'firm_id. See EXEMPT_TABLE_METADATA.',
        ],
        'seat_pools' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Organization/plan-level seat pool. See EXEMPT_TABLE_METADATA.',
        ],
        'license_events' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform-level license event log. See EXEMPT_TABLE_METADATA.',
        ],
        'platform_subscriptions' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform commercial subscription. See EXEMPT_TABLE_METADATA.',
        ],
        'platform_subscription_items' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Line item of a platform_subscriptions row. See EXEMPT_TABLE_METADATA.',
        ],
        'platform_invoices' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform-to-organization/firm commercial invoice. See EXEMPT_TABLE_METADATA.',
        ],
        'platform_payments' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform commercial payment. See EXEMPT_TABLE_METADATA.',
        ],
        'platform_refunds' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform commercial refund. See EXEMPT_TABLE_METADATA.',
        ],
        'platform_payment_attempts' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform commercial payment attempt log. See EXEMPT_TABLE_METADATA.',
        ],
        'platform_billing_events' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform commercial billing event log. See EXEMPT_TABLE_METADATA.',
        ],
        'platform_invoice_lines' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Nullable firm_id used for attribution only — real ownership '
                .'boundary is billing_account_id, per the Phase 6 RLS migration\'s own '
                .'doc comment. See EXEMPT_TABLE_METADATA.',
        ],
        'usage_rollups' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Nullable firm_id used for attribution only — real ownership '
                .'boundary is billing_account_id. See EXEMPT_TABLE_METADATA.',
        ],
        'practice_areas' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform-wide reference catalog. See EXEMPT_TABLE_METADATA.',
        ],
        'matter_types' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform-wide reference catalog. See EXEMPT_TABLE_METADATA.',
        ],
        'template_packs' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform-wide installable template pack catalog. See EXEMPT_TABLE_METADATA.',
        ],
        'template_pack_versions' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Version history of a template_packs row. See EXEMPT_TABLE_METADATA.',
        ],
        'intake_templates' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform-wide reference catalog. See EXEMPT_TABLE_METADATA.',
        ],
        // The 2 Wave 1A EXEMPT_TABLES additions:
        'module_catalog' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Global installable-module reference catalog, addressed by '
                .'module_code, not firm-scoped. Confirmed no firm_id/firm-referencing '
                .'column by direct migration inspection. See EXEMPT_TABLE_METADATA.',
        ],
        'readiness_scorecard_components' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Global pluggable readiness-component registry catalog, addressed '
                .'by component_key, not firm-scoped. Confirmed no firm_id/firm-referencing '
                .'column by direct migration inspection. See EXEMPT_TABLE_METADATA.',
        ],
        // Stage B Checkpoint 2 (FirmsBase Integration Platform mission)
        // addition — see EXEMPT_TABLES docblock above.
        'integration_providers' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform-wide, seeded-only integration provider reference catalog, '
                .'addressed by code, not firm-scoped. Confirmed no firm_id/firm-referencing '
                .'column by direct migration inspection. See EXEMPT_TABLE_METADATA.',
        ],
        // 20 further platform-wide tables classified Global here but
        // NOT added to EXEMPT_TABLES (no human-approved exemption
        // request covers them — that array is reserved for tables with
        // an explicit documented reason/readers/writers decision):
        'ai_policy_settings' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'No firm_id column, no BelongsToTenant — platform-wide AI policy defaults.',
        ],
        'commission_plans' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform sales-commission plan catalog; plan_id nullable, no firm_id.',
        ],
        'fleet_migration_runs' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform-wide fleet migration batch/run; no firm_id (per-firm '
                .'status is tracked separately in fleet_migration_instance_status, a '
                .'DirectTenant table).',
        ],
        'form_edition_watch_items' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'No firm_id at all — no firm ever sees or sets this; platform-admin-only watch queue.',
        ],
        'form_fields' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Child of form_template_versions -> form_templates, a global platform catalog.',
        ],
        'form_mapping_rules' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Child of form_template_versions -> form_templates, a global platform catalog.',
        ],
        'form_template_versions' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Child of form_templates, a global platform catalog.',
        ],
        'form_templates' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'No firm_id at all — a form\'s existence is never firm-specific.',
        ],
        'high_risk_change_requests' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform-admin dual-approval workflow entity; no firm_id at all.',
        ],
        'integration_degradation_modes' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform-wide integration degradation-mode configuration; not firm-scoped, no firm_id, no BelongsToTenant.',
        ],
        'opportunities' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform sales pipeline entity; no firm_id (a firm does not yet exist at this pipeline stage).',
        ],
        'platform_admins' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform staff account entity; no firm_id.',
        ],
        'platform_leads' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform sales pipeline entity; no firm_id.',
        ],
        'platform_roles' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Grant row scoped to platform_admin_id, not firm_id.',
        ],
        'platform_sales_tasks' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform sales-team task entity; no firm_id.',
        ],
        'release_notes' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Explicitly no organization_id/firm_id/plan_id column — release notes must never be firm-specific.',
        ],
        'sales_rep_assignments' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform sales-rep-to-account assignment; scoped to platform_admin_id, not firm_id.',
        ],
        'subprocessors' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Customer-facing disclosure entry linked to vendor_register; platform-wide, no firm_id.',
        ],
        'trial_requests' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Platform sales pipeline entity; organization_id nullable, no firm_id (a firm does not yet exist at trial-request stage).',
        ],
        'vendor_register' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Internal vendor/processor governance record; platform-wide, no firm_id.',
        ],
        // Stage B Checkpoint 7 (FirmsBase Integration Platform mission,
        // "Inbound Webhook Security") additions — see
        // reviews/checkpoint-07/frozen-design-post-security-review.md
        // §5.1/§10.1. Both carry an EXPLICIT DISCLAIMER note overriding
        // this array's usual "Global => no RLS needed, no further
        // scrutiny required" implication: neither table is an ordinary
        // platform-reference catalog. integration_webhook_receipts is
        // confirmed by direct inspection of its own create migration to
        // carry no firm_id or any other firm-referencing column at
        // all — but integration_webhook_routing_index is NOT: it
        // genuinely DOES carry a NOT NULL firm_id column (by design),
        // and was promoted into EXEMPT_TABLES (see that array's own
        // docblock above) as a registry-classification correction; see
        // EXEMPT_TABLE_METADATA below for its documented reason.
        'integration_webhook_routing_index' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'DISCLAIMER: Global, no RLS — but NOT an ordinary platform-reference catalog, and NOT a '
                .'"no firm_id" exemption either: this table genuinely carries a NOT NULL firm_id column (plus '
                .'firm_integration_id), by deliberate design, and is promoted into EXEMPT_TABLES accordingly '
                .'(see EXEMPT_TABLE_METADATA). It must be readable before any tenant context exists at all, '
                .'to bootstrap inbound-webhook connection-identity resolution '
                .'(App\\Integrations\\Services\\WebhookConnectionResolverService). Carries no secret/credential '
                .'material — only a one-way sha256 hash of an opaque routing token. Written only by '
                .'App\\Integrations\\Services\\ProviderConnectionService::enableWebhookRouting()/'
                .'disableWebhookRouting()/disconnect(). See '
                .'database/migrations/2026_09_06_060001_create_integration_webhook_routing_index_table.php '
                .'§10.1 for the full "WHY THIS TABLE HAS NO RLS" reasoning. See EXEMPT_TABLE_METADATA.',
        ],
        // Checkpoint 11 (FirmsBase Integration Platform mission,
        // "SuperAdmin Integration Oversight and Governance") addition —
        // see reviews/checkpoint-11/frozen-design-post-security-review.md
        // §5. Same DISCLAIMER category as integration_webhook_routing_index
        // immediately above: genuinely carries a NOT NULL, UNIQUE firm_id
        // column, exempted anyway for a documented reason — see
        // EXEMPT_TABLE_METADATA.
        'integration_platform_overview_summaries' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'DISCLAIMER: Global, no RLS — but NOT a "no firm_id" exemption: this table genuinely '
                .'carries a NOT NULL, UNIQUE firm_id column (one row per firm), by deliberate design, and is '
                .'exempted from RLS anyway (see EXEMPT_TABLE_METADATA). It backs an always-visible, cross-firm '
                .'SuperAdmin overview list that must remain readable without a per-request, per-firm RLS '
                .'context-switch cost. Every column is a sanitized count/status/timestamp snapshot, never raw '
                .'resource content, a secret, or credential material. Written only by '
                .'App\\Services\\IntegrationPlatformOverviewSummaryService::refreshForFirm() (an upsert-only '
                .'sole writer). See '
                .'database/migrations/2026_09_09_090001_create_integration_platform_overview_summaries_table.php '
                .'for the full "WHY THIS TABLE HAS NO RLS AND NO FORCE RLS" reasoning. See EXEMPT_TABLE_METADATA.',
        ],
        // Phase 2 (FirmsVault Platform Admin Control Center, "Integration
        // Operations Center") addition. Unlike
        // integration_platform_overview_summaries immediately above, this
        // IS an ordinary "no firm_id" Global exemption — no firm_id
        // column exists on this table at all (confirmed via the create
        // migration), structurally identical in shape to
        // `integration_providers`.
        'integration_platform_provider_health_summaries' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Global, no RLS, no firm_id column at all — a per-PROVIDER cross-firm rollup (one row per '
                .'provider), not a per-firm row. Every column is a sanitized count/status/timestamp snapshot, never '
                .'raw resource content, a secret, or credential material. Written only by '
                .'App\\Services\\IntegrationPlatformProviderHealthSummaryService::refreshForProvider() (an '
                .'upsert-only sole writer that iterates every activated firm\'s OWN tenant context via '
                .'TenantContextService::runWithFirmContext() to build the aggregate — never a live cross-firm '
                .'query). See '
                .'database/migrations/2026_09_11_110001_create_integration_platform_provider_health_summaries_table.php '
                .'for the full "WHY THIS TABLE HAS NO RLS AND NO FORCE RLS" reasoning.',
        ],
        // FirmsVault Live Integrations, Checkpoint 3 (Google Workspace
        // provider) addition — checkpoint3-combined-design.md §5/§6.4.3;
        // checkpoint3-security-review.md Finding 3. Same DISCLAIMER
        // category as integration_webhook_routing_index above: genuinely
        // carries a NOT NULL firm_id column, exempted anyway for a
        // documented reason — see EXEMPT_TABLE_METADATA.
        'integration_gmail_mailbox_routes' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'DISCLAIMER: Global, no RLS — but NOT a "no firm_id" exemption either: this table '
                .'genuinely carries a NOT NULL firm_id column (plus firm_integration_id), by deliberate design, '
                .'and is exempted from RLS anyway (see EXEMPT_TABLE_METADATA). Structural sibling of '
                .'integration_webhook_routing_index, for the identical reason: it must be readable before any '
                .'tenant context exists at all, to bootstrap Gmail Cloud Pub/Sub inbound-webhook mailbox-to-'
                .'connection routing (App\\Integrations\\Support\\GmailMailboxRoutingService::resolveByMailbox()). '
                .'Carries no secret material — only a keyed HMAC-SHA256 lookup digest of a normalized mailbox '
                .'address and an already-encrypted display-value ciphertext, never a plaintext mailbox. Written '
                .'only by App\\Integrations\\Support\\GmailMailboxRoutingService::route()/unroute(). See '
                .'database/migrations/2026_09_23_170001_create_integration_gmail_mailbox_routes_table.php for the '
                .'full "WHY THIS TABLE HAS NO RLS" reasoning, cross-referencing '
                .'2026_09_06_060001_create_integration_webhook_routing_index_table.php directly. See '
                .'EXEMPT_TABLE_METADATA.',
        ],
        // FirmsVault Live Integrations, Checkpoint 4 ("Plaid Financial
        // Evidence add-on", cost-control/billing track) additions —
        // checkpoint4-design-cost-control.md §1.1/§4.1/§6;
        // checkpoint4-combined-design.md §1.7/§1.8/§10. Unlike
        // integration_webhook_routing_index/integration_gmail_mailbox_routes
        // immediately above, all four of these ARE ordinary "no firm_id
        // at all" exemptions — none carries a firm_id column of any
        // kind, confirmed directly against each one's own create
        // migration. Every one is platform-admin-authored reference/
        // incident-response/reconciliation data, never firm-panel-
        // writable — even a `provider_rate_card_entries` `firm_override`
        // row's `scope_id` merely POINTS AT a firm; the row itself is
        // platform reference data, mirroring `Plan`/`PlanModule`'s own
        // "platform reference/commercial data, not owned by one firm"
        // framing.
        'provider_rate_card_entries' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Global, no RLS — platform-admin-authored rate-card reference data, effective-dated, '
                .'three-tier scoped (platform_default/package_default/firm_override). No firm_id column exists '
                .'(a nullable scope_id, which for firm_override rows POINTS AT a firm without the row itself '
                .'being tenant-owned). Confirmed no firm_id/firm-referencing column by direct migration '
                .'inspection (database/migrations/2026_09_24_500001_create_provider_rate_card_entries_table.php). '
                .'Sole reader: App\\Integrations\\Billing\\ProviderRateCardResolver. See EXEMPT_TABLE_METADATA.',
        ],
        'provider_kill_switches' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Global, no RLS — the incident-response kill-switch surface, admin-panel-mutated only. '
                .'No firm_id column exists (a nullable scope_id, used only for the platform-scope rows this '
                .'pipeline actually reads — see database/migrations/2026_09_24_500004_create_provider_kill_switches_table.php '
                .'for why a firm-scope row is never written/read by this checkpoint\'s own resolver). Must be '
                .'checked on every pipeline run for every firm, so it cannot itself be firm-scoped. Sole reader: '
                .'App\\Integrations\\Billing\\ProviderOperationPolicyResolver. See EXEMPT_TABLE_METADATA.',
        ],
        'provider_operation_default_policies' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Global, no RLS — the platform-default half of the coordinator-resolved two-table split '
                .'(checkpoint4-combined-design.md §1.8); the firm-editable half, provider_firm_operation_policies, '
                .'is Direct BelongsToTenant + FORCE RLS instead (see PREPARED_TABLES). No firm_id column exists '
                .'by design — one row per (provider_key, product, environment), never per firm. Confirmed no '
                .'firm_id/firm-referencing column by direct migration inspection '
                .'(database/migrations/2026_09_24_500005_create_provider_operation_default_policies_table.php). '
                .'Sole reader: App\\Integrations\\Billing\\ProviderOperationPolicyResolver. See EXEMPT_TABLE_METADATA.',
        ],
        'provider_invoice_reconciliations' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Global, no RLS — platform-scoped like Plan, modeled directly on TrustReconciliation\'s '
                .'own shape but never firm-owned: a real provider invoice covers all firms\' aggregated usage. '
                .'No firm_id column exists. Confirmed no firm_id/firm-referencing column by direct migration '
                .'inspection (database/migrations/2026_09_24_500010_create_provider_invoice_reconciliations_table.php). '
                .'Sole writer: App\\Services\\ProviderInvoiceReconciliationService::run(). See EXEMPT_TABLE_METADATA.',
        ],
        'integration_webhook_receipts' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'DISCLAIMER: Global, no RLS — platform pre-tenant intake table, structurally incapable '
                .'of holding tenant-identifying columns (no firm_id/firm_integration_id column exists, and '
                .'none may ever be added — see the create migration\'s tenant-blindness note). NOT an '
                .'ordinary platform-reference catalog: this is the durable record of every inbound webhook '
                .'delivery whose routing token resolved, written before real tenant context is established. '
                .'The legitimate path to discover which firm a delivery belonged to is '
                .'integration_inbound_webhook_events.receipt_id pointing BACK to a specific receipt row — a '
                .'forward pointer here would only add a de-anonymization channel. See '
                .'database/migrations/2026_09_06_060002_create_integration_webhook_receipts_table.php §10.1 '
                .'for the full reasoning, including why a session-GUC-gated RLS alternative was explicitly '
                .'rejected (agent-7h-security-design-review.md §1.3).',
        ],
        // Checkpoint 1 (FirmsVault Live Integrations,
        // checkpoint1-design-health-sandbox.md §A.3.3) addition — same
        // DISCLAIMER category as integration_webhook_receipts
        // immediately above: platform pre-tenant, structurally
        // incapable of holding a tenant-identifying column, since a
        // rejected inbound webhook frequently cannot be attributed to
        // any resolved connection at all (an attacker-supplied
        // routing token that never maps to a real connection).
        'integration_webhook_verification_failures' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'DISCLAIMER: Global, no RLS — platform pre-tenant counter table, structurally '
                .'incapable of holding tenant-identifying columns for the same reason as '
                .'integration_webhook_receipts immediately above: a rejected inbound webhook delivery '
                .'frequently cannot be attributed to a resolved firm_integration_id at all. Sole writer is '
                .'App\\Integrations\\Jobs\\RecordWebhookVerificationFailureJob, dispatched (never a '
                .'synchronous write) from App\\Integrations\\Http\\Controllers\\InboundWebhookController\'s '
                .'rejection branches — see '
                .'database/migrations/2026_09_13_130001_create_integration_webhook_verification_failures_table.php '
                .'for the full "WHY THIS TABLE HAS NO RLS" reasoning.',
        ],

        // --- Audit (4) — pure platform-wide append-only event logs -----
        'commission_events' => [
            'classification' => TenantOwnershipClassification::Audit,
            'ownership_path' => null,
            'notes' => 'Platform commission event log tied to billing_account_id/platform_admin_id, not firm_id.',
        ],
        'conversion_events' => [
            'classification' => TenantOwnershipClassification::Audit,
            'ownership_path' => null,
            'notes' => 'Platform sales-funnel conversion event log; no firm_id.',
        ],
        'demo_events' => [
            'classification' => TenantOwnershipClassification::Audit,
            'ownership_path' => null,
            'notes' => 'Platform sales-demo event log tied to opportunity_id; no firm_id.',
        ],
        'status_page_events' => [
            'classification' => TenantOwnershipClassification::Audit,
            'ownership_path' => null,
            'notes' => 'Platform-level status-page event log; deliberately no firm_id.',
        ],

        // --- System (8) — Laravel/framework infrastructure tables -------
        'cache' => [
            'classification' => TenantOwnershipClassification::System,
            'ownership_path' => null,
            'notes' => 'Laravel default cache store table.',
        ],
        'cache_locks' => [
            'classification' => TenantOwnershipClassification::System,
            'ownership_path' => null,
            'notes' => 'Laravel default cache-lock table.',
        ],
        'sessions' => [
            'classification' => TenantOwnershipClassification::System,
            'ownership_path' => null,
            'notes' => 'Laravel default session-driver table.',
        ],
        'jobs' => [
            'classification' => TenantOwnershipClassification::System,
            'ownership_path' => null,
            'notes' => 'Laravel default queue table.',
        ],
        'job_batches' => [
            'classification' => TenantOwnershipClassification::System,
            'ownership_path' => null,
            'notes' => 'Laravel default queue batch table.',
        ],
        'failed_jobs' => [
            'classification' => TenantOwnershipClassification::System,
            'ownership_path' => null,
            'notes' => 'Laravel default failed-queue-job table.',
        ],
        'password_reset_tokens' => [
            'classification' => TenantOwnershipClassification::System,
            'ownership_path' => null,
            'notes' => 'Laravel default auth-scaffolding table.',
        ],
        // FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial
        // evidence add-on") addition — client_portal_password_reset_tokens,
        // byte-for-byte the same shape as the stock password_reset_tokens
        // table immediately above (email primary key, token, created_at
        // only), backing the client guard's own password broker
        // (config/auth.php `passwords.client_portal_users`). No firm_id,
        // no RLS — looked up by email BEFORE any authentication (and
        // therefore before any tenant context) can exist, the identical
        // reasoning stock password_reset_tokens itself has no RLS.
        'client_portal_password_reset_tokens' => [
            'classification' => TenantOwnershipClassification::System,
            'ownership_path' => null,
            'notes' => 'Client Portal (client guard) password-reset-token table — same '
                .'pre-tenant-context, no-RLS shape as the stock password_reset_tokens table '
                .'immediately above.',
        ],
        'users' => [
            'classification' => TenantOwnershipClassification::System,
            'ownership_path' => null,
            'notes' => 'Laravel default auth user table — a global identity table (firm '
                .'staff, platform admins, and other actors all reference users.id); '
                .'firm-specific staff membership itself lives in firm_users, a '
                .'DirectTenant table.',
        ],
        // FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial
        // evidence add-on") reclassification — client_portal_users was
        // originally shipped as InheritedTenant with real FORCE ROW
        // LEVEL SECURITY (a subquery-shaped tenant-isolation policy plus
        // a self-lookup policy); that design is a confirmed defect
        // (see ClientPortalAuthenticationTest's own docblock for the
        // full empirical reproduction: neither policy permits
        // Auth::guard('client')->attempt()/Password::broker(...)'s
        // retrieveByCredentials() to find a row BY EMAIL with no
        // context at all, which is the unavoidable first step of any
        // login or password reset). Corrected to System, identical
        // treatment to 'users' immediately above — same role, one level
        // down: a global credential/identity table, not a business-data
        // table. The real tenant boundary lives one level below this
        // table instead, in Client (already FORCE-RLS protected) and
        // client_portal_matter_grants (already FORCE-RLS protected,
        // DirectTenant, own firm_id column) — exactly how 'users' has
        // no RLS while firm_users (the real tenant-scoped membership
        // table) does. See this table's own create-migration
        // (2026_09_24_180001_create_client_portal_users_table.php)
        // docblock's "WHY THIS TABLE HAS NO RLS" section for the full
        // corrected reasoning.
        'client_portal_users' => [
            'classification' => TenantOwnershipClassification::System,
            'ownership_path' => null,
            'notes' => 'Client Portal (client guard) credential/identity table — a global '
                .'identity table exactly mirroring users\' own role, one level down: no '
                .'firm_id, no RLS. The real tenant boundary lives in Client and '
                .'client_portal_matter_grants (both still FORCE-RLS protected), not here — '
                .'same split as users (no RLS) versus firm_users (RLS, real membership).',
        ],
        // FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial
        // evidence add-on", Plaid provider-core track) addition —
        // checkpoint4-combined-design.md §1.1.1 (binding "Option B");
        // checkpoint4-design-plaid-provider-core.md §11.2. Same
        // DISCLAIMER category as integration_webhook_routing_index/
        // integration_gmail_mailbox_routes above: genuinely carries a
        // NOT NULL firm_id column, exempted anyway for a documented
        // reason — see EXEMPT_TABLE_METADATA.
        'integration_plaid_item_routes' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'DISCLAIMER: Global, no RLS — but NOT a "no firm_id" exemption either: this table '
                .'genuinely carries a NOT NULL firm_id column (plus firm_integration_id), by deliberate design, '
                .'and is exempted from RLS anyway (see EXEMPT_TABLE_METADATA). Structural sibling of '
                .'integration_webhook_routing_index/integration_gmail_mailbox_routes, for the identical reason: '
                .'it must be readable before any tenant context exists at all, to bootstrap Plaid inbound-webhook '
                .'item_id-to-connection routing (App\\Integrations\\Support\\PlaidItemRoutingService::resolveByItemId()). '
                .'Carries no secret material — only a keyed HMAC-SHA256 lookup digest of a Plaid item_id and an '
                .'already-encrypted display-value ciphertext, never a plaintext item_id. Written only by '
                .'App\\Integrations\\Support\\PlaidItemRoutingService::route()/unroute(). See '
                .'database/migrations/2026_09_24_180001_create_integration_plaid_item_routes_table.php for the '
                .'full "WHY THIS TABLE HAS NO RLS" reasoning. See EXEMPT_TABLE_METADATA.',
        ],
        // FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial
        // evidence add-on", Financial Evidence Workspace UI track)
        // addition — checkpoint4-combined-design.md §1.6 (found-and-
        // fixed RLS misclassification). UNLIKE integration_plaid_item_routes
        // immediately above, this IS an ordinary "no firm_id at all"
        // exemption — direct migration inspection confirms no firm_id
        // column exists (only a nullable scope_id, which for
        // firm_override rows POINTS AT a firm without the row itself
        // being tenant-owned).
        'financial_evidence_large_deposit_thresholds' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Global, no RLS — mirrors provider_rate_card_entries\' own platform_default -> '
                .'firm_override scope-precedence pattern exactly (checkpoint4-combined-design.md §1.6\'s binding '
                .'reconciliation: the source doc\'s own blanket "all Direct BelongsToTenant + FORCE RLS" rule '
                .'conflicted with its own explicit statement that this table\'s scope shape is identical to '
                .'provider_rate_card_entries; resolved by following the more-precedented Global/no-RLS option, '
                .'since a plain FORCE RLS policy structurally cannot represent a firm-agnostic platform_default '
                .'row). No firm_id column exists — confirmed by direct migration inspection '
                .'(database/migrations/2026_09_25_190017_create_financial_evidence_large_deposit_thresholds_table.php). '
                .'Sole reader: App\\Integrations\\Services\\FinancialEvidenceLargeDepositDetectionService. See '
                .'EXEMPT_TABLE_METADATA.',
        ],
        // FirmsVault Live Integrations, Checkpoint 8.2 §A4 addition — the
        // FK-free durable at-most-once provider-call gate. Classified
        // Global (no RLS), the same treatment as its exempt siblings
        // integration_webhook_routing_index/integration_gmail_mailbox_routes/
        // integration_plaid_item_routes, which likewise carry a real
        // firm_id column that is a non-authoritative correlation pointer
        // rather than a security boundary.
        'provider_operation_attempts' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Global, no RLS — the durable at-most-once gate for outbound provider calls, written on a '
                .'database session deliberately INDEPENDENT of the calling job\'s transaction and row locks so that '
                .'"a request was already sent" survives a rollback or crash (FirmsVault Live Integrations '
                .'Checkpoint 8.2 §A4). Carries a real NOT NULL firm_id scalar (like '
                .'integration_webhook_routing_index) but NO foreign keys at all — Checkpoint 8.1 proved that a '
                .'cross-session INSERT whose FK references a row PullSyncJob holds FOR UPDATE deadlocks in '
                .'production. A firm-keyed policy would require tenant context on that independent session for '
                .'every read, including the pre-claim probe that runs before any firm context necessarily exists, '
                .'reintroducing exactly that coupling. Sole reader and sole writer: '
                .'App\\Integrations\\Billing\\ProviderOperationAttemptService, always filtered on the scalar '
                .'firm_id. Operational evidence only — never a source of truth for money owed. See '
                .'EXEMPT_TABLE_METADATA and the create migration\'s own docblock.',
        ],
        // feature/ses-event-consumer addition — real NOT NULL firm_id
        // column, exempted anyway — see EXEMPT_TABLES/EXEMPT_TABLE_METADATA.
        'notification_provider_correlations' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'DISCLAIMER: Global, no RLS — but NOT a "no firm_id" exemption either: this table '
                .'genuinely carries a NOT NULL firm_id column, by deliberate design, and is exempted from RLS '
                .'anyway (see EXEMPT_TABLE_METADATA). Structural sibling of integration_webhook_routing_index/'
                .'integration_gmail_mailbox_routes, for the identical reason: it must be readable before any '
                .'tenant context exists at all, to bootstrap SesEventConsumerService\'s firm resolution when an '
                .'SES bounce/complaint event arrives on the shared SQS queue. Populated at outbound-send time by '
                .'App\\Services\\OutboundMailCorrelationService::correlate(). Carries no secret material — only '
                .'{correlation_id (opaque uuid), firm_id, channel, recipient_normalized, provider_message_id}. '
                .'See database/migrations/2026_10_15_100002_create_notification_provider_correlations_table.php.',
        ],
        // feature/ses-event-consumer addition — no firm_id column at
        // all; ordinary "no firm_id" Global exemption, structurally
        // identical to integration_platform_provider_health_summaries.
        'ses_event_receipts' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Global, no RLS, no firm_id column at all — the durable idempotency ledger for the SES '
                .'bounce/complaint consumer (SesEventConsumerService), keyed by {eventType}:{feedbackId ?? '
                .'mail.messageId} so a retried SQS delivery of the same underlying SES event is never processed '
                .'twice. One row per already-processed event; carries no PII, no email content, and no secret '
                .'material — only event-type, provider/queue message ids, and a processed_at timestamp. Written '
                .'only by SesEventConsumerService after the corresponding suppression/business logic has already '
                .'succeeded durably. See database/migrations/2026_10_15_100003_create_ses_event_receipts_table.php.',
        ],
        // post-578ee98 audit remediation (finding H1) — no firm_id
        // column at all; ordinary "no firm_id" Global exemption,
        // structurally identical to ses_event_receipts.
        'platform_notification_correlations' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Global, no RLS, no firm_id column at all — the platform-scope correlation ledger for '
                .'governed real sends (today: password-reset notifications) that could not resolve an owning firm '
                .'at send time. Uses a plain account_type/account_id morph pair to identify which User/'
                .'ClientPortalUser a correlation belongs to, never a firm. recipient_fingerprint is a keyed '
                .'HMAC-SHA256, never plaintext. Written only by PlatformNotificationCorrelationService::correlate(). '
                .'See database/migrations/2026_10_20_100001_create_platform_notification_correlations_table.php.',
        ],
        'platform_notification_suppressions' => [
            'classification' => TenantOwnershipClassification::Global,
            'ownership_path' => null,
            'notes' => 'Global, no RLS, no firm_id column at all — the platform-scope analogue of a suppressed '
                .'recipient, independent of SuppressionService/notification_events (firm-scoped, FORCE-RLS). One '
                .'row per suppressed recipient_fingerprint (a keyed HMAC, never plaintext), upserted by '
                .'PlatformNotificationCorrelationService::recordOutcome(). See '
                .'database/migrations/2026_10_20_100002_create_platform_notification_suppressions_table.php.',
        ],
    ];

    /**
     * Reason, expected readers, and authorized writers for every one
     * of the (now 27) EXEMPT_TABLES entries. Readers/writers are
     * expressed as human-readable role/class descriptions, not a
     * runtime-enforced allowlist — this is documentation, mirroring
     * how the rest of this registry is declarative-only.
     *
     * @var array<string, array{reason: string, expected_readers: array<int, string>, authorized_writers: array<int, string>}>
     */
    private const EXEMPT_TABLE_METADATA = [
        'organizations' => [
            'reason' => 'Global parent-account entity above the firm level; organizations own firms, not the reverse, so a firm-keyed policy cannot apply.',
            'expected_readers' => ['platform admins (org/billing panels)', 'FirmActivationService and other org-aware onboarding services'],
            'authorized_writers' => ['platform admins via the platform-admin panel', 'OrganizationService-class onboarding flows'],
        ],
        'billing_accounts' => [
            'reason' => 'Commercial billing entity that can be shared across multiple firms under one organization; no per-firm boundary exists.',
            'expected_readers' => ['platform admins (billing panels)', 'billing/invoicing services'],
            'authorized_writers' => ['platform admins via the platform-admin panel', 'billing onboarding services'],
        ],
        'plans' => [
            'reason' => 'Platform-wide commercial plan catalog shared by every organization/firm; not firm-scoped data.',
            'expected_readers' => ['EntitlementService/PlanModuleService and any firm-facing plan-limit check', 'platform admins'],
            'authorized_writers' => ['platform admins via the platform-admin panel'],
        ],
        'plan_modules' => [
            'reason' => 'Which module_catalog modules a plan grants — scoped to plan_id, a global entity, not firm_id.',
            'expected_readers' => ['EntitlementPlanSyncService', 'PlanModuleService'],
            'authorized_writers' => ['platform admins via the platform-admin panel'],
        ],
        'plan_limits' => [
            'reason' => 'Plan-level limit configuration — scoped to plan_id, a global entity, not firm_id.',
            'expected_readers' => ['entitlement/limit-enforcement services'],
            'authorized_writers' => ['platform admins via the platform-admin panel'],
        ],
        'org_licenses' => [
            'reason' => 'Organization-level license record — scoped to organization_id, not firm_id.',
            'expected_readers' => ['license validation/entitlement services'],
            'authorized_writers' => ['platform admins via the platform-admin panel'],
        ],
        'seat_pools' => [
            'reason' => 'Organization/plan-level seat pool shared across firms; not firm-scoped.',
            'expected_readers' => ['SeatAllocationService and related seat-management services'],
            'authorized_writers' => ['platform admins via the platform-admin panel'],
        ],
        'license_events' => [
            'reason' => 'Platform-level license lifecycle event log; not firm-scoped.',
            'expected_readers' => ['license/audit reporting services', 'platform admins'],
            'authorized_writers' => ['license-issuance and validation services'],
        ],
        'platform_subscriptions' => [
            'reason' => 'Platform commercial subscription record; billing_account_id is the real ownership boundary, not firm_id.',
            'expected_readers' => ['billing/subscription services', 'platform admins'],
            'authorized_writers' => ['platform admins via the platform-admin panel', 'subscription-lifecycle services'],
        ],
        'platform_subscription_items' => [
            'reason' => 'Line item of a platform_subscriptions row; same ownership boundary as its parent.',
            'expected_readers' => ['billing/subscription services'],
            'authorized_writers' => ['subscription-lifecycle services'],
        ],
        'platform_invoices' => [
            'reason' => 'Platform commercial invoice; billing_account_id is the real ownership boundary, not firm_id.',
            'expected_readers' => ['PlatformInvoiceService and billing reporting', 'platform admins'],
            'authorized_writers' => ['PlatformInvoiceService'],
        ],
        'platform_payments' => [
            'reason' => 'Platform commercial payment; billing_account_id is the real ownership boundary, not firm_id.',
            'expected_readers' => ['billing/payment reconciliation services', 'platform admins'],
            'authorized_writers' => ['platform payment-processing services'],
        ],
        'platform_refunds' => [
            'reason' => 'Platform commercial refund; billing_account_id is the real ownership boundary, not firm_id.',
            'expected_readers' => ['billing/payment reconciliation services'],
            'authorized_writers' => ['platform admins via the platform-admin panel', 'platform payment-processing services'],
        ],
        'platform_payment_attempts' => [
            'reason' => 'Platform commercial payment attempt log; billing_account_id is the real ownership boundary, not firm_id.',
            'expected_readers' => ['billing/payment reconciliation services'],
            'authorized_writers' => ['platform payment-processing services'],
        ],
        'platform_billing_events' => [
            'reason' => 'Platform commercial billing event log; billing_account_id is the real ownership boundary, not firm_id.',
            'expected_readers' => ['billing reporting/audit services', 'platform admins'],
            'authorized_writers' => ['platform billing services'],
        ],
        'platform_invoice_lines' => [
            'reason' => 'Nullable firm_id for attribution only — the real ownership boundary is billing_account_id, per the Phase 6 RLS migration\'s own doc comment.',
            'expected_readers' => ['PlatformInvoiceService and billing reporting'],
            'authorized_writers' => ['PlatformInvoiceService'],
        ],
        'usage_rollups' => [
            'reason' => 'Nullable firm_id for attribution only — the real ownership boundary is billing_account_id.',
            'expected_readers' => ['UsageRollupService', 'billing reporting services'],
            'authorized_writers' => ['UsageRollupService'],
        ],
        'practice_areas' => [
            'reason' => 'Platform-wide reference catalog shared by every firm; not firm-scoped data.',
            'expected_readers' => ['every firm-facing service that lists practice areas'],
            'authorized_writers' => ['platform admins via the platform-admin panel'],
        ],
        'matter_types' => [
            'reason' => 'Platform-wide reference catalog shared by every firm; not firm-scoped data.',
            'expected_readers' => ['every firm-facing service that lists matter types'],
            'authorized_writers' => ['platform admins via the platform-admin panel'],
        ],
        'template_packs' => [
            'reason' => 'Platform-wide installable template pack catalog; not firm-scoped (a firm\'s own installation is tracked separately in installed_template_packs, a DirectTenant table).',
            'expected_readers' => ['TemplatePackCommercialService and installation services'],
            'authorized_writers' => ['platform admins via the platform-admin panel'],
        ],
        'template_pack_versions' => [
            'reason' => 'Version history of a template_packs row; same global scope as its parent.',
            'expected_readers' => ['TemplateUpgradeService and installation services'],
            'authorized_writers' => ['platform admins via the platform-admin panel'],
        ],
        'intake_templates' => [
            'reason' => 'Platform-wide reference catalog shared by every firm; not firm-scoped data.',
            'expected_readers' => ['intake submission services'],
            'authorized_writers' => ['platform admins via the platform-admin panel'],
        ],
        'module_catalog' => [
            'reason' => 'Global installable-module reference catalog (approved decision — see the migration\'s own doc comment); addressed by module_code, never firm-scoped. Confirmed by direct migration inspection to carry no firm_id or any other firm-referencing column (database/migrations/2026_07_04_300001_create_module_catalog_table.php).',
            'expected_readers' => [
                'FirmEntitlement/PlanModule models (belongsTo module_code)',
                'EntitlementPlanSyncService',
                'per-module *EntitlementPolicyService classes (AiEntitlementPolicyService, WebhookEntitlementPolicyService, AccountingEntitlementPolicyService, FormAndDocumentAccessPolicyService, SignatureAndPdfAccessPolicyService, EmailAccessPolicyService, ApiAccessPolicyService, PdfAnnotationService)',
            ],
            'authorized_writers' => [
                'platform engineering only, via a data-seeding migration when a new module ships (e.g. 2026_07_09_900023_seed_phase6_module_catalog_entries.php, 2026_07_21_900006_seed_phase14_module_catalog_webhook_entry.php, 2026_07_23_900009_seed_phase15_module_catalog_ai_entry.php) — no runtime create/update path exists anywhere in app/ (confirmed by direct search)',
            ],
        ],
        'readiness_scorecard_components' => [
            'reason' => 'Global pluggable readiness-component registry catalog (per the migration\'s own doc comment: "registering a new component is a data row plus registry code, never a schema change"); addressed by component_key, never firm-scoped. Confirmed by direct migration inspection to carry no firm_id or any other firm-referencing column (database/migrations/2026_07_07_800013_create_readiness_scorecard_components_table.php).',
            'expected_readers' => ['ReadinessScorecardRegistry::evaluate() (reads active component_key rows to decide which registered evaluators to run)'],
            'authorized_writers' => [
                'platform engineering only, via a data-seeding migration when a new readiness component ships — no runtime create/update path exists anywhere in app/ (confirmed by direct search; ReadinessScorecardRegistry only ever queries this table, it never writes to it)',
            ],
        ],
        'integration_providers' => [
            'reason' => 'Global, platform-wide, seeded-only integration provider reference catalog (Stage B Checkpoint 2 of the FirmsBase Integration Platform mission — matches module_catalog\'s exact table-design pattern per the migration\'s own doc comment); addressed by code, never firm-scoped. Confirmed by direct migration inspection to carry no firm_id or any other firm-referencing column (database/migrations/2026_09_01_010001_create_integration_providers_table.php). This is a code-defined-registry-backed seeded catalog per App\Integrations\Core\ProviderRegistry, and is never DB-editable at runtime by application logic.',
            'expected_readers' => [
                'App\Integrations\Core\ProviderRegistry-aware services and panels presenting provider metadata (display name, category, auth method, documentation-only OAuth scope and webhook event-type lists)',
            ],
            'authorized_writers' => [
                'platform engineering only, via this table\'s own seeding migration (2026_09_01_010001_create_integration_providers_table.php) — no runtime create/update path exists anywhere in app/ (confirmed by direct search)',
            ],
        ],
        'integration_webhook_routing_index' => [
            'reason' => 'Stage B Checkpoint 7 registry-classification correction: unlike every other EXEMPT_TABLES '
                .'entry, this table genuinely carries a NOT NULL firm_id column '
                .'($table->foreignId(\'firm_id\')->constrained(\'firms\')->cascadeOnDelete(), per '
                .'database/migrations/2026_09_06_060001_create_integration_webhook_routing_index_table.php) — it '
                .'is exempt from RLS despite that, not because it lacks it. The independent security reviewer '
                .'for this checkpoint (agent-7h-security-design-review.md §1.3) explicitly reviewed and approved '
                .'this as a deliberate, narrow exception, for three reasons: (1) it holds no secret or credential '
                .'material of any kind — only {firm_id, firm_integration_id, integration_provider_id, '
                .'webhook_routing_token_hash}, and the hash is a one-way sha256 digest of an opaque routing '
                .'token, never the token itself; (2) it MUST be queryable in a genuinely pre-tenant-context '
                .'bootstrap step — an inbound webhook request arrives before any firm identity is authenticated, '
                .'so app.current_firm_id cannot be SET LOCAL before this exact lookup resolves which firm the '
                .'request belongs to, and a FORCE RLS policy here would make the entire bounded connection-'
                .'identity-resolution mechanism (App\\Integrations\\Services\\WebhookConnectionResolverService) '
                .'structurally impossible without a SECURITY DEFINER function or a session-GUC-gated carve-out '
                .'policy, both explicitly rejected by that review; and (3) the firm_id read back from this table '
                .'is never treated as authoritative on its own — every subsequent step re-establishes and '
                .'re-verifies real tenant context via the ordinary, unmodified '
                .'TenantContextService::runWithFirmContext() before anything RLS-protected is touched, so this '
                .'table\'s firm_id is a non-authoritative routing pointer only, not a security boundary. Same '
                .'justification category as integration_providers (Global, no-RLS), with the added nuance of '
                .'carrying a firm_id column purely for pre-authentication routing.',
            'expected_readers' => [
                'App\\Integrations\\Services\\WebhookConnectionResolverService::resolveConnectionIdentity() — the sole pre-tenant-context read, returning only {firm_id, firm_integration_id, integration_provider_id, provider_key}, never a secret or hydrated model',
            ],
            'authorized_writers' => [
                'App\\Integrations\\Services\\ProviderConnectionService::enableWebhookRouting()/disableWebhookRouting()/disconnect() — always in the same transaction that writes/clears firm_integrations.webhook_routing_token, so the plaintext-display column and this hashed-lookup row can never drift',
            ],
        ],
        'integration_platform_overview_summaries' => [
            'reason' => 'Checkpoint 11 (SuperAdmin Integration Oversight and Governance): unlike every "no firm_id" '
                .'EXEMPT_TABLES entry above (except integration_webhook_routing_index), this table genuinely '
                .'carries a NOT NULL, UNIQUE firm_id column '
                .'($table->foreignId(\'firm_id\')->constrained(\'firms\')->cascadeOnDelete()->unique(), per '
                .'database/migrations/2026_09_09_090001_create_integration_platform_overview_summaries_table.php) '
                .'— it is exempt from RLS despite that, not because it lacks it. Reviewed and approved as a '
                .'deliberate, narrow exception for three reasons: (1) it must be readable without a per-request, '
                .'per-firm RLS context-switch cost, backing an always-visible SuperAdmin overview list over a firm '
                .'population of undocumented/unbounded size — a FORCE RLS policy here would make a single '
                .'cross-firm overview query structurally impossible without a SECURITY DEFINER function or a '
                .'session-GUC-gated carve-out policy, both explicitly rejected for this mission; (2) every column '
                .'is a sanitized count/status/timestamp snapshot only — connection counts, a derived health-state '
                .'label, last sync outcome/timestamp, failure/dead-letter/conflict counts, an entitlement flag, '
                .'and a computed_at staleness marker — never raw resource content, a secret, or credential '
                .'material of any kind; and (3) there is exactly one writer, an upsert-only scheduled per-firm '
                .'refresh job, so there is no live-write surface to protect. This table is never treated as '
                .'authoritative for any per-firm LIVE drill-down (health, sync history, conflicts, etc.) — those '
                .'reads always go through the ordinary, unmodified TenantContextService::runWithFirmContext() '
                .'against the real, FORCE-RLS-protected tenant tables instead; this table\'s own firm_id is a '
                .'convenience join/denormalization key for the overview list only, not a security boundary.',
            'expected_readers' => [
                'App\\Filament\\Pages\\PlatformIntegrationOverviewPage (the always-visible, cross-firm SuperAdmin overview list) via App\\Services\\IntegrationPlatformOversightReadService',
            ],
            'authorized_writers' => [
                'App\\Services\\IntegrationPlatformOverviewSummaryService::refreshForFirm() — invoked exclusively by App\\Jobs\\RefreshIntegrationPlatformOverviewSummaryJob, one job per activated firm, dispatched by the integrations:platform-overview:refresh scheduled command',
            ],
        ],
        'integration_platform_provider_health_summaries' => [
            'reason' => 'Phase 2 (FirmsVault Platform Admin Control Center, "Integration Operations Center"): unlike '
                .'integration_platform_overview_summaries/integration_webhook_routing_index, this table carries NO '
                .'firm_id column at all — an ordinary "no firm_id" Global exemption, structurally identical in shape '
                .'to integration_providers (the table it foreign-keys to). It is a per-PROVIDER cross-firm rollup '
                .'(one row per provider), never a per-firm row. Every column is a sanitized count/status/timestamp '
                .'snapshot only — connection counts, a derived oauth/webhook/rate-limit health signal, a '
                .'recent-error-classification count summary, and a computed_at staleness marker — never raw '
                .'resource content, a secret, or credential material of any kind. There is exactly one writer, an '
                .'upsert-only scheduled per-provider refresh job, which itself iterates every activated firm\'s OWN '
                .'tenant context via TenantContextService::runWithFirmContext() to build the aggregate (structurally '
                .'required, not optional: integration_connection_health is FORCE-RLS\'d per firm, so a live '
                .'cross-firm query against it is not possible at all) — never a live, unscoped cross-firm query. See '
                .'database/migrations/2026_09_11_110001_create_integration_platform_provider_health_summaries_table.php '
                .'for the full "WHY THIS TABLE HAS NO RLS AND NO FORCE RLS" reasoning.',
            'expected_readers' => [
                'App\\Filament\\Pages\\PlatformIntegrationProviderHealthPage (the always-visible, cross-firm SuperAdmin Provider Health view, built in a later Phase 2 UI pass) via a future read service',
            ],
            'authorized_writers' => [
                'App\\Services\\IntegrationPlatformProviderHealthSummaryService::refreshForProvider() — invoked exclusively by App\\Jobs\\RefreshIntegrationPlatformProviderHealthSummaryJob, one job per registered provider, dispatched by the integrations:platform-provider-health:refresh scheduled command',
            ],
        ],
        // FirmsVault Live Integrations, Checkpoint 3 ("Add Google
        // Workspace integration provider") addition —
        // checkpoint3-combined-design.md §5/§6.4.3;
        // checkpoint3-security-review.md Finding 3, required.
        'integration_gmail_mailbox_routes' => [
            'reason' => 'FirmsVault Live Integrations, Checkpoint 3 (Google Workspace provider): like '
                .'integration_webhook_routing_index, this table genuinely carries a NOT NULL firm_id column '
                .'($table->foreignId(\'firm_id\')->constrained(\'firms\')->cascadeOnDelete(), per '
                .'database/migrations/2026_09_23_170001_create_integration_gmail_mailbox_routes_table.php) — it '
                .'is exempt from RLS despite that, not because it lacks it. Reviewed and approved as a '
                .'deliberate, narrow exception, for the identical three-reason structure '
                .'integration_webhook_routing_index\'s own exemption already established: (1) it holds no secret '
                .'or credential material of any kind — only {firm_id, firm_integration_id, '
                .'integration_provider_id, mailbox_lookup_hmac, mailbox_display_ciphertext, '
                .'mailbox_display_encryption_key_id}, where mailbox_lookup_hmac is a KEYED HMAC-SHA256 digest '
                .'(not a plain hash — a Gmail mailbox address is a small, structured, guessable string, unlike '
                .'the sibling table\'s CSPRNG-token hash, so a plain hash would be dictionary-attackable offline; '
                .'the HMAC key is a dedicated, platform-wide secret, never APP_KEY, never a per-firm key) and '
                .'mailbox_display_ciphertext is already-encrypted, never plaintext; (2) it MUST be queryable in a '
                .'genuinely pre-tenant-context bootstrap step — Gmail\'s Cloud Pub/Sub push delivery uses ONE '
                .'shared topic/subscription for every connected firm, so the inbound webhook request arrives '
                .'before any firm identity is authenticated, and app.current_firm_id cannot be SET LOCAL before '
                .'this exact lookup resolves which firm/connection the mailbox belongs to — a FORCE RLS policy '
                .'here would make GmailMailboxRoutingService::resolveByMailbox() structurally impossible for the '
                .'identical reason integration_webhook_routing_index\'s own exemption gives; and (3) the firm_id '
                .'read back from this table is never treated as authoritative on its own — every subsequent step '
                .'re-establishes and re-verifies real tenant context via the ordinary, unmodified '
                .'TenantContextService::runWithFirmContext() before anything RLS-protected is touched, so this '
                .'table\'s firm_id is a non-authoritative routing pointer only, not a security boundary. See '
                .'database/migrations/2026_09_23_170001_create_integration_gmail_mailbox_routes_table.php\'s own '
                .'"WHY THIS TABLE HAS NO RLS" class docblock, which cross-references '
                .'2026_09_06_060001_create_integration_webhook_routing_index_table.php directly, for the full '
                .'reasoning.',
            'expected_readers' => [
                'App\\Integrations\\Support\\GmailMailboxRoutingService::resolveByMailbox() — the sole pre-tenant-context read, returning only a resolved {firm_id, firm_integration_id} pair (or null), never a secret or hydrated model',
            ],
            'authorized_writers' => [
                'App\\Integrations\\Support\\GmailMailboxRoutingService::route()/unroute() — route() is the sole writer (delete-before-insert, never updateOrCreate()); unroute() is the sole deleter, per checkpoint3-combined-design.md §4.7 intended to be called from ProviderConnectionService::disconnect()/disableWebhookRouting() in the same transaction as those methods\' existing firm_integrations/integration_webhook_routing_index cleanup',
            ],
        ],
        'provider_rate_card_entries' => [
            'reason' => 'FirmsVault Live Integrations, Checkpoint 4 (Plaid cost-control track): platform-admin-authored '
                .'rate-card reference data (checkpoint4-design-cost-control.md §1.1). Carries no firm_id column at all — '
                .'a firm_override row\'s nullable scope_id POINTS AT a firm without the row itself being tenant-owned, '
                .'mirroring firm_entitlements\' opposite (tenant-owned) relationship being explicitly rejected in the '
                .'design\'s own reasoning: only a platform admin can grant a firm a different price/allowance than its '
                .'package, a firm can never self-serve a discount, so this belongs in admin-panel-owned reference data, '
                .'never RLS-forced tenant data. Confirmed no firm_id/firm-referencing column by direct migration '
                .'inspection (database/migrations/2026_09_24_500001_create_provider_rate_card_entries_table.php).',
            'expected_readers' => [
                'App\\Integrations\\Billing\\ProviderRateCardResolver::resolve() — the sole reader, resolving the highest-precedence matching row for a given (provider_key, product, billing_operation, environment, firm) as of a point in time',
            ],
            'authorized_writers' => [
                'platform-admin-only Filament action (not built by this checkpoint\'s cost-control track — a later admin-UI concern), never firm-panel-writable — no runtime create/update path exists anywhere in this checkpoint\'s own file set',
            ],
        ],
        'provider_kill_switches' => [
            'reason' => 'FirmsVault Live Integrations, Checkpoint 4 (Plaid cost-control track): the incident-response '
                .'kill-switch surface (checkpoint4-design-cost-control.md §4.1/§4.2; checkpoint4-combined-design.md '
                .'§1.7, filled in by direct analogy to provider_rate_card_entries\' own stated reasoning, since the '
                .'source doc left this table\'s RLS classification unstated). A scope_type=\'platform\' row has no '
                .'owning firm and must be visible/checked on every pipeline run for every firm; this table is '
                .'admin-panel-mutated only ("a platform admin can suspend a firm\'s optional operation via the '
                .'kill-switch mechanism... a firm cannot self-serve this" — design §4.2), an incident-response tool '
                .'requiring the operational tempo of a DB-row toggle, not a deploy. Confirmed no firm_id/firm-referencing '
                .'column by direct migration inspection (database/migrations/2026_09_24_500004_create_provider_kill_switches_table.php).',
            'expected_readers' => [
                'App\\Integrations\\Billing\\ProviderOperationPolicyResolver::resolve() — checked broad-to-narrow (product -> endpoint_category -> operation), platform scope only, on every pipeline run',
            ],
            'authorized_writers' => [
                'ProviderKillSwitchResource (a later checkpoint\'s PlatformAdmin Filament UI concern, per checkpoint4-combined-design.md §9.4 — "the one place PlatformAdmin writes, by design") — not built by this checkpoint\'s cost-control track',
            ],
        ],
        'provider_operation_default_policies' => [
            'reason' => 'FirmsVault Live Integrations, Checkpoint 4 (Plaid cost-control track): the GLOBAL half of the '
                .'coordinator-resolved two-table split for the firm-operation-policy concept (checkpoint4-combined-design.md '
                .'§1.8) — "a provider_operation_default_policies table (Global, no RLS, admin-authored, one row per '
                .'product/environment, the platform-default fallback only)". The firm-editable half, '
                .'provider_firm_operation_policies, is a SEPARATE, Direct BelongsToTenant + FORCE RLS table (see '
                .'PREPARED_TABLES) — deliberately never merged into one bespoke-policy table, per the coordinator\'s own '
                .'stated reasoning against introducing a second novel RLS-plumbing pattern in the same checkpoint '
                .'alongside the Client Portal\'s two-hop self-lookup. Confirmed no firm_id/firm-referencing column by '
                .'direct migration inspection (database/migrations/2026_09_24_500005_create_provider_operation_default_policies_table.php).',
            'expected_readers' => [
                'App\\Integrations\\Billing\\ProviderOperationPolicyResolver::resolve() — read as the per-field fallback when the firm-scoped provider_firm_operation_policies row is absent or has a null field',
            ],
            'authorized_writers' => [
                'platform-admin-only Filament action (not built by this checkpoint\'s cost-control track), never firm-panel-writable',
            ],
        ],
        'provider_invoice_reconciliations' => [
            'reason' => 'FirmsVault Live Integrations, Checkpoint 4 (Plaid cost-control track): monthly provider '
                .'invoice reconciliation, modeled directly on TrustReconciliation\'s own shape but PLATFORM-scoped, not '
                .'per-firm (checkpoint4-design-cost-control.md §6) — a real Plaid invoice covers all firms\' aggregated '
                .'usage, the same relationship BillingAccount/PlatformBillingEvent already have to the platform as a '
                .'whole, never to one firm. Confirmed no firm_id/firm-referencing column by direct migration inspection '
                .'(database/migrations/2026_09_24_500010_create_provider_invoice_reconciliations_table.php).',
            'expected_readers' => [
                'PlaidCostOversightPage (a later checkpoint\'s PlatformAdmin Filament UI concern, per checkpoint4-combined-design.md §9.4) — not built by this checkpoint\'s cost-control track',
            ],
            'authorized_writers' => [
                'App\\Services\\ProviderInvoiceReconciliationService::run() — the sole writer, human-entered, comparison-only, never auto-correcting',
            ],
        ],
        // FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial
        // evidence add-on", Plaid provider-core track) addition —
        // checkpoint4-combined-design.md §1.1.1 (binding "Option B");
        // checkpoint4-design-plaid-provider-core.md §11.2;
        // checkpoint4-security-review.md Finding 7, confirmed
        // safe/sufficient.
        'integration_plaid_item_routes' => [
            'reason' => 'FirmsVault Live Integrations, Checkpoint 4 (Plaid provider-core track): like '
                .'integration_webhook_routing_index/integration_gmail_mailbox_routes, this table genuinely carries '
                .'a NOT NULL firm_id column ($table->foreignId(\'firm_id\')->constrained(\'firms\')->cascadeOnDelete(), '
                .'per database/migrations/2026_09_24_180001_create_integration_plaid_item_routes_table.php) — it '
                .'is exempt from RLS despite that, not because it lacks it. Reviewed and approved as a deliberate, '
                .'narrow exception, for the identical three-reason structure both sibling tables\' own exemptions '
                .'already established: (1) it holds no secret or credential material of any kind — only {firm_id, '
                .'firm_integration_id, integration_provider_id, item_lookup_hmac, item_display_ciphertext, '
                .'item_display_encryption_key_id}, where item_lookup_hmac is a KEYED HMAC-SHA256 digest (not a '
                .'plain hash — Plaid does not officially document a guaranteed entropy floor for item_id, so it is '
                .'treated with the same conservative caution the Gmail mailbox-address case required; the HMAC key '
                .'is a dedicated, platform-wide secret, never APP_KEY, never a per-firm key) and '
                .'item_display_ciphertext is already-encrypted, never plaintext; (2) it MUST be queryable in a '
                .'genuinely pre-tenant-context bootstrap step — an inbound Plaid webhook delivery carries only an '
                .'item_id, with no firm identity established yet, and app.current_firm_id cannot be SET LOCAL '
                .'before this exact lookup resolves which firm/connection the item_id belongs to — a FORCE RLS '
                .'policy here would make PlaidItemRoutingService::resolveByItemId() structurally impossible for '
                .'the identical reason both sibling tables\' own exemptions give; and (3) the firm_id read back '
                .'from this table is never treated as authoritative on its own — every subsequent step '
                .'re-establishes and re-verifies real tenant context via the ordinary, unmodified '
                .'TenantContextService::runWithFirmContext() before anything RLS-protected is touched, so this '
                .'table\'s firm_id is a non-authoritative routing pointer only, not a security boundary. See '
                .'database/migrations/2026_09_24_180001_create_integration_plaid_item_routes_table.php\'s own '
                .'"WHY THIS TABLE HAS NO RLS" class docblock for the full reasoning.',
            'expected_readers' => [
                'App\\Integrations\\Support\\PlaidItemRoutingService::resolveByItemId() — the sole pre-tenant-context read, returning only a resolved {firm_id, firm_integration_id, integration_provider_id} tuple (or null), never a secret or hydrated model',
                'App\\Integrations\\Providers\\Plaid\\PlaidProvider::verifyInboundSignature() — a second, narrow, same-request read used only to attribute the webhook-verification-key JWK fetch to a real FirmIntegration (see that method\'s own docblock)',
            ],
            'authorized_writers' => [
                'App\\Integrations\\Support\\PlaidItemRoutingService::route()/unroute() — route() is the sole writer (delete-before-insert, never updateOrCreate()); unroute() is the sole deleter, called from PlaidProvider::subscribe() and ProviderConnectionService::disconnect()/disableWebhookRouting()',
            ],
        ],
        // FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial
        // evidence add-on", Financial Evidence Workspace UI track)
        // addition — checkpoint4-combined-design.md §1.6.
        'financial_evidence_large_deposit_thresholds' => [
            'reason' => 'FirmsVault Live Integrations, Checkpoint 4 (Financial Evidence Workspace UI track): the '
                .'Unexplained Large Deposits detection threshold, platform_default -> firm_override scope-'
                .'precedence, mirroring provider_rate_card_entries\' own reasoning exactly (checkpoint4-combined-design.md '
                .'§1.6). No firm_id column exists at all — a nullable scope_id POINTS AT a firm for firm_override '
                .'rows without the row itself being tenant-owned. A firm may request its own firm_override row '
                .'(FinancialIntegrationAccessPolicyService::canRequest()-gated, via the Review Queues panel — this '
                .'is a detection-tuning preference, not commercial/pricing data, so it is the one exception to '
                .'"platform-admin-authored only" this table\'s sibling Global tables otherwise share); the '
                .'platform_default row is admin-authored only. Confirmed no firm_id/firm-referencing column by '
                .'direct migration inspection '
                .'(database/migrations/2026_09_25_190017_create_financial_evidence_large_deposit_thresholds_table.php).',
            'expected_readers' => [
                'App\\Integrations\\Services\\FinancialEvidenceLargeDepositDetectionService::resolveThresholdCents() — resolves the firm-scoped row first, falls back to the platform_default row, then to config(\'financial_evidence.large_deposit_default_threshold_cents\')',
            ],
            'authorized_writers' => [
                'A firm\'s own FirmOwner/Attorney/BillingStaff (FinancialIntegrationAccessPolicyService::canRequest()-gated) may write ONLY their own firm\'s firm_override row (scope_id = their own firm_id) — never the platform_default row',
                'platform-admin-only Filament action (not built by this checkpoint) writes the platform_default row',
            ],
        ],
        // FirmsVault Live Integrations, Checkpoint 8.2 §A4 addition — the
        // FK-free durable at-most-once provider-call gate.
        'provider_operation_attempts' => [
            'reason' => 'FirmsVault Live Integrations, Checkpoint 8.2 (§A4): the durable at-most-once gate for '
                .'outbound provider calls. It records, on a database session deliberately INDEPENDENT of whatever '
                .'transaction and row locks the calling job holds, that one logical operation has been claimed and '
                .'that its request is about to leave the process — so a rollback or crash in the network window can '
                .'never be mistaken for "never sent" and silently re-charge the customer. Two properties follow from '
                .'that independence and together force the exemption: (1) the table carries NO foreign keys at all '
                .'(Checkpoint 8.1 proved that a cross-session INSERT whose FK references a row PullSyncJob has '
                .'locked FOR UPDATE must wait for FOR KEY SHARE and deadlocks in production), and (2) an '
                .'app.current_firm_id-keyed policy would require tenant context to be pushed on that separate '
                .'session for every read — including the pre-claim probe that must run BEFORE any firm context is '
                .'necessarily established — reintroducing exactly the cross-session coupling this table exists to '
                .'eliminate. Tenant attribution is nonetheless preserved: a real NOT NULL firm_id scalar is stored '
                .'on every row, and every query in ProviderOperationAttemptService filters on it explicitly. That '
                .'firm_id is a non-authoritative correlation pointer, never a security boundary — firm ownership of '
                .'the connection is authorized against real, FK-backed, RLS-protected rows on the ordinary '
                .'connection BEFORE a claim is written, and these rows are operational evidence only, never a '
                .'source of truth for money owed (provider_billable_call_reservations and integration_usage_records '
                .'keep their real FKs and their own protection, and are rebuilt from this evidence during recovery, '
                .'never invented from it). No credential, token, or raw provider payload is ever stored here — only '
                .'a redacted metadata summary and a checksum (§A8). A dangling scalar can only cause a claim to be '
                .'refused or reconciled; it can never authorize a call or expose another tenant\'s data. See '
                .'database/migrations/2026_10_01_100001_create_provider_operation_attempts_table.php\'s own '
                .'"THIS TABLE INTENTIONALLY HAS NO FOREIGN KEYS" class docblock for the full reasoning.',
            'expected_readers' => [
                'App\\Integrations\\Billing\\ProviderOperationAttemptService — the sole reader, always on the independent durable connection and always filtered on the scalar firm_id, returning only gate decisions and redacted recovery evidence',
                'App\\Integrations\\Billing\\ProviderBillableCallPipeline — indirectly, via that service, to decide whether a logical operation may send at all (§A5)',
            ],
            'authorized_writers' => [
                'App\\Integrations\\Billing\\ProviderOperationAttemptService — the sole writer (claim/lease CAS, markAttemptStarted before the send, outcome classification after it, local-processing and reconciliation transitions); no controller, Livewire component, Filament resource, or firm user ever writes this table',
                'platform admins only, and only through an explicit audited reconciliation resolution on a reconciliation_required row — never a direct edit',
            ],
        ],
        // feature/ses-event-consumer addition — the outbound-send
        // correlation ledger the SES bounce/complaint consumer uses to
        // resolve an inbound event back to the correct firm.
        'notification_provider_correlations' => [
            'reason' => 'feature/ses-event-consumer: this table genuinely carries a NOT NULL firm_id column '
                .'($table->foreignId(\'firm_id\')->constrained(\'firms\')->cascadeOnDelete(), per '
                .'database/migrations/2026_10_15_100002_create_notification_provider_correlations_table.php) — it '
                .'is exempt from RLS despite that, not because it lacks it, for the identical reason '
                .'integration_webhook_routing_index/integration_gmail_mailbox_routes are exempt: (1) it MUST be '
                .'queryable in a genuinely pre-tenant-context bootstrap step — an inbound SES bounce/complaint '
                .'event arrives on ONE shared SQS queue for every firm, with no firm identity attached beyond the '
                .'SES provider message id, so app.current_firm_id cannot be SET LOCAL before '
                .'SesEventConsumerService resolves which firm the event belongs to via this table\'s '
                .'provider_message_id -> firm_id lookup; (2) it holds no secret or credential material — only '
                .'{correlation_id (an opaque uuid, deliberately never a sequential id), firm_id, channel, '
                .'recipient_normalized, provider_message_id}; and (3) the firm_id read back from this table is '
                .'never treated as authoritative on its own for anything beyond resolving which firm context to '
                .'enter — SesEventConsumerService immediately re-establishes real tenant context via the ordinary, '
                .'unmodified TenantContextService::runWithFirmContext() before calling '
                .'SuppressionService::recordBounce()/recordComplaint(), so this table\'s firm_id is a '
                .'non-authoritative routing pointer only, never a security boundary. Written once, at outbound-send '
                .'time, by App\\Services\\OutboundMailCorrelationService::correlate() — never updated afterward '
                .'except to attach the confirmed provider_message_id once SES accepts the send. See '
                .'database/migrations/2026_10_15_100002_create_notification_provider_correlations_table.php.',
            'expected_readers' => [
                'App\\Services\\SesEventConsumerService — the sole pre-tenant-context read, resolving an inbound SES event\'s mail.messageId to a {firm_id, channel, recipient_normalized} tuple (or null) before any RLS-protected table is touched',
            ],
            'authorized_writers' => [
                'App\\Services\\OutboundMailCorrelationService::correlate() — the sole writer: creates the row before the real send is attempted, then persists provider_message_id only after the mail transport confirms a successful send via Illuminate\\Mail\\Events\\MessageSent',
            ],
        ],
        // feature/ses-event-consumer addition — the idempotency ledger
        // for the SES bounce/complaint consumer.
        'ses_event_receipts' => [
            'reason' => 'feature/ses-event-consumer: an ordinary "no firm_id" Global exemption — no firm_id '
                .'column exists at all (confirmed via the create migration), structurally identical in shape to '
                .'integration_platform_provider_health_summaries. SES/SNS delivery to SQS is at-least-once, and '
                .'the consumer must be idempotent: this table is a durable ledger keyed by '
                .'{eventType}:{feedbackId ?? mail.messageId} (deliberately never the SQS message id, since a '
                .'retried SNS/SQS delivery of the identical underlying SES event can arrive as a genuinely '
                .'different SQS message). A row is written only after the corresponding suppression/business '
                .'logic for that event has already succeeded durably, so a crash between business-logic success '
                .'and the SQS delete never causes the event to be silently skipped on retry. No PII, no email '
                .'content, and no secret material is ever stored — only event-type, provider/queue message ids, '
                .'and a processed_at timestamp. See '
                .'database/migrations/2026_10_15_100003_create_ses_event_receipts_table.php.',
            'expected_readers' => [
                'App\\Services\\SesEventConsumerService — the sole reader, checking whether an inbound event\'s idempotency key has already been processed before applying any suppression outcome',
            ],
            'authorized_writers' => [
                'App\\Services\\SesEventConsumerService — the sole writer, and only after the corresponding suppression/business logic for that event has already succeeded durably',
            ],
        ],
        // post-578ee98 audit remediation (finding H1) — platform-scope
        // correlation ledger for governed real sends that could not
        // resolve an owning firm.
        'platform_notification_correlations' => [
            'reason' => 'post-578ee98 audit remediation (finding H1): an ordinary "no firm_id" Global exemption — '
                .'no firm_id column exists at all (confirmed via the create migration). Solves the same '
                .'pre-tenant-context bootstrap problem notification_provider_correlations solves, for the narrow '
                .'case where a governed real send (password-reset notifications on User/ClientPortalUser) '
                .'genuinely cannot resolve an owning firm. Uses account_type/account_id (a plain morph pair '
                .'identifying which User/ClientPortalUser, never a firm) instead of firm_id. '
                .'recipient_fingerprint is a keyed HMAC-SHA256 of the normalized recipient, never plaintext — a '
                .'dedicated, platform-wide secret (never APP_KEY), mirroring '
                .'App\\Integrations\\Support\\GmailMailboxRoutingService\'s own established "keyed HMAC, not a '
                .'plain hash" discipline. See '
                .'database/migrations/2026_10_20_100001_create_platform_notification_correlations_table.php.',
            'expected_readers' => [
                'App\\Services\\SesEventConsumerService — the sole pre-tenant-context read, resolving an inbound SES event\'s mail.messageId to an account/notification-type tuple (or null) when no firm-scoped correlation exists',
            ],
            'authorized_writers' => [
                'App\\Services\\PlatformNotificationCorrelationService::correlate() — the sole writer: creates the row before the real send is attempted, then persists provider_message_id only after the mail transport confirms a successful send',
            ],
        ],
        // post-578ee98 audit remediation (finding H1) — the platform-
        // scope analogue of a suppressed recipient.
        'platform_notification_suppressions' => [
            'reason' => 'post-578ee98 audit remediation (finding H1): an ordinary "no firm_id" Global exemption — '
                .'no firm_id column exists at all, by construction (there is no firm to scope it to). Independent '
                .'of SuppressionService/notification_events, which is firm-scoped and FORCE-RLS-protected. One '
                .'row per suppressed recipient_fingerprint (a keyed HMAC, never plaintext) — a current-state '
                .'table, not an append-only event log — so a permanently-bad address stops being retried on the '
                .'uncorrelated-firm password-reset fallback path. See '
                .'database/migrations/2026_10_20_100002_create_platform_notification_suppressions_table.php.',
            'expected_readers' => [
                'App\\Models\\User::sendPasswordResetNotification()/App\\Models\\ClientPortalUser::sendPasswordResetNotification() — via PlatformNotificationCorrelationService::isRecipientSuppressed(), checked before attempting a real send on the uncorrelated-firm fallback path',
            ],
            'authorized_writers' => [
                'App\\Services\\PlatformNotificationCorrelationService::recordOutcome() — the sole writer, called only by SesEventConsumerService once a platform-scope correlation resolves a permanent bounce or complaint',
            ],
        ],
    ];

    /**
     * Filename glob matching every FORCE-activation migration. Each
     * matching migration is a `return new class extends Migration`
     * with a `private const TABLE = '<table_name>';` declaration
     * (verified consistent across every FORCE migration written since
     * Section 39A-3A) — see discoverForcedTables().
     */
    private const FORCE_RLS_MIGRATION_GLOB = '*_force_rls_on_*_table.php';

    /**
     * @return array<int, string>
     */
    public function preparedTables(): array
    {
        return self::PREPARED_TABLES;
    }

    /**
     * @return array<int, string> every tenant-owned table, prepared or not
     */
    public function tenantOwnedTables(): array
    {
        return array_values(array_unique(array_merge(self::PREPARED_TABLES, self::MISSING_PREPARED_TABLES)));
    }

    /**
     * @return array<int, string>
     */
    public function exemptTables(): array
    {
        return self::EXEMPT_TABLES;
    }

    /**
     * @return array<int, string>
     */
    public function missingPreparedTables(): array
    {
        return self::MISSING_PREPARED_TABLES;
    }

    /**
     * Every table with FORCE ROW LEVEL SECURITY active, derived by
     * scanning database/migrations for every FORCE-activation
     * migration and reading each one's own `private const TABLE`
     * declaration. This is intentionally not a hardcoded list: the
     * FORCE rollout is still an active, checkpoint-by-checkpoint
     * effort, and a hardcoded array here has repeatedly gone stale
     * (18 was correct as of Section 39A-3K and wrong within days of
     * Section 39A-3L starting). Deriving it from the migrations that
     * are the actual source of truth for FORCE state means this never
     * needs another manual bump — landing a new
     * force_rls_on_<table>_table.php migration is sufficient.
     *
     * @return array<int, string>
     */
    public function forcedTables(): array
    {
        return $this->discoverForcedTables();
    }

    /**
     * @return array<int, string>
     */
    private function discoverForcedTables(): array
    {
        $tables = [];

        foreach (glob(database_path('migrations/'.self::FORCE_RLS_MIGRATION_GLOB)) ?: [] as $path) {
            $source = file_get_contents($path);

            if ($source !== false && preg_match("/private const TABLE = '([a-z_][a-z0-9_]*)'/", $source, $matches)) {
                $tables[] = $matches[1];
            }
        }

        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }

    /**
     * @return array{prepared_count: int, tenant_owned_count: int, missing_prepared_count: int, forced_count: int, enforcement_active: bool}
     */
    public function coverageSummary(): array
    {
        return [
            'prepared_count' => count(self::PREPARED_TABLES),
            'tenant_owned_count' => count($this->tenantOwnedTables()),
            'missing_prepared_count' => count(self::MISSING_PREPARED_TABLES),
            // Aggregate FORCE-count so callers are never misled by a
            // single boolean: forced_count/forcedTables() reflect
            // however many FORCE migrations exist right now (see
            // discoverForcedTables()) and grow on their own as the
            // rollout lands more of them. 'enforcement_active' below
            // means "FORCE is active on every prepared table"
            // (schema-wide enforcement) — false until every prepared
            // table has a FORCE migration. Use forced_count /
            // forcedTables() / isForced() for partial/per-table
            // enforcement state.
            'forced_count' => count($this->forcedTables()),
            'enforcement_active' => count($this->forcedTables()) === count(self::PREPARED_TABLES),
        ];
    }

    public function isPrepared(string $table): bool
    {
        return in_array($table, self::PREPARED_TABLES, true);
    }

    public function isMissing(string $table): bool
    {
        return in_array($table, self::MISSING_PREPARED_TABLES, true);
    }

    public function isForced(string $table): bool
    {
        return in_array($table, $this->forcedTables(), true);
    }

    /**
     * Wave 1A (Section 39A-4B) canonical inventory: every one of the
     * repository's 208 tables, each with exactly one
     * TenantOwnershipClassification, an ownership path, and a note.
     * Every PREPARED_TABLES/MISSING_PREPARED_TABLES table is
     * synthesized here as DirectTenant with ownership_path "self (own
     * NOT NULL firm_id column)" — the 95 tables outside that union
     * come from FULL_TABLE_INVENTORY_EXTRA above.
     *
     * @return array<string, TenantTableInventoryItem>
     */
    public function fullTableInventory(): array
    {
        $items = [];

        foreach (array_merge(self::PREPARED_TABLES, self::MISSING_PREPARED_TABLES) as $table) {
            $items[$table] = new TenantTableInventoryItem(
                table: $table,
                classification: TenantOwnershipClassification::DirectTenant,
                ownershipPath: 'self (own NOT NULL firm_id column)',
                notes: $this->isPrepared($table)
                    ? 'Direct tenant-owned table; RLS prepared (ENABLE ROW LEVEL SECURITY + CREATE POLICY) — see PREPARED_TABLES.'
                    : 'Direct tenant-owned table; RLS not yet prepared — see MISSING_PREPARED_TABLES.',
            );
        }

        foreach (self::FULL_TABLE_INVENTORY_EXTRA as $table => $entry) {
            $items[$table] = new TenantTableInventoryItem(
                table: $table,
                classification: $entry['classification'],
                ownershipPath: $entry['ownership_path'],
                notes: $entry['notes'],
            );
        }

        return $items;
    }

    public function classificationOf(string $table): ?TenantOwnershipClassification
    {
        return $this->fullTableInventory()[$table]->classification ?? null;
    }

    public function ownershipPathOf(string $table): ?string
    {
        return $this->fullTableInventory()[$table]->ownershipPath ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function tablesByClassification(TenantOwnershipClassification $classification): array
    {
        return array_values(array_map(
            fn (TenantTableInventoryItem $item) => $item->table,
            array_filter(
                $this->fullTableInventory(),
                fn (TenantTableInventoryItem $item) => $item->classification === $classification,
            ),
        ));
    }

    /**
     * @return array<string, int> classification value => table count
     */
    public function classificationSummary(): array
    {
        $summary = [];

        foreach (TenantOwnershipClassification::cases() as $case) {
            $summary[$case->value] = count($this->tablesByClassification($case));
        }

        return $summary;
    }

    /**
     * @return array<int, ExemptTableMetadata>
     */
    public function exemptTableMetadata(): array
    {
        return array_map(
            fn (string $table) => new ExemptTableMetadata(
                table: $table,
                reason: self::EXEMPT_TABLE_METADATA[$table]['reason'],
                expectedReaders: self::EXEMPT_TABLE_METADATA[$table]['expected_readers'],
                authorizedWriters: self::EXEMPT_TABLE_METADATA[$table]['authorized_writers'],
            ),
            self::EXEMPT_TABLES,
        );
    }

    public function exemptMetadataFor(string $table): ?ExemptTableMetadata
    {
        if (! isset(self::EXEMPT_TABLE_METADATA[$table])) {
            return null;
        }

        return new ExemptTableMetadata(
            table: $table,
            reason: self::EXEMPT_TABLE_METADATA[$table]['reason'],
            expectedReaders: self::EXEMPT_TABLE_METADATA[$table]['expected_readers'],
            authorizedWriters: self::EXEMPT_TABLE_METADATA[$table]['authorized_writers'],
        );
    }
}
