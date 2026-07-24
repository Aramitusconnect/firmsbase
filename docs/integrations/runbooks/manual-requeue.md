# Runbook: Manual Requeue

## Symptom

An outbox event or sync item is stuck in a terminal-but-actionable state (`DeadLettered` for outbox; `FailedPermanent` for sync items) and a firm or operator wants to retry it.

## Real source involved

`IntegrationOutboxEventService::requeue()` / `diagnoseRequeueIneligibility()`; `SyncItemService::requeueFromFailedPermanent()` / `diagnoseRequeueIneligibility()`; `RequeueIneligibilityReason` enum; `IntegrationRequeueAuditLogger`; platform-plane: `PlatformFirmIntegrationBoundedAccessService::requeueOutboxEvent()` / `requeueSyncItem()` / `diagnoseOutboxRequeueIneligibility()` / `diagnoseSyncItemRequeueIneligibility()`.

## The two-step discipline

1. **Diagnose first** (read-only, non-authoritative): call the corresponding `diagnose*Ineligibility()` method to surface a specific, human-readable reason if a requeue would fail. This exists purely as a UX layer — the guarded UPDATE underneath is the real, authoritative gate, and can still reject an attempt for reasons the diagnostic didn't anticipate (e.g. a race between the diagnostic read and the requeue write).
2. **Requeue** (the actual guarded UPDATE): both `requeue()`/`requeueFromFailedPermanent()` collapse every rejection cause into an indistinguishable `null` return — a `null` result means the requeue did not happen, full stop; the diagnostic from step 1 is the only way to know *why* after the fact.

## Ineligibility reasons (`RequeueIneligibilityReason`)

| Reason | Meaning |
|---|---|
| `NotFoundOrCrossFirm` | Item not found for this firm — either doesn't exist or belongs to another firm (RLS-consistent behavior, not an error to "fix") |
| `NotEligibleStatus` | Item isn't in a state that can be requeued |
| `RequeueCeilingReached` | Outbox-only — item has hit its requeue attempt limit; repeatedly requeuing without understanding the root cause is not a valid response — see [outbox-dead-letters.md](outbox-dead-letters.md) |
| `Superseded` | A later, already-processed item superseded this one — requeuing would be redundant/incorrect |
| `ConnectionDisconnected` | Reconnect the integration before requeuing — see [disconnected-work-still-queued.md](disconnected-work-still-queued.md) |
| `CredentialRevoked` | No active credential — reconnect before requeuing |

## Required role

Firm-plane: connect/configure ceiling (FirmOwner, Attorney) for their own firm's items. Platform-plane: SupportAgent+ with active support-access session, or SuperAdmin/PlatformAdmin/ImplementationSpecialist, via `PlatformFirmIntegrationBoundedAccessService`.

## Approved interface

Firm: `FirmIntegrationResource`'s `FailedItemsRelationManager`. Platform: `PlatformFirmIntegrationBoundedAccessService::requeueOutboxEvent()` / `requeueSyncItem()` — every call requires a supplied `reasonCode`, captured for audit.

## Steps

1. Identify the item and its current terminal state.
2. Call the appropriate `diagnose*Ineligibility()` method first.
3. If eligible, call the requeue method with a meaningful `reasonCode`.
4. If `null` is returned despite the diagnostic suggesting eligibility, do not retry blindly in a loop — this indicates a race or an edge case the diagnostic didn't anticipate; re-diagnose before trying again.
5. Confirm the item transitions out of its terminal state and is picked up by the normal dispatch/retry cycle.

## Prohibited actions

Never bypass either service's guarded UPDATE by writing directly to the underlying status columns. Never requeue repeatedly in an automated loop without investigating the underlying failure cause first, especially after hitting `RequeueCeilingReached`.

## Evidence to capture

Firm id, item id and type (outbox event vs. sync item), ineligibility reason if any, `reasonCode` supplied, requeuing actor.

## Escalation condition

Repeated ineligibility for the same item/connection, or a pattern of requeues immediately re-failing, should be escalated to engineering rather than requeued again — see [sync-failures.md](sync-failures.md) and [outbox-dead-letters.md](outbox-dead-letters.md) for root-cause investigation.

## Recovery verification

Item successfully processes to a genuine success terminal state (not merely re-entering the same failed state) on the next dispatch cycle. `IntegrationRequeueAuditLogger` entry recorded for the requeue action.
