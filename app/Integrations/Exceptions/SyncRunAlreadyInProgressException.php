<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use App\Integrations\Models\IntegrationSyncRun;
use RuntimeException;

/**
 * SyncRunAlreadyInProgressException — thrown by
 * SyncRunService::startRun() when the partial unique index
 * `integration_sync_runs_one_active_per_scope` (firm_id,
 * firm_integration_id, resource_type, sync_direction WHERE status IN
 * ('pending','running')) rejects a second attempt to start a run for a
 * scope that already has a non-terminal run
 * (agent-6c-idempotency-concurrency.md §6; frozen-design-post-review.md
 * §6). Carries the ALREADY-existing run so the caller can surface "sync
 * already in progress" and, if useful, attach to the existing run's id
 * rather than silently discarding the trigger.
 */
final class SyncRunAlreadyInProgressException extends RuntimeException
{
    public function __construct(public readonly IntegrationSyncRun $existingRun)
    {
        parent::__construct(
            "A sync run is already in progress for firm_integration {$existingRun->firm_integration_id} ".
            "(resource_type={$existingRun->resource_type}, direction={$existingRun->sync_direction->value}): ".
            "existing run id {$existingRun->id}."
        );
    }
}
