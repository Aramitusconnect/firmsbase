<?php

namespace App\Models;

use App\Enums\LicenseStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * OrgLicense — an organization's master license/plan. Reuses the
 * EXISTING LicenseStatus enum as-is (no competing OrgLicenseStatus).
 * Not tenant-owned (organization-owned, not firm-owned) — no
 * BelongsToTenant.
 */
class OrgLicense extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'organization_id',
        'plan_id',
        'billing_account_id',
        'license_key',
        'license_status',
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
            'starts_at' => 'datetime',
            'renews_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class);
    }

    public function firmLicenses(): HasMany
    {
        return $this->hasMany(FirmLicense::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function events(): MorphMany
    {
        return $this->morphMany(LicenseEvent::class, 'licensable');
    }
}
