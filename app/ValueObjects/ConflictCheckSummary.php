<?php

namespace App\ValueObjects;

use App\Enums\ConflictCheckResultStatus;
use App\Enums\ConflictCheckRunStatus;

/**
 * ConflictCheckSummary — the result of ConflictCheckService::run(),
 * used by MatterOpeningService to decide whether a matter is allowed
 * to leave conflict_check_required/conflict_review. Deliberately
 * exposes hasConfirmedConflicts/hasPossibleMatches separately —
 * "possible match" (e.g. a common name) must route to human review,
 * never silently block or silently clear (edge-case catalog, "Conflict
 * false positive").
 */
final readonly class ConflictCheckSummary
{
    public function __construct(
        public int $conflictCheckRunId,
        public ConflictCheckRunStatus $runStatus,
        public int $resultCount,
        public bool $hasConfirmedConflicts,
        public bool $hasPossibleMatches,
    ) {
    }

    /**
     * True only when the run completed with zero confirmed conflicts
     * and zero unresolved possible matches — the only state in which
     * MatterOpeningService may allow a matter to open.
     */
    public function isClearToProceed(): bool
    {
        return $this->runStatus === ConflictCheckRunStatus::Completed
            && ! $this->hasConfirmedConflicts
            && ! $this->hasPossibleMatches;
    }
}
