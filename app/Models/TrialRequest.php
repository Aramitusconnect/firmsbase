<?php

namespace App\Models;

use App\Enums\TrialRequestStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrialRequest extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'opportunity_id',
        'organization_id',
        'status',
        'requested_at',
        'provisioned_at',
        'expires_at',
        'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TrialRequestStatus::class,
            'requested_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'expires_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function conversionEvents(): HasMany
    {
        return $this->hasMany(ConversionEvent::class);
    }
}
