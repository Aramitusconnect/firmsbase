<?php

namespace App\Models;

use App\Enums\ConversionEventType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversionEvent extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'platform_lead_id',
        'opportunity_id',
        'trial_request_id',
        'organization_id',
        'event_type',
        'occurred_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => ConversionEventType::class,
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function platformLead(): BelongsTo
    {
        return $this->belongsTo(PlatformLead::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function trialRequest(): BelongsTo
    {
        return $this->belongsTo(TrialRequest::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
