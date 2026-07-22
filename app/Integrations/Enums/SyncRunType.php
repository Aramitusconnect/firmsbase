<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * SyncRunType — WHAT sync semantics a given `integration_sync_runs` row
 * executes (Checkpoint 6, orthogonal to SyncTriggerSource, which
 * answers WHY the run was created;
 * agent-6e-sync-run-item-cursor-semantics.md §5.3).
 *
 * Populated by a fixed, top-to-bottom precedence order at run-creation
 * time (SyncRunService), so every run has exactly one unambiguous
 * type even though a single run can loosely satisfy more than one
 * description: Retry > Repair > Manual > Scheduled > Outbound >
 * Incremental > Initial. See SyncRunService::determineRunType() for
 * the concrete precedence implementation.
 */
enum SyncRunType: string
{
    case Initial = 'initial';
    case Incremental = 'incremental';
    case Outbound = 'outbound';
    case Manual = 'manual';
    case Scheduled = 'scheduled';
    case Repair = 'repair';
    case Retry = 'retry';
}
