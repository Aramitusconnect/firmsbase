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
 * app.current_firm_id session middleware) is NOT active anywhere in
 * this repository. Every finding below reflects that: even the
 * "prepared" tables are inert today for the app's own database
 * connection (table-owner role is exempt from non-forced RLS).
 *
 * Source of truth: the 6 RLS-preparation migrations
 * (2026_07_04_500001 through 2026_07_09_900024) cover only Phases 1-6.
 * No RLS-preparation migration exists for anything after Phase 6 —
 * every tenant-owned table introduced from Phase 7 onward (email,
 * forms, e-signature, accounting/expenses, trust accounting, webhooks,
 * AI governance, Phase 16/17 deployment/license/governance tables) has
 * no RLS policy at all.
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
     * @var array<int, string>
     */
    private const MISSING_PREPARED_TABLES = [
        'accounting_export_batches', 'ai_approval_events', 'ai_approval_requests',
        'ai_retrieval_indexes', 'ai_tool_actions', 'ai_usage_events', 'chart_of_accounts',
        'deletion_requests', 'deployment_configs', 'deployment_health_checks',
        'email_accounts', 'email_attachments', 'email_messages', 'email_message_links',
        'email_visibility_rules', 'expenses', 'expense_approvals', 'expense_categories',
        'expense_receipts', 'export_jobs', 'firm_ai_provider_keys', 'firm_ai_settings',
        'form_drafts', 'generated_documents', 'import_batches', 'key_destruction_requests',
        'legal_holds', 'migration_projects', 'offboarding_requests',
        'private_enterprise_settings', 'signature_certificates', 'signature_requests',
        'signature_request_recipients', 'trust_accounts', 'trust_balances',
        'trust_chargeback_events', 'trust_ledger_entries', 'trust_ledgers',
        'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests',
        'webhook_events', 'webhook_subscriptions',
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
     * @return array{prepared_count: int, tenant_owned_count: int, missing_prepared_count: int, enforcement_active: bool}
     */
    public function coverageSummary(): array
    {
        return [
            'prepared_count' => count(self::PREPARED_TABLES),
            'tenant_owned_count' => count($this->tenantOwnedTables()),
            'missing_prepared_count' => count(self::MISSING_PREPARED_TABLES),
            'enforcement_active' => false,
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
}
