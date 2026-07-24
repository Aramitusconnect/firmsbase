# Runbook: Sync Failures

## Symptom

A sync run (`IntegrationSyncRun`) or individual sync items (`IntegrationSyncItem`) are failing — either `FailedRetryable` (will be retried by `SyncRetryPollJob`) or `FailedPermanent` (terminal, requires manual requeue).

## Real source involved

`App\Jobs\PullSyncJob` / `PushSyncJob` / `SyncRetryPollJob`, `SyncRunService` (`transitionStatus()`, `determineTerminalStatus()`), `SyncItemService` (`requeueFromFailedPermanent()`, `diagnoseRequeueIneligibility()`), `SyncItemStatus`, `SyncRunStatus`.

## Diagnosis

- **`FailedRetryable`**: expected to self-heal via `SyncRetryPollJob` on its `everyThreeMinutes()` cadence — not itself an incident unless retries are consistently exhausting to `FailedPermanent`.
- **`FailedPermanent`**: terminal without intervention — requires `SyncItemService::requeueFromFailedPermanent()` (see [manual-requeue.md](manual-requeue.md)).
- **Run-level terminal status** (`SyncRunService::determineTerminalStatus(itemsTotal, itemsSucceeded, itemsFailed)`) reflects the aggregate outcome — a run can complete with partial success; review the specific item-level failures, not just the run's own status, to understand what actually failed.
- **`SyncRunAlreadyInProgressException`**: thrown if a new run is attempted while one is already active for the connection — sync runs are serialized per connection, never concurrent. This is expected, correct behavior, not a bug, if two triggers (e.g. a scheduled run and a manual nudge) overlap.

## Required role

Firm-plane: view ceiling (FirmOwner, Attorney, Paralegal, LegalAssistant) can view sync run/item detail via `SyncRunsRelationManager`/`FailedItemsRelationManager`. Platform-plane: SupportAgent+ with active support-access session, or SuperAdmin/PlatformAdmin/ImplementationSpecialist.

## Approved interface

`FirmIntegrationResource`'s relation managers (firm-plane). `PlatformFirmIntegrationDetailPage` (platform-plane, read). `PlatformFirmIntegrationBoundedAccessService::requeueSyncItem()` / `diagnoseSyncItemRequeueIneligibility()` (platform-plane, mutating).

## Steps

1. Identify whether the failure is at the run level (whole run failed to start/complete) or item level (specific items within an otherwise-progressing run).
2. For item-level `FailedRetryable`: no action needed — allow `SyncRetryPollJob` to retry on its normal cadence, or use `NudgeIntegrationQueueAsSupportAction` to accelerate if urgent.
3. For item-level `FailedPermanent`: see [manual-requeue.md](manual-requeue.md).
4. For run-level failures: check whether `SyncRunAlreadyInProgressException` explains it (overlapping triggers, not a real failure) before investigating further.
5. Cross-reference `IntegrationConflictService`-recorded conflicts if the failures correlate with data conflicts rather than transport/auth failures — see [cursor-conflict.md](cursor-conflict.md) and [mapping-conflict.md](mapping-conflict.md).

## Prohibited actions

Never manually edit `integration_sync_items`/`integration_sync_runs` status columns directly — always go through the guarded service methods, which enforce the correct state-machine transitions and audit trail.

## Evidence to capture

Firm id, connection id, sync run id, item-level failure reasons (sanitized — never raw provider error bodies that might contain secret-shaped data), run-level terminal status.

## Escalation condition

A sustained pattern of `FailedPermanent` items across many firms for the same provider/resource type should be escalated to engineering — likely indicates a handler or provider-API-shape issue rather than isolated per-firm data problems.

## Recovery verification

Subsequent sync run for the connection reaches `SyncRunStatus`'s success terminal state with `itemsFailed` at or near zero.
