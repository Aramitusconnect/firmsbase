<?php

namespace App\ValueObjects;

use App\Enums\FleetMigrationRunStatus;

/**
 * FleetMigrationRunSummary — a read-only rollup of one
 * fleet_migration_runs row's per-instance counts, used for
 * admin-visibility query output (project rule: no UI in Phase 16 —
 * dashboard visibility is represented by service/query outputs).
 */
final readonly class FleetMigrationRunSummary
{
    public function __construct(
        public FleetMigrationRunStatus $status,
        public int $pendingCount,
        public int $appliedCount,
        public int $failedCount,
        public int $rolledBackCount,
        public int $skippedCount,
    ) {
    }

    public function totalInstances(): int
    {
        return $this->pendingCount + $this->appliedCount + $this->failedCount
            + $this->rolledBackCount + $this->skippedCount;
    }
}
