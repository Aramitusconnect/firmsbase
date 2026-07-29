# Checkpoint 6 Final Report — Cross-Provider Security/Ops Review

**Mission:** FirmsVault Live Integrations
**Branch:** `feature/firmsvault-live-integrations`
**Fix commit:** `7538e3d` — "Close cross-provider security/ops gaps found in Checkpoint 6 review"
**Environment:** Sandbox-only. No real credentials, no money movement, no AWS/staging/production changes. Local construction and verification only — nothing pushed, merged, or deployed.

---

## 1. Scope

Checkpoint 6 is a cross-provider security/ops review across the three
providers actually built in this mission: **Microsoft 365** (Checkpoint 2),
**Google Workspace** (Checkpoint 3), and **Plaid** (Checkpoint 4).
**LawPay was explicitly skipped per direction and is not covered by, or
implied to be covered by, this review.**

Unlike Checkpoints 2–4 (each building one new provider), this checkpoint
builds nothing new by default — it compares the three existing providers
against each other, looking for inconsistencies, gaps, or defects a
single-provider-at-a-time build process could plausibly have missed.

## 2. Method

Four parallel, read-only review agents were dispatched, each covering a
distinct cross-cutting concern across all three providers:

- **Agent A — Credential and webhook security.** Credential storage/
  redaction consistency, webhook signature verification mechanisms,
  replay/idempotency protection, and credential rotation/expiry handling.
- **Agent B — Rate-limiting and cost-control.** Kill switches, rate
  limiting, outage/degradation handling, and cost/usage visibility for
  operators.
- **Agent C — Tenant isolation and RLS.** Every table each provider
  created, cross-checked against `RowLevelSecurityCoverageMappingService`'s
  registry, plus a spot-check of representative write paths for correct
  `TenantContextService::runWithFirmContext()` wrapping.
- **Agent D — Audit trail and admin oversight.** Connection lifecycle
  audit coverage, PlatformAdmin/Firm-Admin visibility parity, and
  webhook-failure audit trail consistency.

Each agent was instructed to distinguish genuine gaps from expected
differences (e.g., Plaid legitimately having more cost-control
infrastructure than Microsoft 365/Google Workspace, since only Plaid is
metered per call) and never to manufacture findings to justify a change.

## 3. Findings

**Agent C (tenant isolation/RLS): clean.** Every table created by all
three providers is correctly classified (FORCE RLS with the canonical
predicate, or a documented, narrow, precedented exemption), fully present
in the RLS coverage registry, and representative write paths are
consistently wrapped in `runWithFirmContext()`. No divergence in kind
between the three providers' isolation patterns.

**Agents A, B, and D surfaced four genuine, actionable defects** — see
§4 for what was fixed. Everything else each agent examined (credential
encryption/redaction, rate limiting, degradation handling, connection
lifecycle audit coverage, PlatformAdmin/Firm-Admin visibility parity) was
found consistent and correctly scoped across all three providers.

## 4. Defects found and fixed

