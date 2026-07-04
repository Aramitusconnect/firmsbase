<?php

namespace App\Models;

use App\Enums\PlatformLeadStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * PlatformLead — PLATFORM sales pipeline lead (a prospective law firm).
 * Deliberately distinct from Phase 2's FirmLead (client intake), never
 * shares a table or model with it. Not tenant-owned, no BelongsToTenant
 * — platform-staff-owned, cross-firm by design.
 */
class PlatformLead extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'company_name',
        'contact_name',
        'contact_email',
        'contact_phone',
        'source',
        'status',
        'assigned_to',
        'notes',
        'converted_organization_id',
        'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlatformLeadStatus::class,
            'converted_at' => 'datetime',
        ];
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'assigned_to');
    }

    public function convertedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'converted_organization_id');
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function salesRepAssignments(): MorphMany
    {
        return $this->morphMany(SalesRepAssignment::class, 'assignable');
    }

    public function salesTasks(): MorphMany
    {
        return $this->morphMany(PlatformSalesTask::class, 'taskable');
    }

    public function conversionEvents(): HasMany
    {
        return $this->hasMany(ConversionEvent::class);
    }
}
