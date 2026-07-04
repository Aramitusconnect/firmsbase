<?php

namespace App\Models;

use App\Enums\ConflictScope;
use App\Enums\ConsolidationMode;
use App\Enums\RecordStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Organization — an optional parent grouping over one or more firms.
 * Not itself tenant-owned (it IS part of the tenancy boundary), so it
 * does not use BelongsToTenant.
 *
 * Phase 6 addition: default_plan_id (the plan assigned to new member
 * firms unless overridden), org master licenses, and org-level pooled
 * seats.
 */
class Organization extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'name',
        'legal_name',
        'status',
        'primary_contact',
        'conflict_scope',
        'consolidation_mode',
        'default_plan_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
            'conflict_scope' => ConflictScope::class,
            'consolidation_mode' => ConsolidationMode::class,
        ];
    }

    public function firms(): HasMany
    {
        return $this->hasMany(Firm::class);
    }

    /**
     * Phase 6 additions below.
     */
    public function defaultPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'default_plan_id');
    }

    public function orgLicenses(): HasMany
    {
        return $this->hasMany(OrgLicense::class);
    }

    public function seatPools(): HasMany
    {
        return $this->hasMany(SeatPool::class);
    }

    public function billingAccounts(): HasMany
    {
        return $this->hasMany(BillingAccount::class);
    }
}
