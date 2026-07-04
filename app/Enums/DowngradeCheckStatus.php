<?php

namespace App\Enums;

/**
 * DowngradeCheckStatus — the result status carried inside
 * DowngradeEvaluationResult (not a persisted table column by itself;
 * downgrade evaluations are computed at read time from seat/usage/
 * module state, mirroring Phase 5's ProductionReadinessResult
 * pattern). Proposed during Phase 6 planning and approved.
 */
enum DowngradeCheckStatus: string
{
    case Safe = 'safe';
    case BlockedSeatOveruse = 'blocked_seat_overuse';
    case BlockedStorageOveruse = 'blocked_storage_overuse';
    case BlockedModuleInUse = 'blocked_module_in_use';
    case RequiresAdminResolution = 'requires_admin_resolution';
}
