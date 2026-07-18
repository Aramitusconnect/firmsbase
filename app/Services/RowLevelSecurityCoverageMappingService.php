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
     * @var array<int, string>
     */
    private const MISSING_PREPARED_TABLES = [
        'accounting_export_batches', 'accounting_export_lines', 'ai_approval_events',
        'ai_approval_requests', 'ai_retrieval_indexes', 'ai_tool_actions',
        'ai_usage_events', 'chart_of_accounts',
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
        'users' => [
            'classification' => TenantOwnershipClassification::System,
            'ownership_path' => null,
            'notes' => 'Laravel default auth user table — a global identity table (firm '
                .'staff, platform admins, and other actors all reference users.id); '
                .'firm-specific staff membership itself lives in firm_users, a '
                .'DirectTenant table.',
        ],
    ];

    /**
     * Reason, expected readers, and authorized writers for every one
     * of the (now 24) EXEMPT_TABLES entries. Readers/writers are
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
