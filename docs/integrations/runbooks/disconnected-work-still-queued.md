# Runbook: Disconnected Work Still Queued

## Symptom

A connection has been disconnected (`ConnectionStatus::Disconnected`) or its credential revoked, but outbox events / sync items for that connection are still pending or in-flight.

## Real source involved

`ProviderConnectionService::disconnect()`, `IntegrationCredentialService::revoke()`, `RequeueIneligibilityReason::ConnectionDisconnected` / `::CredentialRevoked` (the diagnostic surfaces this exact scenario when a requeue is attempted post-disconnect).

## Diagnosis

Disconnecting a connection does not itself retroactively cancel already-enqueued outbox events or in-progress sync items — `ProviderConnectionService::disconnect()` transitions the connection's own status and revokes its credential, but pending queue work is a separate concern. When such an item is eventually claimed for processing (or a requeue is attempted), the connection-state check surfaces `ConnectionDisconnected`/`CredentialRevoked` as the ineligibility reason rather than silently attempting (and failing) a real operation against a dead connection.

## Required role

Platform-plane investigation: SupportAgent+ with active support-access session, or SuperAdmin/PlatformAdmin/ImplementationSpecialist.

## Approved interface

`IntegrationOutboxEventService::cancel()` (outbox events specifically — a guarded UPDATE with its own required starting state, distinct from `fail()`) is the correct mechanism for explicitly cancelling still-queued outbox work for a connection that is no longer expected to process it. For sync items, there is no equivalent dedicated "cancel because disconnected" action beyond letting them naturally fail with `ConnectionDisconnected` when claimed — this is a narrower capability than the outbox side, disclosed here rather than assumed to exist.

## Steps

1. Confirm the connection's actual status (`Disconnected`/credential revoked) via `PlatformFirmIntegrationDetailPage` or the firm's own view.
2. For outbox events still `Pending`: consider `cancel()` if the work is genuinely no longer relevant (the connection is gone and won't be reconnected in a way that would make the queued work valid again).
3. For outbox events already `Processing` when disconnect happened: allow them to run their natural course — they will fail against the dead connection's absent credential and transition normally through the fail/dead-letter path, which is safe and expected.
4. For sync items: no proactive cancel exists — they will naturally surface `ConnectionDisconnected` when claimed for retry, which is the correct, safe outcome (no accidental processing against a dead connection).
5. If the firm reconnects the same provider later (a new connection, not a resurrection of the old one), queued work tied to the *old* connection id remains tied to it — it does not automatically transfer to the new connection.

## Prohibited actions

Never manually force a disconnected connection's queued work through by re-activating the old connection's status without the firm actually completing a real reconnect flow — status is meant to reflect genuine connection state, not be toggled to unblock a queue.

## Evidence to capture

Firm id, connection id, disconnect timestamp, count and status of items still queued against the dead connection, whether any were explicitly cancelled.

## Escalation condition

A large volume of queued work stuck against a disconnected connection with no clear cancellation path (particularly on the sync-item side, given the narrower capability there) should be escalated to engineering if it appears to be accumulating rather than naturally draining via the fail path.

## Recovery verification

No outbox events remain `Pending`/`Processing` against a disconnected connection's id; sync items against it have naturally reached a terminal state (most likely `FailedPermanent` via the `ConnectionDisconnected` path).
