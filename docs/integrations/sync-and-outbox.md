# Sync and Outbox

## 1. Outbox (outbound events)

`integration_outbox_events` implements the outbox pattern for outbound provider calls. `IntegrationOutboxEventService` (`app/Integrations/Services/IntegrationOutboxEventService.php`) owns the full lifecycle:

- `recordOnce()` — idempotent write.
- `claim(int $firmId, int $limit = 1)` — atomic claim (lock-token based).
- `complete()` / `release()` / `fail()` / `cancel()` — guarded UPDATEs, each naming its required starting state explicitly so an attempt to act on an already-terminal row affects zero rows.
- `requeue()` / `diagnoseRequeueIneligibility()` — see [runbooks/manual-requeue.md](runbooks/manual-requeue.md).

**States** (`OutboxEventStatus`): `Pending`, `Processing`, `Completed`, `DeadLettered`, `Cancelled`. `Completed`, `DeadLettered`, `Cancelled` are terminal. There is no resting `failed` state — every failure is resolved immediately, in the same guarded UPDATE, into either `Pending` (attempts remain, `next_attempt_at` pushed into the future) or `DeadLettered` (attempts exhausted or explicitly non-retryable).

**Dispatch**: `App\Jobs\OutboxDispatchJob` (`ShouldQueue`) claims and processes rows, resolving the concrete handler through `App\Integrations\Outbox\OutboxEventHandlerRegistry` — a closed, array-driven map from `event_type` string to handler class (modeled on `ProviderRegistry`'s shape). The registry never branches on `event_type` itself; all such logic lives on the handler. Today the registry has exactly **one** entry: `'test.resource.push_retry' => TestResourcePushHandler::class`.

**Handler exceptions** (`app/Integrations/Outbox/Exceptions/`): `OutboxHandlerTransientException` (retry), `OutboxHandlerReleaseException` (release the claim, retry later), `OutboxHandlerPermanentException` (dead-letter immediately), `UnknownOutboxEventTypeException` (registry miss).

**Backoff**: `integrations.outbox.max_backoff_seconds` (default 3600) is a single shared ceiling used both by `IntegrationOutboxEventService::fail()`'s own retry delay **and** `RetryAfterParser`'s clamp on a provider-supplied `Retry-After` signal — deliberately one shared maximum, not two independently configured ceilings.

See [runbooks/outbox-dead-letters.md](runbooks/outbox-dead-letters.md) and [runbooks/manual-requeue.md](runbooks/manual-requeue.md).

## 2. Pull/push sync

- **Pull**: `App\Jobs\PullSyncJob` — dispatches through `SupportsPullSyncContract`, tracked via `IntegrationSyncRun`/`IntegrationSyncItem`/`IntegrationSyncCursor`.
- **Push**: `App\Jobs\PushSyncJob` — dispatches through `SupportsPushSyncContract`, records conflicts via `IntegrationConflictService` and mappings via `IntegrationExternalMappingService`.
- **Retry polling**: `App\Jobs\SyncRetryPollJob` — retries items in `FailedRetryable` status.

`SyncRunService::startRun()` throws `SyncRunAlreadyInProgressException` if a run is already active for the connection — sync runs for a given connection are serialized, never concurrent.

**Sync item states** (`SyncItemStatus`): `Pending`, `Retrying`, `Succeeded`, `FailedPermanent`, `Skipped`, `FailedRetryable`. Terminal: `Succeeded`, `FailedPermanent`, `Skipped`. Cursor-safety: `Pending`/`Retrying`/`FailedRetryable` block cursor advancement past a batch; the three terminal-or-skipped states do not (`SyncCursorService::isCursorSafeBatch()`).

**Cursor conflicts**: `SyncCursorService::advance()` uses an optimistic-concurrency CAS (`UPDATE ... WHERE cursor_version = ? RETURNING *`); zero affected rows throws `CursorVersionConflictException`. The frozen decision is **reject, never automatically serialize-and-retry** — thrown from inside the same transaction that wrote the batch's item-terminal-status rows, so the caller's whole batch rolls back together. See [runbooks/cursor-conflict.md](runbooks/cursor-conflict.md).

**Mapping conflicts**: `IntegrationExternalMappingService::recordMapping()` throws `ExternalMappingConflictException` when a local record is already mapped to a *different* external object for the same connection — a genuine data-integrity conflict, never silently swallowed. See [runbooks/mapping-conflict.md](runbooks/mapping-conflict.md).

**Conflict resolution workflow**: `IntegrationConflictService::recordDetection()` / `transitionStatus()` / `proposeResolution()` — see `ConflictStatus` enum and [runbooks/conflict-approval.md](runbooks/conflict-approval.md).

## 3. Requeue

Both `IntegrationOutboxEventService::requeue()` and `SyncItemService::requeueFromFailedPermanent()` are guarded UPDATEs that collapse every rejection cause into an indistinguishable `null` return on failure. `RequeueIneligibilityReason` (`app/Integrations/Enums/RequeueIneligibilityReason.php`) is a closed vocabulary purely for a **read-only diagnostic re-check** (`diagnoseRequeueIneligibility()` on both services) to surface a specific, UI-facing reason after the fact — it is explicitly non-authoritative and never gates or retries the actual requeue itself. Reasons: `NotFoundOrCrossFirm`, `NotEligibleStatus`, `RequeueCeilingReached` (outbox only), `Superseded`, `ConnectionDisconnected`, `CredentialRevoked`. See [runbooks/manual-requeue.md](runbooks/manual-requeue.md).

## 4. Retention

See [webhooks.md](webhooks.md) §5 and [configuration.md](configuration.md) for the outbox/sync/conflict/OAuth-state retention windows, and [runbooks/retention-kill-switch-runbook.md](runbooks/retention-kill-switch-runbook.md) for the firm-data sweep kill switch.
