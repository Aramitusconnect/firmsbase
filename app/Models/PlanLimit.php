<?php

namespace App\Models;

use App\Enums\PlanLimitMetric;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PlanLimit — one numeric/enforceable limit for a Plan. limit_value
 * null means unlimited for that metric on that plan.
 */
class PlanLimit extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'plan_id',
        'metric',
        'limit_value',
    ];

    protected function casts(): array
    {
        return [
            'metric' => PlanLimitMetric::class,
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isUnlimited(): bool
    {
        return $this->limit_value === null;
    }
}
