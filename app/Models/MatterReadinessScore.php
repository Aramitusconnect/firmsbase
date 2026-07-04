<?php

namespace App\Models;

use App\Enums\MatterReadinessStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MatterReadinessScore — one current row per matter (unique
 * matter_id), recomputed IN PLACE by MatterReadinessService.
 * breakdown_json only ever reflects currently-registered, active
 * components — never a stale/removed one. No uuid — internal/staff-
 * facing only in Phase 4.
 */
class MatterReadinessScore extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'matter_id',
        'status',
        'satisfied_count',
        'total_count',
        'breakdown_json',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MatterReadinessStatus::class,
            'breakdown_json' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }
}
