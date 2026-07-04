<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\PlanStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan — GLOBAL admin-managed commercial plan catalog. Not tenant-owned
 * (it is platform reference/commercial data consumed BY firms/
 * organizations, not owned by one), so no BelongsToTenant.
 */
class Plan extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'name',
        'status',
        'price_cents',
        'billing_interval',
        'support_access_level',
        'trial_days',
        'trial_requires_card',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlanStatus::class,
            'billing_interval' => BillingInterval::class,
            'trial_requires_card' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function modules(): HasMany
    {
        return $this->hasMany(PlanModule::class);
    }

    public function limits(): HasMany
    {
        return $this->hasMany(PlanLimit::class);
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'default_plan_id');
    }

    public function orgLicenses(): HasMany
    {
        return $this->hasMany(OrgLicense::class);
    }

    public function firmLicenses(): HasMany
    {
        return $this->hasMany(FirmLicense::class);
    }

    public function platformSubscriptions(): HasMany
    {
        return $this->hasMany(PlatformSubscription::class);
    }
}
