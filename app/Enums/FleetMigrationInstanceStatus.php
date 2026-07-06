<?php

namespace App\Enums;

/**
 * FleetMigrationInstanceStatus — fleet_migration_instance_status.status.
 * Deliberately named identically to the FleetMigrationInstanceStatus
 * MODEL (App\Models\FleetMigrationInstanceStatus) per the approved
 * Phase 16 spec — different namespaces, no PHP conflict, but any file
 * needing both must alias one on import (documented at each such call
 * site). Skipped covers an instance that was never attempted because
 * an earlier instance's Failed status halted the run first.
 */
enum FleetMigrationInstanceStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
    case Skipped = 'skipped';
}
