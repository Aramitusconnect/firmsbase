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
}
