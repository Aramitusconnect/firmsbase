<?php

namespace App\Services;

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
 * (table-owner role is exempt from non-forced RLS), and the 61 tables
 * in MISSING_PREPARED_TABLES have no RLS policy of any kind yet. Do
 * not read this docblock as "RLS is fully enforced" — it is not.
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
     * @var array<int, string>
     */
    private const MISSING_PREPARED_TABLES = [
        'accounting_export_batches', 'accounting_export_lines', 'ai_approval_events',
        'ai_approval_requests', 'ai_retrieval_indexes', 'ai_tool_actions',
        'ai_usage_events', 'chart_of_accounts', 'customer_success_health_scores',
        'deletion_requests', 'deployment_configs', 'deployment_health_checks',
        'document_hashes', 'email_accounts', 'email_attachments',
        'email_message_links', 'email_messages', 'email_sync_events',
        'email_visibility_rules', 'expense_approvals', 'expense_categories',
        'expense_receipts', 'expenses', 'export_jobs', 'firm_ai_provider_keys',
        'firm_ai_settings', 'fleet_migration_instance_status', 'form_drafts',
        'form_review_events', 'generated_document_events', 'generated_documents',
        'implementation_projects', 'import_batches', 'key_destruction_requests',
        'legal_holds', 'matter_expenses', 'matter_trust_balances',
        'migration_projects', 'offboarding_requests', 'pdf_view_events',
        'private_enterprise_settings', 'signature_certificates', 'signature_events',
        'signature_request_recipients', 'signature_requests',
        'support_access_requests', 'support_access_sessions', 'trust_accounts',
        'trust_approval_events', 'trust_balances', 'trust_chargeback_events',
        'trust_ledger_entries', 'trust_ledgers', 'trust_reconciliations',
        'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries',
        'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets',
        'webhook_subscriptions',
    ];

    /**
     * Global/organization/platform-level tables intentionally exempt
     * from firm-keyed RLS — they either have no firm_id at all, or
     * (usage_rollups, platform_invoice_lines) carry a NULLABLE firm_id
     * for attribution only, where the real ownership boundary is
     * billing_account_id, not firm_id (reasoning taken directly from
     * the Phase 6 RLS migration's own doc comment).
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
}
