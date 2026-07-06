<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * DataModelContractMappingService — declares the master plan's Section
 * 26 data-model/state-machine contract: 9 global data rules and 14
 * table-family mappings, each mapped to an EXISTING owning mechanism
 * (Phases 1-17 + the cross-cutting package) or a known, explicitly
 * declared gap/open question. Purely declarative — no schema change,
 * no new table, no enforcement. Reuses GovernanceMappingResult/
 * GovernanceMappingStatus from the Section 25 cross-cutting package
 * rather than inventing a parallel type.
 *
 * Every classification below was determined by direct inspection of
 * the real repository (all database/migrations, all app/Models) at
 * the time this service was written.
 */
class DataModelContractMappingService
{
    /**
     * Public-reference/UUID candidates named in Section 26 that do not
     * currently carry a HasPublicUuid-backed public identifier.
     * Decision-needed only — NOT added as columns, NOT added to the
     * gap register, listed here as notes for a future decision.
     *
     * @var array<int, string>
     */
    private const PUBLIC_UUID_CANDIDATES = [
        'Task',
        'Deadline',
        'CalendarEvent',
        'TimeEntry',
        'TrustLedgerEntry',
        'MatterTrustBalance',
        'MatterType',
        'PracticeArea',
        'IntakeTemplate',
        'InvoiceLine',
        'DocumentVersion',
    ];

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function globalRules(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'uuidv7_public_references',
                item_label: 'UUIDv7 used for every public-facing reference',
                owning_class: \App\Models\Concerns\HasPublicUuid::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'HasPublicUuid (Str::uuid7()) is applied to 125+ models and is the sole mechanism that ever populates a uuid column. However, several models named in the master plan do not yet carry a public uuid at all — see publicUuidCandidates() for the exact, decision-needed list (Task, Deadline, CalendarEvent, TimeEntry, TrustLedgerEntry, MatterTrustBalance, MatterType, PracticeArea, IntakeTemplate, InvoiceLine, DocumentVersion). No column is added by this service; this is notes-only per approved decision.',
            ),
            new GovernanceMappingResult(
                item_key: 'firm_id_on_tenant_tables',
                item_label: 'firm_id present on every tenant-owned table',
                owning_class: \App\Models\Concerns\BelongsToTenant::class,
                status: GovernanceMappingStatus::Implemented,
                notes: '83+ models apply BelongsToTenant (direct firm_id + automatic global scope). Tables with no firm_id of their own (e.g. matter_parties, document_versions, task_dependencies) are intentionally scoped transitively through their parent row — a documented, consistent pattern across every phase\'s migrations, not an omission.',
            ),
            new GovernanceMappingResult(
                item_key: 'global_commercial_tables',
                item_label: 'Commercial-hierarchy tables deliberately carry no firm_id',
                owning_class: \App\Models\Organization::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'organizations, billing_accounts, plans, plan_modules, plan_limits, org_licenses, and seat_pools deliberately have no firm_id column — they sit above or beside the firm tenancy boundary by design (an organization spans many firms; a plan is a global catalog). Explicitly reasoned about in the Phase 6 RLS migration\'s own doc comment.',
            ),
            new GovernanceMappingResult(
                item_key: 'rls_transaction_local_tenant_identifier',
                item_label: 'RLS uses a transaction-local tenant identifier (app.current_firm_id via SET LOCAL)',
                owning_class: null,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'Every RLS policy created so far (6 migrations, Phases 1-6) reads current_setting(\'app.current_firm_id\', true) exactly as the master plan specifies. But no SET LOCAL call exists anywhere in app/config/bootstrap/routes, RLS preparation stops at Phase 6 (nothing for Phases 7-17+), and FORCE ROW LEVEL SECURITY is never applied — see RowLevelSecurityCoverageMappingService for the exact prepared/missing table breakdown.',
            ),
            new GovernanceMappingResult(
                item_key: 'avoid_hard_deletes_for_sensitive_records',
                item_label: 'Hard deletes avoided for sensitive/evidentiary records',
                owning_class: \App\Models\TrustLedgerEntry::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'A consistent booted()-hook pattern blocks delete() (throwing LogicException) across TrustLedgerEntry, DeletionRequest, KeyDestructionApproval, AccessReviewItem, WebhookEvent, AiUsageEvent, PdfViewEvent, and many other append-only/evidentiary models. No behavior changed here.',
            ),
            new GovernanceMappingResult(
                item_key: 'status_fields_and_state_machine_events',
                item_label: 'Status fields paired with state-machine event tables',
                owning_class: \App\Models\TimelineEvent::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Consistent pattern of an enum-backed status column plus a paired append-only event table across nearly every workflow family (DeletionRequestStatus + timeline events, OffboardingRequestStatus, PaymentPlanInstallment status + PaymentPlanEvent, KeyDestructionRequestStatus + KeyDestructionApproval, etc.). No state machine rewritten here.',
            ),
            new GovernanceMappingResult(
                item_key: 'append_only_and_reversal_patterns',
                item_label: 'Append-only ledgers corrected only via a new reversing row',
                owning_class: \App\Services\TrustLedgerEntryReversalService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'TrustLedgerEntry rows are immutable (booted() guard throws on update/delete); the only correction path is TrustLedgerEntryReversalService creating a brand-new row referencing the original via reverses_entry_id. Confirmed unmodified by this package.',
            ),
            new GovernanceMappingResult(
                item_key: 'idempotency_keys_for_retry_sensitive_operations',
                item_label: 'Idempotency keys used for retry-sensitive operations',
                owning_class: null,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'payments.idempotency_key + a partial unique index (firm_id, idempotency_key) is a real, enforced mechanism. Payment plan installment collection, webhook delivery attempts, import apply, and generic retry-sensitive jobs do not have an equivalent explicit key — see IdempotencyKeyCoverageMappingService for the full per-operation breakdown.',
            ),
            new GovernanceMappingResult(
                item_key: 'expand_contract_migration_discipline',
                item_label: 'Migrations follow expand/contract discipline (additive up(), no destructive forward operations)',
                owning_class: null,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Full audit of every migration file: every column-drop and table-drop call found is inside a down() method (standard rollback behavior for a create-table migration or the rollback half of an additive add-column migration). Zero destructive schema operations exist in any up() method. See MigrationExpandContractDisciplineTest for the exact token list audited.',
            ),
        ];
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function tableFamilies(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'commercial_hierarchy',
                item_label: 'Commercial hierarchy (organizations, billing, licensing, seats, usage)',
                owning_class: \App\Models\Organization::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'organizations, billing_accounts, org_licenses, seat_pools, seat_allocations, usage_rollups all exist and are modeled.',
            ),
            new GovernanceMappingResult(
                item_key: 'tenant_and_security',
                item_label: 'Tenant and security primitives (firms, users, roles, encryption, security events)',
                owning_class: \App\Models\Firm::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'firms, firm_settings, users, firm_users, tenant_encryption_keys, security_events all exist. No dedicated roles/permissions tables exist for firm-level RBAC — role is an enum column (FirmUserRole) directly on firm_users, a lightweight-RBAC design choice, not a missing table. Platform-level roles exist separately via platform_roles/PlatformRole.',
            ),
            new GovernanceMappingResult(
                item_key: 'plans_and_licenses',
                item_label: 'Plans, licenses, and entitlements',
                owning_class: \App\Models\FirmLicense::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'plans, plan_modules, plan_limits, firm_licenses, firm_entitlements, license_events, license_files all exist. No separate entitlement_overrides table exists — an override is represented as an EntitlementSource::AdminOverride-tagged firm_entitlements/FirmEntitlementEvent row, not a distinct table (same "catalog imprecision, not a literal second column/table" pattern already documented for billing_accounts.consolidation_mode).',
            ),
            new GovernanceMappingResult(
                item_key: 'practice_templates',
                item_label: 'Practice-area templates (checklists, workflow stages, deadlines, tasks)',
                owning_class: \App\Models\TemplatePackVersion::class,
                status: GovernanceMappingStatus::NotApplicableYet,
                notes: 'practice_areas, matter_types, template_packs, template_pack_versions, installed_template_packs, intake_templates, form_templates, and document_templates all exist. document_checklist_templates, workflow_stage_templates, deadline_templates, and task_templates do NOT exist as physical tables and are NOT represented under another name by any existing table (confirmed by direct search) — per approved decision #2, this is an OPEN QUESTION for a future practice-template-engine section, not a schema request and not a gap-register item.',
            ),
            new GovernanceMappingResult(
                item_key: 'firm_growth',
                item_label: 'Firm growth (leads, consultations)',
                owning_class: \App\Models\FirmLead::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'firm_leads, lead_sources, consultations, consultation_outcomes all exist.',
            ),
            new GovernanceMappingResult(
                item_key: 'client_and_matters',
                item_label: 'Clients and matters',
                owning_class: \App\Models\Matter::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'clients, contacts, parties, matter_parties, matters, matter_assignments, communication_consents, timeline_events all exist.',
            ),
            new GovernanceMappingResult(
                item_key: 'documents',
                item_label: 'Documents and generated documents',
                owning_class: \App\Models\Document::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'documents, document_versions, document_requests, document_request_items, generated_documents all exist. No dedicated upload_events table exists — document upload is captured via the documents row\'s own lifecycle (status/scan_status) plus the document.uploaded webhook event and DocumentChaseEvent, not a separate table.',
            ),
            new GovernanceMappingResult(
                item_key: 'tasks_and_deadlines',
                item_label: 'Tasks, deadlines, and notifications',
                owning_class: \App\Models\Task::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'tasks, task_dependencies, deadlines, calendar_events, notification_events, document_chase_events all exist.',
            ),
            new GovernanceMappingResult(
                item_key: 'billing_and_payments',
                item_label: 'Time entries, invoices, payment plans, and payments',
                owning_class: \App\Models\Invoice::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'time_entries, invoices, invoice_lines, payment_plans, payment_plan_installments, payments, manual_payment_records, payment_classification_events all exist.',
            ),
            new GovernanceMappingResult(
                item_key: 'platform_billing',
                item_label: 'Platform-level billing (subscriptions, invoices, payments, refunds)',
                owning_class: \App\Models\PlatformBillingEvent::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'platform_subscriptions, platform_invoices, platform_invoice_lines, platform_payments, platform_refunds, platform_billing_events all exist.',
            ),
            new GovernanceMappingResult(
                item_key: 'trust_accounting',
                item_label: 'Trust/IOLTA accounting',
                owning_class: \App\Models\TrustLedgerEntry::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'trust_accounts, trust_ledger_entries, trust_balances, trust_reconciliations, trust_approval_events all exist. trust_ledger_entries is append-only, enforced by a booted() guard; corrections flow only through TrustLedgerEntryReversalService.',
            ),
            new GovernanceMappingResult(
                item_key: 'operations',
                item_label: 'Sales/CS/ops (leads, opportunities, trials, implementation, health, support, fleet, analytics)',
                owning_class: \App\Models\ImplementationProject::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Represented under slightly different physical names than the master plan\'s generic catalog: "leads" -> platform_leads, "health_scores" -> customer_success_health_scores; opportunities, trial_requests, implementation_projects, support_access_sessions, fleet_migration_runs, and product_analytics_events all exist with their literal names.',
            ),
            new GovernanceMappingResult(
                item_key: 'ai',
                item_label: 'AI governance foundation',
                owning_class: \App\Models\AiUsageEvent::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'firm_ai_settings, firm_ai_provider_keys, ai_retrieval_indexes, ai_usage_events, ai_approval_requests, ai_tool_actions all exist (Phase 15).',
            ),
            new GovernanceMappingResult(
                item_key: 'governance',
                item_label: 'Data-ownership governance (retention, legal hold, offboarding, key destruction, access review, vendors)',
                owning_class: \App\Models\LegalHold::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'retention_policies, legal_holds, offboarding_exports, key_destruction_requests, access_reviews, vendor_register all exist (Phase 17). activity_logs does NOT exist as a physical table and is NOT a gap — see activityLogsInterpretation(): it is represented by the existing SecurityEvent model and TimelineEventRecorder/timeline_events, per approved decision.',
            ),
        ];
    }

    public function byRule(string $key): ?GovernanceMappingResult
    {
        foreach ($this->globalRules() as $rule) {
            if ($rule->item_key === $key) {
                return $rule;
            }
        }

        return null;
    }

    public function byFamily(string $key): ?GovernanceMappingResult
    {
        foreach ($this->tableFamilies() as $family) {
            if ($family->item_key === $key) {
                return $family;
            }
        }

        return null;
    }

    /**
     * Decision-needed / public-reference candidates only — notes-only
     * per approved decision #3. No column is added, and this is NOT a
     * gap-register item.
     *
     * @return array<int, string>
     */
    public function publicUuidCandidates(): array
    {
        return self::PUBLIC_UUID_CANDIDATES;
    }

    /**
     * activity_logs does not exist and is NOT created by this package.
     * It is represented by the existing generic audit primitives:
     * SecurityEvent (structured security/access events) and
     * TimelineEventRecorder/timeline_events (general firm activity
     * narrative). This is a documented equivalence, not a gap.
     */
    public function activityLogsInterpretation(): GovernanceMappingResult
    {
        return new GovernanceMappingResult(
            item_key: 'activity_logs',
            item_label: 'Generic activity/audit log',
            owning_class: \App\Services\TimelineEventRecorder::class,
            status: GovernanceMappingStatus::Implemented,
            notes: 'No activity_logs table exists and none is created here. Represented by two existing, unmodified audit primitives: SecurityEvent (firm-scoped, append-only, structured security events) and TimelineEventRecorder/timeline_events (the sole write path for general firm activity narrative). No second/duplicate audit system is recommended or introduced.',
        );
    }
}
