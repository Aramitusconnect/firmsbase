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
}
