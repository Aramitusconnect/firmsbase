<?php

namespace App\Models;

use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HealthCheck — firm_id is NULLABLE (null = platform-infrastructure
 * check, non-null = firm-specific, e.g. a tenant isolation anomaly).
 * Deliberately does NOT use BelongsToTenant: that trait's global scope
 * adds `WHERE firm_id = <active firm>`, which would incorrectly hide
 * every platform-wide (firm_id NULL) row whenever a tenant context is
 * active — the exact reason Phase 4's NotificationTemplate also
 * avoided the trait. Append-only — a new row is written every run.
 */
class HealthCheck extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'check_type',
        'status',
        'detail',
        'checked_at',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'check_type' => HealthCheckType::class,
            'status' => HealthCheckStatus::class,
            'checked_at' => 'datetime',
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function isHealthy(): bool
    {
        return $this->status === HealthCheckStatus::Healthy;
    }
}
