# Inbound Webhooks

## 1. Route status: wired and reachable since Checkpoint 7

`POST /webhooks/integrations/{provider}` is defined in `routes/webhooks.php` and has been **wired and reachable by real traffic since commit `01605b6`** ("Checkpoint 7: inbound webhook security foundation"), the same commit that created the route file. `bootstrap/app.php`'s `withRouting(..., then: function (): void { ... })` closure requires `routes/webhooks.php` alongside the `/readyz` registration:

```php
then: function (): void {
    Route::get('/readyz', ReadinessController::class);

    // Registers the Checkpoint 7 inbound webhook route file outside
    // any implicit middleware group, consistent with the /readyz
    // registration above — see routes/webhooks.php for why.
    require __DIR__.'/../routes/webhooks.php';
},
```

An earlier version of `routes/webhooks.php`'s own docblock claimed the route was "NOT YET REACHABLE" pending a `bootstrap/app.php` change — that claim was stale and self-contradicted at HEAD: `git log -S` on the `require` line proves it was added in the same commit that introduced the file. Do not describe this route as unreachable in any context; it has been live since Checkpoint 7.

## 2. Why this route is unauthenticated

`{provider}` is a route segment matched against the closed, non-dynamic `integration_providers.code` catalog (`WebhookConnectionResolverService`). The actual routing/identity token travels on a request header, never in the URL — required for the timing-oracle mitigation and to avoid URL-based leakage into access/proxy logs. This route is deliberately registered outside `routes/web.php`'s implicit `web` middleware group (no session, no CSRF, no cookie encryption) — only the two middleware explicitly attached in `routes/webhooks.php` (`throttle:60,1` and `LimitInboundWebhookPayloadSize`) ever run.

## 3. Request flow

`App\Integrations\Http\Controllers\InboundWebhookController` is the only HTTP entry point, deliberately thin — every real decision is delegated:

1. `WebhookConnectionResolverService` — resolves the routing token (header, not URL) to a connection.
2. `InboundWebhookSignatureVerifier` — exact raw-body HMAC-SHA256 verification, bounded 2-candidate secret rotation, constant-time comparison with padding (`performConstantWorkPadding()`) to reduce timing-oracle risk.
3. `InboundWebhookReceiptService` — writes an `integration_webhook_receipts` row (platform table, no `firm_id` column ever, idempotent on `(routing_token_hash, body_hash)`) **only after** routing resolution and signature verification have both already succeeded.
4. `InboundWebhookEventService` — writes the tenant-owned `integration_inbound_webhook_events` row (FORCE RLS, idempotent on both `receipt_id` and `(firm_integration_id, provider_key, provider_event_id)` — connection-scoped, not provider-wide).
5. `InboundWebhookAuditLogger` — platform-only audit logging, never exposed to the caller.

**Collapse-to-false response shape**: every pre-verification failure path (unresolvable routing token, signature mismatch, malformed payload, etc.) is byte-identical on the wire (`401 {"status":"rejected"}`) — the controller never branches its HTTP response on which specific failure occurred; only internal audit logging varies. A `429` from the throttle middleware is a disclosed, acceptable exception to this uniformity (orthogonal to content correctness).

## 4. Verification outcomes

`WebhookVerificationOutcome` (`app/Integrations/Enums/WebhookVerificationOutcome.php`) is a closed vocabulary of 8 cases, but only 2 are ever actually written by this checkpoint's code path: `Verified` and `Malformed`. The remaining cases (`Pending`, `SignatureInvalid`, `RoutingUnresolved`, `Replayed`, `Expired`, `Error`) exist for schema completeness (matching the column's CHECK constraint and a possible future broader pre-verification audit trail) — no code path in this framework writes them today.

## 5. Retention

Two independent retention mechanisms apply to webhook data:

- **`integration_webhook_receipts`** (platform table): `receipt_verified_retention_days` (`INTEGRATIONS_WEBHOOK_RECEIPT_VERIFIED_RETENTION_DAYS`, default 30) — swept by `App\Console\Commands\SweepIntegrationRetentionCommand` directly (not the per-firm job, since this table has no `firm_id`).
- **`integration_inbound_webhook_events`** (tenant table): a two-stage redact-then-delete sweep in `App\Jobs\RetentionSweepJob::sweepProcessedWebhookEvents()`:
  - **Stage 1 — redact** at `retention_deadline` (computed at insert time from `event_redact_after_days`, default **400** days) — provider-originated content is redacted from the row.
  - **Stage 2 — delete** at **2,555 days** from `received_at` (`integrations.webhook.event_delete_after_days`, `InboundWebhookEventService::DEFAULT_DELETE_AFTER_DAYS`).

### The 2,555-day window is a disclosed, carried-forward placeholder — not a legally validated figure

**This is important and must not be misstated.** The 2,555-day (≈7-year) deletion horizon traces to an **unconfirmed placeholder first introduced in Checkpoint 7 §16** and first acted upon as an actual deletion horizon in Checkpoint 8. There is:

- No seeded `RetentionPolicy` row anchoring this specific number for webhook/integration data.
- No `RetentionRecordType` case dedicated to it.
- No documented human/legal decision establishing 2,555 days as a correct or required retention period for this data.

The number appears as a bare literal default in three places (`app/Jobs/RetentionSweepJob.php:311`, `app/Integrations/Services/InboundWebhookEventService.php:72`, and `app/Services/RetentionGovernanceRegistryService.php:147`) and is documented in Checkpoint 8's own report as "a disclosed, carried-forward placeholder, not a confirmed compliance figure."

**This window must never be described as legally validated or compliance-satisfying in any context.** Treat it as an open compliance question requiring a human/legal decision before it can be relied upon as a real retention commitment. See [known-limitations.md](known-limitations.md).

## 6. Operator-facing runbooks

- [runbooks/webhook-invalid-signature-spike.md](runbooks/webhook-invalid-signature-spike.md)
- [runbooks/webhook-replay-spike.md](runbooks/webhook-replay-spike.md)
- [runbooks/webhook-backlog.md](runbooks/webhook-backlog.md)

No operator-facing webhook-drain tool exists today — see [runbooks/webhook-backlog.md](runbooks/webhook-backlog.md).
