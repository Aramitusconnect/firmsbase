<?php

namespace App\Models;

use App\Enums\FleetMigrationInstanceStatus as FleetMigrationInstanceStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FleetMigrationInstanceStatus (MODEL) — named identically to
 * App\Enums\FleetMigrationInstanceStatus per the approved Phase 16
 * spec. Different namespaces, no PHP conflict, but this file (needing
 * both) aliases the enum import as FleetMigrationInstanceStatusEnum to
 * keep the two unambiguous at every use site — every other file that
 * needs both must do the same. One row per (fleet_migration_run, firm)
 * pair. The ONLY writer is FleetMigrationOrchestrationService.
 */
class FleetMigrationInstanceStatus extends Model
{
    use HasFactory;

    protected $table = 'fleet_migration_instance_status';

    protected $fillable = [
        'fleet_migration_run_id',
        'firm_id',
        'status',
        'applied_version',
        'error_detail',
        'attempted_at',
        'completed_at',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => FleetMigrationInstanceStatusEnum::class,
            'attempted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function fleetMigrationRun(): BelongsTo
    {
        return $this->belongsTo(FleetMigrationRun::class);
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
