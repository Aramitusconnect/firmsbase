<?php

namespace App\Models;

use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Enums\FirmActivationStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Firm — the operating tenant. This IS the tenancy boundary, so it
 * does not use BelongsToTenant itself (a firm cannot be scoped to
 * itself). billing_account_id is nullable to allow a firm to exist
 * pre-activation; the transition guard requiring it before activation
 * lives in ActivationChecklistService, not here or in the migration.
 *
 * Phase 2 addition: leads, clients, matters, practice-area enablement,
 * template-pack installs, and the firm's timeline log.
 * Phase 3 addition: employee rates, time entries, invoices, payment
 * plans, and canonical payments.
 * Phase 4 addition: documents/document requests, tasks, deadlines,
 * calendar events, notification templates/events, document chase
 * rules, and matter readiness scores.
 * Phase 5 addition: firm activation audit events, health checks,
 * backup/restore tests, incident events, maintenance windows, and
 * pilot feedback items. status_page_events is deliberately NOT a Firm
 * relation — it is a platform-level table with no firm_id (see that
 * model's own doc comment).
 * Phase 6 addition: per-firm seat allocations, usage rollup
 * attribution rows, and template upgrade previews/logs. No new
 * fillable column was added to firms itself — commercial columns landed
 * on organizations/billing_accounts/firm_licenses/firm_users instead
 * (approved Phase 6 manifest).
 * Phase 7 addition: support access requests/sessions, one implementation
 * project, and customer success health score snapshots — all
 * platform-staff-facing, cross-firm-accessible relations. No new
 * fillable column was added to firms itself in Phase 7 either.
 * Phase 8 addition: API keys, import batches, export jobs, and
 * migration projects — all firm-scoped Import/Export/API foundation
 * relations. No new fillable column was added to firms itself in
 * Phase 8 either.
 * Phase 9 addition: connected email accounts and captured email
 * messages — the two top-level roots of the email integration
 * foundation (message links, attachments, sync events, and visibility
 * rules are reachable transitively through these, so no additional
 * relation was added for each of them). No new fillable column was
 * added to firms itself in Phase 9 either.
 * Phase 10 addition: form drafts and generated documents — the two
 * top-level firm-owned workflow roots of the legal form automation and
 * document generation foundation (form draft values, review events,
 * missing-data items, and checklist items are reachable transitively
 * through form_drafts; generated_document_events are reachable
 * transitively through generated_documents, so no additional relation
 * was added for each of them). form_templates, form_template_versions,
 * form_fields, form_mapping_rules, form_edition_watch_items,
 * document_templates, and document_template_versions are global/
 * platform-curated content — not firm-owned — and are deliberately NOT
 * Firm relations. No new fillable column was added to firms itself in
 * Phase 10 either.
 * Phase 11 addition: signature requests and PDF view events — the two
 * top-level firm-owned roots of the advanced PDF viewer, e-signature,
 * and execution evidence foundation (signature_request_recipients,
 * signature_events, and signature_certificates are reachable
 * transitively through signature_requests, so no additional relation
 * was added for each of them; document_hashes is reachable per source
 * document, not through Firm, since it is shared evidentiary metadata
 * for both documents and generated_documents rather than a child of
 * either). No new fillable column was added to firms itself in Phase
 * 11 either.
 * Phase 12 addition: expenses and accounting export batches — the two
 * top-level firm-owned roots of the operating accounting and expenses
 * foundation (expense_receipts, expense_approvals, and matter_expenses
 * are reachable transitively through expenses; accounting_export_lines
 * and accounting_export_errors are reachable transitively through
 * accounting_export_batches, so no additional relation was added for
 * each of them). chart_of_accounts and expense_categories are firm-
 * owned but not given direct Firm relations either, for the same
 * transitive-reachability reasoning (chart_of_accounts is reachable via
 * expense_categories.chartOfAccount() and accounting_export_lines.chartOfAccount();
 * expense_categories via expenses.category()). No new fillable column
 * was added to firms itself in Phase 12 either — this table has and
 * must never have any trust/IOLTA column (project rule).
 * Phase 13 addition: trust accounts, trust transfer requests, and
 * trust refund requests — the three top-level firm-owned roots of the
 * trust accounting foundation (trust_ledgers/trust_balances/
 * matter_trust_balances/trust_ledger_entries are reachable transitively
 * through trust_accounts -> trust_ledgers; trust_approval_events and
 * trust_chargeback_events are firm-owned append-only/lifecycle logs
 * reachable by direct query but not given their own Firm relation,
 * matching the transitive-reachability reasoning used in every prior
 * phase; trust_reconciliations is reachable via
 * trustAccounts()->reconciliations()). No new fillable column was
 * added to firms itself in Phase 13 either. Trust eligibility itself is
 * resolved by TrustEligibilityService from firm.customer_type,
 * firm.firmSettings (payment_mode/trust_iolta_protection), the existing
 * trust_iolta entitlement, and a TrustModeActivationLinked
 * trust_approval_events row — none of which required a new column here.
 * Phase 14 addition: webhook subscriptions — the single top-level
 * firm-owned root of the outbound webhook foundation (webhook_events,
 * webhook_deliveries, webhook_delivery_attempts, and webhook_secrets
 * are all reachable transitively through webhook_subscriptions, or are
 * firm-scoped-but-not-subscription-owned append-only logs reachable by
 * direct query, matching the transitive-reachability reasoning used in
 * every prior phase — webhook_events in particular is a firm-wide log
 * that fans out to N webhook_deliveries across possibly-multiple
 * subscriptions, so it deliberately is NOT reachable only through one
 * subscription and is given no separate Firm relation of its own,
 * consistent with how trust_approval_events was handled in Phase 13).
 * No new fillable column was added to firms itself in Phase 14 either.
 * Phase 15 addition: AI settings, firm-owned provider keys, retrieval
 * index record, usage events, and approval requests — the five
 * top-level firm-owned roots of the AI governance foundation
 * (ai_approval_events and ai_tool_actions are reachable transitively
 * through ai_approval_requests and ai_usage_events respectively, so no
 * additional relation was added for each of them, matching the
 * transitive-reachability reasoning used in every prior phase).
 * ai_policy_settings is platform-level, not firm-owned, and
 * deliberately has no Firm relation. No new fillable column was added
 * to firms itself in Phase 15 either — AI mode continues to live on
 * firm_settings.ai_mode (Phase 1), unchanged in shape; only its enum's
 * case values were updated (approved decision #1).
 * Phase 16 addition: deployment config, deployment health checks,
 * license files, and private enterprise settings — the four top-level
 * firm-owned roots of the dedicated/private enterprise deployment
 * governance foundation. fleet_migration_instance_status and
 * license_validation_events are reachable transitively (through
 * license_files and, for instance-status, through the non-firm-owned
 * fleet_migration_runs by firm_id query) and get no direct Firm
 * relation, matching the transitive-reachability convention used since
 * Phase 10. fleet_migration_runs itself is NOT a Firm relation — a
 * single run spans many firms, exactly like webhook_events fanning out
 * to N webhook_deliveries in Phase 14. integration_degradation_modes is
 * platform-level, not firm-owned, and deliberately has no Firm
 * relation. No new fillable column was added to firms itself in Phase
 * 16 either — deployment_mode/customer_type (Phase 1) are unchanged in
 * shape and are only read, never branched on, by Phase 16 code.
 * Phase 17 addition: retention policies, legal holds, offboarding
 * requests, key destruction requests, deletion requests, and access
 * reviews — the data-ownership/offboarding/governance foundation.
 * offboarding_exports, key_destruction_approvals, deletion_approvals,
 * and access_review_items are reachable transitively (through their
 * parent request/review row) and get no direct Firm relation, matching
 * the transitive-reachability convention used since Phase 10.
 * vendor_register, subprocessors, and data_processing_records are
 * platform-level (data_processing_records.firm_id is nullable but not
 * exclusively firm-owned) and deliberately have no Firm relation. No
 * new fillable column was added to firms itself in Phase 17 either.
 */
class Firm extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'organization_id',
        'billing_account_id',
        'name',
        'legal_name',
        'customer_type',
        'deployment_mode',
        'primary_country',
        'primary_state',
        'default_timezone',
        'default_currency',
        'data_region',
        'activation_status',
    ];

    protected function casts(): array
    {
        return [
            'customer_type' => CustomerType::class,
            'deployment_mode' => DeploymentMode::class,
            'activation_status' => FirmActivationStatus::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class);
    }

    public function firmSettings(): HasOne
    {
        return $this->hasOne(FirmSettings::class);
    }

    public function firmUsers(): HasMany
    {
        return $this->hasMany(FirmUser::class);
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(FirmLicense::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(FirmEntitlement::class);
    }

    public function activationChecklist(): HasOne
    {
        return $this->hasOne(ActivationChecklist::class);
    }

    public function tenantEncryptionKeys(): HasMany
    {
        return $this->hasMany(TenantEncryptionKey::class);
    }

    public function activeTenantEncryptionKey(): HasOne
    {
        return $this->hasOne(TenantEncryptionKey::class)->where('status', 'active');
    }

    public function clientCommunicationPreferences(): HasMany
    {
        return $this->hasMany(ClientCommunicationPreference::class);
    }

    public function communicationConsents(): HasMany
    {
        return $this->hasMany(CommunicationConsent::class);
    }

    /**
     * Phase 2 additions below.
     */
    public function leadSources(): HasMany
    {
        return $this->hasMany(LeadSource::class);
    }

    public function consultationOutcomes(): HasMany
    {
        return $this->hasMany(ConsultationOutcome::class);
    }

    public function firmLeads(): HasMany
    {
        return $this->hasMany(FirmLead::class);
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function parties(): HasMany
    {
        return $this->hasMany(Party::class);
    }

    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }

    public function firmPracticeAreas(): HasMany
    {
        return $this->hasMany(FirmPracticeArea::class);
    }

    public function installedTemplatePacks(): HasMany
    {
        return $this->hasMany(InstalledTemplatePack::class);
    }

    public function intakeSubmissions(): HasMany
    {
        return $this->hasMany(IntakeSubmission::class);
    }

    public function conflictCheckRuns(): HasMany
    {
        return $this->hasMany(ConflictCheckRun::class);
    }

    public function timelineEvents(): HasMany
    {
        return $this->hasMany(TimelineEvent::class);
    }

    /**
     * Phase 3 additions below.
     */
    public function employeeRates(): HasMany
    {
        return $this->hasMany(EmployeeRate::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function paymentPlans(): HasMany
    {
        return $this->hasMany(PaymentPlan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Phase 4 additions below.
     */
    public function documentRequests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function deadlines(): HasMany
    {
        return $this->hasMany(Deadline::class);
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    /**
     * Firm-specific notification template overrides only — global
     * defaults (firm_id null) are never returned by this relation.
     */
    public function notificationTemplates(): HasMany
    {
        return $this->hasMany(NotificationTemplate::class);
    }

    public function notificationEvents(): HasMany
    {
        return $this->hasMany(NotificationEvent::class);
    }

    public function documentChaseRules(): HasMany
    {
        return $this->hasMany(DocumentChaseRule::class);
    }

    public function matterReadinessScores(): HasMany
    {
        return $this->hasMany(MatterReadinessScore::class);
    }

    /**
     * Phase 5 additions below.
     */
    public function activationEvents(): HasMany
    {
        return $this->hasMany(FirmActivationEvent::class);
    }

    public function healthChecks(): HasMany
    {
        return $this->hasMany(HealthCheck::class);
    }

    public function backupRestoreTests(): HasMany
    {
        return $this->hasMany(BackupRestoreTest::class);
    }

    public function incidentEvents(): HasMany
    {
        return $this->hasMany(IncidentEvent::class);
    }

    public function maintenanceWindows(): HasMany
    {
        return $this->hasMany(MaintenanceWindow::class);
    }

    public function pilotFeedbackItems(): HasMany
    {
        return $this->hasMany(PilotFeedbackItem::class);
    }

    /**
     * Phase 6 additions below.
     */
    public function seatAllocations(): HasMany
    {
        return $this->hasMany(SeatAllocation::class);
    }

    public function usageRollups(): HasMany
    {
        return $this->hasMany(UsageRollup::class);
    }

    public function templateUpgradePreviews(): HasMany
    {
        return $this->hasMany(TemplateUpgradePreview::class);
    }

    public function templateUpgradeLogs(): HasMany
    {
        return $this->hasMany(TemplateUpgradeLog::class);
    }

    /**
     * Phase 7 additions below.
     */
    public function supportAccessRequests(): HasMany
    {
        return $this->hasMany(SupportAccessRequest::class);
    }

    public function supportAccessSessions(): HasMany
    {
        return $this->hasMany(SupportAccessSession::class);
    }

    public function implementationProject(): HasOne
    {
        return $this->hasOne(ImplementationProject::class);
    }

    public function customerSuccessHealthScores(): HasMany
    {
        return $this->hasMany(CustomerSuccessHealthScore::class);
    }

    /**
     * Phase 8 additions below.
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    public function exportJobs(): HasMany
    {
        return $this->hasMany(ExportJob::class);
    }

    public function migrationProjects(): HasMany
    {
        return $this->hasMany(MigrationProject::class);
    }

    /**
     * Phase 9 additions below.
     */
    public function emailAccounts(): HasMany
    {
        return $this->hasMany(EmailAccount::class);
    }

    public function emailMessages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    /**
     * Phase 10 additions below.
     */
    public function formDrafts(): HasMany
    {
        return $this->hasMany(FormDraft::class);
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    /**
     * Phase 11 additions below.
     */
    public function signatureRequests(): HasMany
    {
        return $this->hasMany(SignatureRequest::class);
    }

    public function pdfViewEvents(): HasMany
    {
        return $this->hasMany(PdfViewEvent::class);
    }

    /**
     * Phase 12 additions below.
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function accountingExportBatches(): HasMany
    {
        return $this->hasMany(AccountingExportBatch::class);
    }

    /**
     * Phase 13 additions below.
     */
    public function trustAccounts(): HasMany
    {
        return $this->hasMany(TrustAccount::class);
    }

    public function trustTransferRequests(): HasMany
    {
        return $this->hasMany(TrustTransferRequest::class);
    }

    public function trustRefundRequests(): HasMany
    {
        return $this->hasMany(TrustRefundRequest::class);
    }

    /**
     * Phase 14 additions below.
     */
    public function webhookSubscriptions(): HasMany
    {
        return $this->hasMany(WebhookSubscription::class);
    }

    /**
     * Phase 15 additions below.
     */
    public function aiSettings(): HasOne
    {
        return $this->hasOne(FirmAiSettings::class);
    }

    public function aiProviderKeys(): HasMany
    {
        return $this->hasMany(FirmAiProviderKey::class);
    }

    public function aiRetrievalIndex(): HasOne
    {
        return $this->hasOne(AiRetrievalIndex::class);
    }

    public function aiUsageEvents(): HasMany
    {
        return $this->hasMany(AiUsageEvent::class);
    }

    public function aiApprovalRequests(): HasMany
    {
        return $this->hasMany(AiApprovalRequest::class);
    }

    /**
     * Phase 16 additions below.
     */
    public function deploymentConfig(): HasOne
    {
        return $this->hasOne(DeploymentConfig::class);
    }

    public function deploymentHealthChecks(): HasMany
    {
        return $this->hasMany(DeploymentHealthCheck::class);
    }

    public function licenseFiles(): HasMany
    {
        return $this->hasMany(LicenseFile::class);
    }

    public function privateEnterpriseSettings(): HasOne
    {
        return $this->hasOne(PrivateEnterpriseSettings::class);
    }

    /**
     * Phase 17 additions below.
     */
    public function retentionPolicies(): HasMany
    {
        return $this->hasMany(RetentionPolicy::class);
    }

    public function legalHolds(): HasMany
    {
        return $this->hasMany(LegalHold::class);
    }

    public function offboardingRequests(): HasMany
    {
        return $this->hasMany(OffboardingRequest::class);
    }

    public function keyDestructionRequests(): HasMany
    {
        return $this->hasMany(KeyDestructionRequest::class);
    }

    public function deletionRequests(): HasMany
    {
        return $this->hasMany(DeletionRequest::class);
    }

    public function accessReviews(): HasMany
    {
        return $this->hasMany(AccessReview::class);
    }
}
