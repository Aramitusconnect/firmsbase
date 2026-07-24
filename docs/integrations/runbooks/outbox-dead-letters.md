# Runbook: Outbox Dead Letters

## Symptom

One or more `integration_outbox_events` rows in `DeadLettered` status — attempts exhausted or the failure was explicitly non-retryable (`OutboxHandlerPermanentException`).

## Real source involved

`IntegrationOutboxEventService::fail()` (the guarded UPDATE that transitions a row to `DeadLettered` when attempts are exhausted or the handler threw a permanent exception), `OutboxEventStatus::DeadLettered` (terminal state), `IntegrationOutboxEventService::requeue()` / `diagnoseRequeueIneligibility()`, `IntegrationRequeueAuditLogger`.

## Required role

Firm-plane: connect/configure ceiling (FirmOwner, Attorney) can view and request requeue for their own firm's dead-lettered items via `FirmIntegrationResource`'s `FailedItemsRelationManager`. Platform-plane: SupportAgent+ with active support-access session, or SuperAdmin/PlatformAdmin/ImplementationSpecialist, via `PlatformFirmIntegrationBoundedAccessService::requeueOutboxEvent()`.

## Approved interface

- Firm: `FirmIntegrationResource`'s failed-items relation manager.
- Platform: `PlatformFirmIntegrationBoundedAccessService::requeueOutboxEvent(PlatformAdmin $admin, Firm $firm, int $outboxEventId, string $reasonCode): ?IntegrationOutboxEvent` and `diagnoseOutboxRequeueIneligibility(PlatformAdmin $admin, Firm $firm, int $outboxEventId): ?RequeueIneligibilityReason`.

## Steps

1. Identify the dead-lettered row and its terminal-cause context (was it attempt-exhaustion or a permanent handler exception — `OutboxHandlerPermanentException` implies the handler itself determined this should never be retried, which is a stronger signal than mere exhaustion).
2. Before requeuing, call `diagnoseOutboxRequeueIneligibility()` — a **read-only diagnostic**, never authoritative on its own — to surface a human-readable reason if a requeue attempt would be rejected (see [sync-and-outbox.md](../sync-and-outbox.md) §3 for why this is explicitly non-authoritative: the actual `requeue()` guarded UPDATE is the real gate, this is only a UX-layer explanation).
3. If eligible, call `requeue()` with a `reasonCode` — this re-queues the row (implementation detail: resets it to a retryable state) and is captured by `IntegrationRequeueAuditLogger`.
4. If `diagnoseOutboxRequeueIneligibility()` returns `RequeueCeilingReached` (outbox-only reason — never returned for sync items), the row has exhausted its requeue budget; requeuing further requires understanding *why* the underlying operation keeps failing, not simply requeuing again — investigate the root cause (see [sync-failures.md](sync-failures.md)) rather than repeatedly forcing requeues.

## Prohibited actions

Never attempt to bypass the guarded UPDATE by writing directly to `integration_outbox_events.status` — the guard exists specifically to make an already-terminal row's mutation attempts affect zero rows, a correctness property that must not be worked around.

## Evidence to capture

Firm id, outbox event id, `event_type`, terminal cause (exhaustion vs. permanent exception), requeue attempt outcome, `reasonCode` supplied.

## Escalation condition

A pattern of the same `event_type` repeatedly dead-lettering across multiple firms should be escalated to engineering — likely indicates a handler-level defect (see `OutboxEventHandlerRegistry`, [sync-and-outbox.md](../sync-and-outbox.md) §1) rather than a per-firm data issue.

## Recovery verification

Row transitions out of `DeadLettered` back to `Pending` and is subsequently claimed and completed (`OutboxEventStatus::Completed`) by the normal dispatch cycle.
