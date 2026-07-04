<?php

namespace App\Models;

use App\Enums\OpportunityStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Opportunity extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'platform_lead_id',
        'assigned_to',
        'status',
        'estimated_seats',
        'estimated_mrr_cents',
        'expected_close_at',
        'lost_reason',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OpportunityStatus::class,
            'expected_close_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function platformLead(): BelongsTo
    {
        return $this->belongsTo(PlatformLead::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'assigned_to');
    }

    public function demoEvents(): HasMany
    {
        return $this->hasMany(DemoEvent::class);
    }

    public function trialRequests(): HasMany
    {
        return $this->hasMany(TrialRequest::class);
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
