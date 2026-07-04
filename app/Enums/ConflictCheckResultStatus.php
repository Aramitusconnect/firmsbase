<?php

namespace App\Enums;

/**
 * ConflictCheckResultStatus — conflict_check_results.status. Not given
 * exact values by the master plan (proposed/approved during Phase 2
 * planning). PossibleMatch is required by the edge-case catalog's
 * "Conflict false positive" rule: a common-name match must route to
 * review, never silently block or silently clear.
 */
enum ConflictCheckResultStatus: string
{
    case Clear = 'clear';
    case PossibleMatch = 'possible_match';
    case ConfirmedConflict = 'confirmed_conflict';
    case Dismissed = 'dismissed';
}
