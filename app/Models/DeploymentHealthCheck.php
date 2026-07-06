<?php

namespace App\Models;

use App\Enums\DeploymentHealthReportMode;
use App\Enums\HealthCheckStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DeploymentHealthCheck — append-only (no updated_at, booted() hook
 * throws on update/delete, mirroring WebhookEvent/AiUsageEvent's exact
 * immutability pattern). The minimum health envelope contract for one
 * dedicated/private deployment heartbeat. Different table from Phase
 * 5's health_checks (SaaS-internal infra monitoring) — status reuses
 * HealthCheckStatus's exact case values, no second vocabulary.
 */
class DeploymentHealthCheck extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $table = 'deployment_health_checks';

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'heartbeat_at',
        'version',
        'migration_status',
        'status',
        'reported_via',
        'detail',
    ];

    protected $attributes = [
        'reported_via' => 'live',
    ];

    protected function casts(): array
    {
        return [
            'heartbeat_at' => 'datetime',
            'status' => HealthCheckStatus::class,
            'reported_via' => DeploymentHealthReportMode::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('deployment_health_checks is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('deployment_health_checks is append-only and cannot be deleted.');
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
