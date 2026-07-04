<?php

namespace App\Models;

use App\Enums\CommissionPlanStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionPlan extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'name',
        'plan_id',
        'rate_type',
        'rate_value',
        'holding_period_days',
        'status',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CommissionPlanStatus::class,
            'rate_value' => 'decimal:2',
            'holding_period_days' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function commissionEvents(): HasMany
    {
        return $this->hasMany(CommissionEvent::class);
    }
}
