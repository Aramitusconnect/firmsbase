<?php

namespace App\Models;

use App\Enums\FleetMigrationRunStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FleetMigrationRun — one simulated fleet-wide migration rollout, not
 * firm-owned (a single run spans many firms) — no BelongsToTenant.
 * The ONLY writer is FleetMigrationOrchestrationService.
 */
class FleetMigrationRun extends Model
{
    use HasFactory, HasPublicUuid;

    protected $table = 'fleet_migration_runs';

    protected $fillable = [
        'migration_identifier',
        'status',
        'initiated_by',
        'halted_reason',
        'started_at',
        'completed_at',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => FleetMigrationRunStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function instanceStatuses(): HasMany
    {
        return $this->hasMany(\App\Models\FleetMigrationInstanceStatus::class);
    }
}
