# Configuration

All integration configuration flows through a single authoritative file, `config/integrations.php` — every key below is read from there, and every env var below is consumed exclusively by that file (verified by grepping `env(` calls across `config/integrations.php` at HEAD; no other file reads an `INTEGRATIONS_*` var directly except the TestProvider environment-name guard's own independent re-check — see [testprovider.md](testprovider.md)).

## 1. All 19 `INTEGRATIONS_*` env vars

| # | Env var | Config key | Default | Notes |
|---|---|---|---|---|
| 1 | `INTEGRATIONS_TEST_PROVIDER_ENABLED` | `integrations.providers[test]` (registration gate) | `false` | Also independently re-checked by `TestProvider::isEnabledByEnvironment()`. As of Checkpoint 14, both gates additionally require the app not be running in the `production` environment — see [testprovider.md](testprovider.md). In `.env.example`. |
| 2 | `INTEGRATIONS_OUTBOX_MAX_BACKOFF_SECONDS` | `integrations.outbox.max_backoff_seconds` | `3600` | Shared ceiling for both `IntegrationOutboxEventService::fail()`'s retry delay and `RetryAfterParser`'s clamp on a provider `Retry-After` signal |
| 3 | `INTEGRATIONS_OUTBOX_COMPLETED_RETENTION_DAYS` | `integrations.outbox.completed_retention_days` | `30` | |
| 4 | `INTEGRATIONS_OUTBOX_DEAD_LETTERED_RETENTION_DAYS` | `integrations.outbox.dead_lettered_retention_days` | `90` | |
| 5 | `INTEGRATIONS_OUTBOX_CANCELLED_RETENTION_DAYS` | `integrations.outbox.cancelled_retention_days` | `30` | |
| 6 | `INTEGRATIONS_OAUTH_STATES_CONSUMED_RETENTION_HOURS` | `integrations.oauth_states.consumed_retention_hours` | `72` | The conservative/longer end of a frozen 24–72h range — biases toward retaining forensic evidence longer |
| 7 | `INTEGRATIONS_OAUTH_STATES_UNCONSUMED_EXPIRED_RETENTION_HOURS` | `integrations.oauth_states.unconsumed_expired_retention_hours` | **none** | Deliberately no default. The sweep must explicitly no-op (log `oauth_state_unconsumed_cleanup_not_configured`) until a human sets it — never guess a value |
| 8 | `INTEGRATIONS_SYNC_RUNS_RETENTION_DAYS` | `integrations.sync_runs.retention_days` | `180` | |
| 9 | `INTEGRATIONS_SYNC_ITEMS_RETENTION_DAYS` | `integrations.sync_items.retention_days` | `60` | |
| 10 | `INTEGRATIONS_CONFLICTS_RETENTION_DAYS` | `integrations.conflicts.retention_days` | `365` | Resolved conflicts only — an unresolved conflict is never swept by age alone |
| 11 | `INTEGRATIONS_WEBHOOK_RECEIPT_VERIFIED_RETENTION_DAYS` | `integrations.webhook.receipt_verified_retention_days` | `30` | Verified-receipt evidence retention |
| 12 | `INTEGRATIONS_HEALTH_BACKOFF_BASE_SECONDS` | `integrations.health.backoff_base_seconds` | `60` | |
| 13 | `INTEGRATIONS_HEALTH_BACKOFF_MAX_SECONDS` | `integrations.health.backoff_max_seconds` | `3600` | |
| 14 | `INTEGRATIONS_HEALTH_DEGRADED_AFTER_FAILURES` | `integrations.health.degraded_after_failures` | `1` | |
| 15 | `INTEGRATIONS_HEALTH_UNAVAILABLE_AFTER_FAILURES` | `integrations.health.unavailable_after_failures` | `3` | |
| 16 | `INTEGRATIONS_HEALTH_DIAGNOSTIC_SUMMARY_MAX_LENGTH` | `integrations.health.diagnostic_summary_max_length` | `500` | |
| 17 | `INTEGRATIONS_RETENTION_PLATFORM_MAX_BATCHES_PER_RUN` | `integrations.retention.platform_max_batches_per_run` | `50` | Bounds the blast radius of a single sweep invocation on `integration_webhook_receipts` — the one retention target with no RLS backstop at all |
| 18 | `INTEGRATIONS_RETENTION_SWEEP_FIRM_DATA_ENABLED` | `integrations.retention.sweep_firm_data_enabled` | `false` | Kill-switch for the 3 firm-data retention sweeps (sync items, sync runs, resolved conflicts) — see [known-limitations.md](known-limitations.md) KR-01 and [runbooks/retention-kill-switch-runbook.md](runbooks/retention-kill-switch-runbook.md). Added to `.env.example` as part of Checkpoint 14's narrow config correction (it was Checkpoint 13's own new flag, missing from `.env.example` until Checkpoint 14). |
| 19 | `INTEGRATIONS_USAGE_RECORDS_RETENTION_DAYS` | `integrations.usage_records.retention_days` | **none** | Deliberately no default — matches item 7's fail-safe precedent. `IntegrationUsageRecorderService::recordOnce()` leaves `retention_deadline` null when this resolves to null rather than guessing a number. Note: the writer itself is unwired in production — see [known-limitations.md](known-limitations.md) |

## 2. Deliberately-no-default keys

Two keys (items 7 and 19 above) ship with **no default value** — not zero, not a large placeholder number, genuinely absent. This is a deliberate fail-safe design choice, not an oversight: the corresponding sweep code paths check for `null` and no-op with an explicit log event rather than inventing a retention period. Any future change to these keys must preserve that framing.

## 3. `.env.example` status

As of Checkpoint 14, `.env.example` documents 2 of the 19 vars explicitly (`INTEGRATIONS_TEST_PROVIDER_ENABLED` and, newly added this checkpoint, `INTEGRATIONS_RETENTION_SWEEP_FIRM_DATA_ENABLED`). The remaining 17 vars are undocumented in `.env.example` and fall back to the defaults listed in the table above (or, for the two no-default keys, remain unset/null) unless an operator explicitly sets them. This is a known, disclosed documentation gap — this document is the authoritative enumeration of all 19 until `.env.example` is expanded further, which is outside this checkpoint's narrow config-change allowlist.

## 4. Provider registry

`config/integrations.php`'s `providers` array is the single source of truth for which provider classes are registered — structurally mirrors `config/filesystems.php`'s disk-driver-listing shape. A `null` value for a provider key means that provider is **absent from the registry entirely** (not merely disabled) — `ProviderRegistry::get()` throws `UnknownProviderException` for it. See [testprovider.md](testprovider.md) for the only entry that exists today.