1. **Plaid webhook event-id non-determinism breaking retry idempotency**
   (Agent A). `PlaidProvider::parseInboundEvent()` folded
   `now()->getTimestampMs()` into the synthesized `event_id`, so a
   genuine Plaid redelivery (identical payload, resent because the
   original response wasn't a timely 2xx) always produced a distinct
   `event_id`. `InboundWebhookEventService::recordVerifiedEvent()`'s own
   `UNIQUE(firm_integration_id, provider_key, provider_event_id)` dedup
   constraint could therefore never recognize a retry as a duplicate —
   every retry re-dispatched `DispatchPullSyncOnVerifiedWebhookEvent` and
   `DispatchPlaidItemLifecycleTransitionOnVerifiedWebhookEvent`. Fixed by
   hashing the full raw request body instead of a timestamp, matching
   Microsoft 365's and Google Workspace's own deterministic-fingerprint
   behavior for the identical scenario. Two new regression tests prove
   both halves: a byte-identical redelivery now produces the same
   `event_id`, and two genuinely different deliveries sharing the same
   `(webhook_type, webhook_code, item_id)` tuple still produce distinct
   ones.

2. **No admin-triggerable emergency disable for Microsoft 365 or Google
   Workspace** (Agent B). The only code that ever checked
   `provider_kill_switches` was `ProviderOperationPolicyResolver`,
   reached only through `ProviderBillableCallPipeline` — which only
   Plaid routes through (only Plaid implements
   `RequiresBillableCallPipelineContract`). `ProviderKillSwitchResource`
   additionally hardcoded `provider_key = Plaid` in both its list query
   and create action, so an operator could not even create a kill-switch
   row for the other two providers. Fixed by adding a new, coarser,
   provider-agnostic `ProviderKillSwitch::LEVEL_PROVIDER` level ("the
   entire provider is disabled"), checked as the very first step of
   `ProviderRequestExecutor::send()` — the one outbound path every
   provider adapter shares — so a platform-admin-authored kill switch
   now stops calls for any of the three providers uniformly. The admin
   UI is now provider-selectable (Microsoft 365 / Google Workspace /
   Plaid). Plaid's existing fine-grained product/endpoint-category/
   operation-level switches are untouched and still work exactly as
   before, checked separately by the billing pipeline as an additional,
   more granular gate.

3. **Stale "usage-metering not wired up" banner** (Agent B).
   `PlatformIntegrationUsagePage` always rendered a hardcoded "no
   usage-metering data is available" notice, correct when originally
   written but since gone stale: `ProviderRequestExecutor::send()` now
   calls `IntegrationUsageRecorderService::recordOnce()` for every
   provider call, so real `integration_usage_records` rows exist today.
   Fixed by adding
   `IntegrationPlatformOversightReadService::usageRecordSummaryAcrossFirms()`
   — a genuine, RLS-safe, per-firm-loop aggregate (mirroring
   `PlatformFirmUserDirectoryService::countAll()`'s own established
   idiom) — and wiring the page to render it. A new test proves the page
   shows real recorded usage once it exists, alongside the pre-existing,
   still-passing proof that a genuinely empty database renders an honest
   empty state rather than a fabricated "$0."

4. **Microsoft 365 disconnect leaves no audit trace of "never attempted"
   vs. "revoked"** (Agent D). Microsoft 365 does not implement
   `SupportsDisconnectContract` at all (Microsoft Graph delegated OAuth
   permissions cannot be revoked by the app itself — a tenant admin must
   revoke separately via the Entra admin center, already disclosed in
   `Microsoft365Provider`'s own docblock). `ProviderConnectionService::disconnect()`
   previously fired the exact same `credential_revoked`/`disconnect`
   events for a provider with no revocation support as for one that
   genuinely revoked — nothing distinguished the two. Fixed by adding a
   new `integration_oauth.provider_revocation_not_supported` audit
   event, fired when the resolved provider doesn't implement the
   contract at all — closing the operator-visibility gap without
   changing `disconnect()`'s own best-effort local-teardown behavior.
   `IntegrationAuditEventTypeTest`'s closed event-type taxonomy was
   updated (21 → 22 events) and a new test proves the event fires for a
   no-disconnect-support provider and never also fires the unrelated
   "revocation failed" event.

## 5. Testing

Every fix above has a dedicated regression test proving it (not just
that the surrounding code still compiles). All 14 changed/added files
are Pint-clean. A full ~2,400-test sweep of `tests/Feature/Integrations`
and `tests/Unit/Integrations` passed clean after both the initial
failing test (checking the now-intentionally-changed usage-page banner
text) was updated and after re-verification.

### Full-suite verification

A full ~9,918-test suite run on a fresh disposable database, on the
committed `7538e3d`, produced exactly one failure:
`TimeTrackingSessionsForceRlsActivationTest::test_stop_persists_correctly_when_called_with_no_ambient_context_established_beforehand`
(`3601` vs. expected `3600`) — the same pre-existing, wall-clock-timing
flake already disclosed during Checkpoint 4's own verification, confirmed
unrelated to any CP6 change (this test file has never been touched by
any commit this mission), and confirmed to pass reliably in isolation.
This flake was subsequently fixed as the first action of Checkpoint 7
(see that checkpoint's own report) once Checkpoint 7's much stricter
"zero failed tests across three identical runs" bar made it necessary
to eliminate rather than merely disclose.

Pint: clean. `git diff --check`: clean (no whitespace errors). Secret
scan of the full diff: clean (no credential/key patterns found).

## 6. Known limitations / non-defects

- The pre-existing `TimeTrackingSessionsForceRlsActivationTest` flake
  described above — root-caused and fixed as Checkpoint 7's first step,
  not as part of this checkpoint's own commit.
- `PlatformIntegrationOversightQueryDeterminismTest::test_sanitized_audit_history_orders_deterministically_when_occurred_at_ties`
  failed once during an intermediate (non-final) sweep in this session
  but passed in this checkpoint's own final full-suite run and in four
  repeated isolated runs. Its fixture uses a single, explicitly captured
  timestamp (not multiple independent `now()` calls, unlike the
  TimeTrackingSessions flake above), so it is not confirmed to share
  that same root cause. Not touched — no reproducing failure exists
  against the current codebase to fix. Flagged as a watch item for
  Checkpoint 7's own three-run verification gauntlet.

## 7. Confirmations

- No real credentials, API keys, or client secrets were used anywhere
  in this checkpoint.
- No real customer or firm data was used.
- No money movement occurred or was implemented — Plaid still never
  writes to `trust_ledger_entries`, `trust_transfers`, or
  `trust_balances`; this checkpoint did not touch any trust-accounting
  code path.
- Nothing was pushed, merged, or deployed. No AWS, staging, or
  production infrastructure was touched.
- Working tree is clean as of `7538e3d`; no uncommitted changes remain.
- This checkpoint covers exactly three providers — Microsoft 365,
  Google Workspace, Plaid. **LawPay was not built, not tested, and is
  not covered by any finding or fix in this report.**

## 8. Next steps

Per the standing "continue through Checkpoint 7" directive, and per the
explicit Checkpoint 7 scope/requirements provided directly by the user,
work proceeds automatically to **Checkpoint 7: final release-candidate
verification** — covering exactly the three implemented providers
(Microsoft 365, Google Workspace, Plaid), explicitly excluding LawPay
from any claim of implementation or testing.
