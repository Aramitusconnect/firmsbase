# Runbook: Webhook Backlog

## Symptom

A growing number of received-but-unprocessed webhook events for one or more connections — receipts/events are being written but downstream processing is falling behind.

## Real source involved

`integration_inbound_webhook_events`, `App\Jobs\OutboxDispatchJob` / any handler triggered from a webhook-derived outbox event, `integrations:outbox:dispatch` scheduled command (`everyMinute()`).

## Honest capability statement — read this first

**There is no operator-facing webhook-drain tool today.** If a backlog forms because downstream processing (whatever handler chain a webhook event feeds) cannot keep pace, there is no dedicated "drain the backlog faster" operator action beyond the general-purpose `NudgeIntegrationQueueAsSupportAction` (which dispatches the same `OutboxDispatchJob`/`SyncRetryPollJob` tick the scheduler already performs on its normal cadence — it does not add processing capacity, only accelerates the next tick). **This scenario requires engineering intervention** for anything beyond that — no self-service operator action exists today to add worker capacity or fundamentally change processing throughput.

## Required role

Platform-plane investigation: SupportAgent+ with active support-access session (per-firm) or SuperAdmin/PlatformAdmin/ImplementationSpecialist (cross-firm pattern).

## Approved interface

`PlatformFirmIntegrationDetailPage` / `PlatformIntegrationOverviewPage` for per-firm/aggregate visibility into sync-outcome and health state (proxy signals — there is no direct "webhook backlog depth" metric anywhere in this framework, a disclosed observability gap — see [known-limitations.md](../known-limitations.md), "Queue depth/latency... Missing entirely"). `NudgeIntegrationQueueAsSupportAction` (`PlatformFirmIntegrationBoundedAccessService::nudgeQueue()`) for the one available acceleration lever.

## Steps

1. Confirm a backlog actually exists — there is no direct depth metric, so this requires manual database inspection (count of unprocessed `integration_inbound_webhook_events` / pending outbox events for the affected firm) rather than a dashboard read.
2. Use `NudgeIntegrationQueueAsSupportAction` to trigger an immediate processing tick rather than waiting for the next scheduled cadence — this is the only in-app acceleration lever, and it dispatches the exact same job the scheduler already runs, never a new dispatch shape.
3. If the backlog persists after nudging (indicating the bottleneck is processing capacity/worker count, not merely tick cadence), this is an infrastructure/scaling question outside any operator-facing tool this framework provides — escalate to engineering.
4. Confirm whether the backlog is scoped to one connection (likely a provider-specific slowdown) or broad (likely a queue/worker capacity issue affecting the whole environment).

## Prohibited actions

Never manually mark webhook events as processed to "clear" a backlog without them having actually been processed by their real handler — this would silently drop real work.

## Evidence to capture

Firm id(s)/connection id(s) affected, approximate backlog depth (manually counted), whether nudging improved throughput, timestamp range.

## Escalation condition

Any backlog that does not resolve after nudging, or that recurs repeatedly, should be escalated to engineering — it likely indicates a genuine capacity or throughput problem this runbook has no further operator-facing lever for.

## Recovery verification

Backlog count (manually re-checked) returns to near-zero / steady-state; connection health state returns to `Healthy`.
