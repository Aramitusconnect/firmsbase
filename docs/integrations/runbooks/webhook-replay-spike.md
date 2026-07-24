# Runbook: Webhook Replay Spike

## Symptom

An elevated rate of webhook deliveries that appear to be duplicates/replays of previously-processed events for the same connection.

## Real source involved

`integration_webhook_receipts` (idempotent on `(routing_token_hash, body_hash)`), `integration_inbound_webhook_events` (idempotent on both `receipt_id` and `(firm_integration_id, provider_key, provider_event_id)` — connection-scoped, not provider-wide), `WebhookVerificationOutcome::Replayed` (schema-complete vocabulary case; see [webhooks.md](../webhooks.md) §4 — not currently written by any live code path per this checkpoint's implementation, since replay detection today is expressed structurally via the idempotent unique constraints rather than as a distinct verification-outcome write).

## Diagnosis

Idempotency is enforced at **two layers**: the pre-tenant receipt layer (`integration_webhook_receipts`, platform table) and the tenant-owned event layer (`integration_inbound_webhook_events`). A replay that is genuinely identical (same routing token, same body) is absorbed at the receipt layer without ever reaching the tenant event layer — this is expected, correct, safe behavior, not a defect. A "spike" in this context most likely means the *provider* is redelivering at an unusually high rate (common webhook-provider behavior after their own outage/backlog), not that this framework is failing to deduplicate.

## Required role

Platform-plane investigation: SupportAgent+ with active support-access session (per-firm) or SuperAdmin/PlatformAdmin/ImplementationSpecialist (cross-firm pattern).

## Approved interface

Application log / database review of `integration_webhook_receipts` insert rate and idempotent-hit rate — no dedicated Filament UI surface exists for replay-rate visibility today (disclosed observability gap, queryable only — see [known-limitations.md](../known-limitations.md)).

## Steps

1. Confirm the spike is being correctly absorbed — i.e., receipt rows are being deduplicated (idempotent-hit, not a new row per replay) rather than the tenant event layer seeing genuinely new rows for what should be the same event.
2. If dedup is working correctly: no operator action is needed beyond monitoring — this is the provider redelivering, and the framework's idempotency layers are doing their job. Consider whether the provider's own status page indicates a redelivery-storm incident on their end.
3. If dedup is **not** working correctly (new tenant-event rows appearing for what should be identical `(firm_integration_id, provider_key, provider_event_id)` triples): this is a genuine defect and should be escalated to engineering immediately — the uniqueness constraint itself should make this structurally impossible, so its apparent failure warrants investigation into whether the constraint is intact (see [rls-policy-mismatch.md](rls-policy-mismatch.md) for the adjacent "is my constraint/policy actually what I think it is" verification pattern).

## Prohibited actions

Never manually delete rows from `integration_webhook_receipts`/`integration_inbound_webhook_events` to "clear" a perceived backlog — these tables are the actual idempotency mechanism; deleting rows would reopen the window for genuine duplicate processing.

## Evidence to capture

Timestamp range, connection/provider, receipt-insert vs. idempotent-hit ratio, whether new tenant-event rows are appearing per replay (would indicate a real defect) or not (expected, healthy dedup).

## Escalation condition

Any evidence that the tenant-event-layer uniqueness constraint is not preventing duplicate processing is a same-day engineering escalation — this is a core correctness guarantee, not a tunable.

## Recovery verification

Replay rate returns to the provider's normal baseline; receipt-layer dedup ratio confirms no genuine duplicate tenant-event rows were created during the spike.
