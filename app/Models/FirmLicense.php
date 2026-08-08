<?php

namespace App\Models;

use App\Enums\BillingMode;
use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Enums\LicenseStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Phase 6 addition: org_license_id (nullable — set when this firm's
 * license descends from an organization master license), plan_id,
 * deployment_mode/customer_type (reusing the EXISTING enums of the same
 * name already used by firms.deployment_mode/customer_type — not new
 * enums), billing_mode (new BillingMode enum), and the polymorphic
 * license_events audit relation (shared with OrgLicense).
 */
class FirmLicense extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $fillable = [
        'firm_id',
        'billing_account_id',
        'org_license_id',
        'plan_id',
        'purchased_seats',
        'license_key',
        'license_status',
        'deployment_mode',
        'customer_type',
        'billing_mode',
        'starts_at',
        'renews_at',
        'expires_at',
        'cancelled_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'license_status' => LicenseStatus::class,
            'deployment_mode' => DeploymentMode::class,
            'customer_type' => CustomerType::class,
            'billing_mode' => BillingMode::class,
            'purchased_seats' => 'integer',
            'starts_at' => 'datetime',
            'renews_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Phase 6 additions below.
     */
    public function orgLicense(): BelongsTo
    {
        return $this->belongsTo(OrgLicense::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function events(): MorphMany
    {
        return $this->morphMany(LicenseEvent::class, 'licensable');
    }
}
