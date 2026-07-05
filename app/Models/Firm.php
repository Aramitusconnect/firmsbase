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
}
