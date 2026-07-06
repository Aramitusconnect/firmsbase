<?php

namespace App\Enums;

/**
 * FleetMigrationRunStatus — fleet_migration_runs.status. Simulated
 * fleet-migration lifecycle only (project rule: Phase 16 fleet
 * migration tooling is simulated/foundation only — no real migration
 * ever executes).
 */
enum FleetMigrationRunStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Halted = 'halted';
    case RolledBack = 'rolled_back';
    case Completed = 'completed';
}
