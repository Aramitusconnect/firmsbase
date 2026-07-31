# Checkpoint 8.2 — Billable-Call Durability Redesign (FK-Free Operation Ledger)

**Branch:** `fix/fvli-billing-durability-redesign`
**Base SHA:** `1a8d2efe78b65fc79a45285b69887eb0b9ef4aba` (Checkpoint 8's final commit)

## Why this checkpoint exists

Checkpoint 8 left one Critical defect only partially fixed (C3): the record of
"we already sent this provider request" lived inside the caller's own database
transaction. A rollback — a post-call exception, a crash, a worker killed
mid-deploy — destroyed that evidence, and the retry re-sent a request the
provider had already performed and billed.

Checkpoint 8.1 tried to fix it by relocating the FK-bearing
`provider_billable_call_reservations` table onto an independent database
connection. **That attempt was rejected.** `PullSyncJob` held `->lockForUpdate()`
on the `firm_integrations` row across the whole provider call, so a reservation
INSERT from a separate session had to take `FOR KEY SHARE` on that same row to
validate its foreign key — which `FOR UPDATE` blocks. The durable write waited
for a transaction that could not commit until the job finished: a production
deadlock, proven live via `pg_stat_activity`/`pg_locks`, not merely argued. The
full rejection record is appended to
[checkpoint8-remediation-report.md](checkpoint8-remediation-report.md#checkpoint-81-rejected--superseded-by-checkpoint-82).

Checkpoint 8.2 takes a different route: an **FK-free operation ledger** plus
**phased claim / call / apply / recover execution** at every caller.

## The design in one paragraph

`provider_operation_attempts` records at-most-once permission to call a
provider. It is written on an independent connection using only single
autocommitted statements whose `WHERE` clause *is* the compare-and-set; it holds
no lock anyone else waits on, opens no transaction, and pushes no session
setting. The row that says a request left the process is committed **before** the
request leaves. The authoritative billing rows keep their real foreign keys and
RLS on the ordinary connection — the gate is evidence used to rebuild them,
never a second ledger of money owed.

## Commits

| SHA | Scope |
|---|---|
| `2b023f1` | §A4 — the FK-free durable gate: table, enums, model, service, RLS registration |
| `9b9624b` | §A5 — pipeline phased into claim / call / apply / recover |
| `e68c97b` | §A6 — PullSyncJob: no transaction and no row lock across a provider call |
| `63fcd46` | §A7 — webhook renewals gated at-most-once and made resumable |
| `5e62113` | §A7b — OAuth webhook bootstrap staged outside the connect transaction |
| `4876b6a` | §A9/§A13/§A14 — one sweep for both ledgers; negative controls; real concurrency |
| `a9030e1` | §A10 — deterministic idempotency keys across all three providers |
| `196a670` | §A12 — trust-ledger firewall widening; CP8 report corrections |

## Load-bearing decisions, stated plainly

**The gate table has no foreign keys.** That is the point, and it costs
referential integrity. Compensating controls: firm ownership, entitlement and
actor authorization are all checked against real, FK-backed, RLS-protected rows
*before* a claim is written; a logical key resolving to another firm is refused
outright; and a dangling scalar can only ever cause a claim to be refused or
reconciled, never to authorize a call.

**It is registered Global/EXEMPT rather than FORCE-RLS.** An
`app.current_firm_id`-keyed policy would require tenant context on the
independent session for every read — including the pre-claim probe that runs
before any firm context necessarily exists — reintroducing exactly the
cross-session coupling the table exists to remove. Tenant attribution is the
scalar `firm_id`, which every query filters on explicitly.

**The durable claim runs before the ambient reservation gate.** Two failing
tests forced this ordering. With the ambient gate first, a retry after a
local-apply failure and a retry after an uncertain outcome both short-circuited
on the reservation, so the caller never received the durable evidence or the
owner token it needs to resume, and an unknown outcome was served as "already
handled" instead of demanding reconciliation. The ambient gate is kept below it
— it is the only thing covering reservations with no durable counterpart, i.e.
rows created before this ships.

**Uncertain outcomes escalate to reconciliation, including for non-billable
operations.** A duplicated webhook subscription is a real side effect, not
merely a cost. This cannot stall a resource, because every logical operation key
is scoped to one attempt cycle — a sync run plus cursor version plus page, or
one renewal cycle — never to a resource forever.

**PullSyncJob's connection lock is gone, not replaced.** The cursor claim was
always the mechanism preventing double-processing. *Disclosed tradeoff:* a
credential refresh may now proceed during a long sync, so a rotation between
pages can fail the next page — an ordinary sanitized failure leaving the cursor
at its last committed position. Strictly better than blocking token refresh for
up to five minutes per sync. Because the claim now commits rather than rolling
back with a crashed worker, it needed a lease (default 6× the job's own timeout)
so a killed worker cannot stall a cursor forever.

**OAuth webhook bootstrap: claim before call.** The Gmail mailbox route is
claimed in one short autocommitted statement *before* the `watch()` request, so a
cross-firm conflict costs zero provider calls. Previously the route was written
after the call and a conflict was resolved by rolling back — compensating for a
call that had already happened, while the subscription lived on at Google and a
completed authorization was discarded.

**A connection can now be Active while push delivery is not yet live.** Tracked
in a separate `webhook_bootstrap_state`, not folded into `ConnectionStatus`, and
surfaced in the firm UI with copy naming both what is degraded and what still
works. Folding it in would either mark a working connection non-Active —
silently disabling every `status === Active` guard, PullSyncJob's included — or
hide the degradation entirely.

## Defects found and fixed during this work

Both were introduced by this checkpoint; both are regression-tested.

1. **Unbounded retry recursion.** The webhook-bootstrap retry dispatched its own
   successor unconditionally, so a failing retry queued another — and under a
   synchronous queue driver that dispatch runs inline, recursing until the
   process was killed. Diagnosed from `pg_stat_activity`: the backend was
   *active* with `pg_blocking_pids = {}`, a hot loop rather than a deadlock.
   Repetition now belongs solely to the job's own `$tries`/`backoff()`.
2. **Reclaim guard contradiction.** A blanket `send_count = 0` guard silently
   blocked the two provably-safe re-send paths (a definite pre-billing
   rejection, and an operator-authorized retry). The guard is now
   `send_count = 0 OR state IN (retry_allowed, provider_rejected)`, with
   `total_send_count` monotonic so no history is lost.

## Tests changed rather than added, and why

Three pre-existing tests asserted contracts this checkpoint deliberately
replaces. None was weakened; each asserts the new contract, and the security
properties they cover are unchanged or stronger.

- `RenewGraphSubscriptionJobIdempotencyKeyTest` — a characterisation test
  recorded the residual C3 gap as accepted behaviour, noting that closing it
  required infrastructure "well beyond this remediation's scope." It now proves
  the evidence survives and the gate refuses to call again.
- `GmailMailboxRoutingLifecycleTest` (two tests) — both depended on the whole
  OAuth connect rolling back. Rewritten to validate the real security contract
  with committed fixtures and explicit cleanup through the owning connection:
  the cross-firm constraint fails closed, the existing owner is byte-identical
  afterwards, no route is reassigned or duplicated, no provider call is made on
  refusal, and no credential or connection state is corrupted.

Registry bookkeeping updated deliberately: exempt tables 35 → 36, Global
57 → 58, inventory total 257 → 258, `firm_integrations` column and fillable
sets, and the closed audit taxonomy 22 → 23.

## Test evidence

All runs against disposable databases, destroyed afterwards; no orphaned
processes.

| Suite | Result |
|---|---|
| `ProviderOperationAttemptServiceTest` (§A4) | 21 tests, 95 assertions |
| `ProviderBillableCallPipelineDurableGateTest` (§A5) | 11 tests, 48 assertions |
| `PullSyncJobConcurrencyBoundaryTest` (§A6) | 12 tests |
| `RenewGraphSubscriptionJobDurableGateTest` (§A7) | 8 tests, 45 assertions |
| `OAuthStagedWebhookBootstrapTest` (§A7b) | 11 tests, 38 assertions |
| `GmailMailboxRoutingLifecycleTest` (§A7b) | 9 tests, 53 assertions |
| `DurableGateNegativeControlsTest` (§A13/§A14) | 7 tests, 19 assertions |
| Provider unit suite (§A10) | 225 tests, 731 assertions |
| Trust-ledger firewall (§A12) | 17 tests, 1061 assertions |
| `tests/Feature/Integrations/` | **1885 tests, 8247 assertions, 0 failures** |

The two proofs that matter most:

- `test_the_durable_write_completes_while_the_caller_holds_for_update_on_the_connection_row`
  — the durable claim and send-intent write both complete while the ambient
  session holds `FOR UPDATE` on the real `firm_integrations` row, under a
  bounded `lock_timeout` so a regression fails in seconds rather than hanging.
- `DurableGateNegativeControlsTest` — the control group. NC-A reproduces the
  original defect's mechanism and then shows the durable path surviving the
  identical rollback; NC-B reproduces the rejected Checkpoint 8.1 failure and
  then shows the FK-free gate writing freely under the same lock. Every lock
  assertion is bounded by `lock_timeout`/`NOWAIT`, because a negative control
  that could hang the suite would be worse than none — that is exactly how
  Checkpoint 8.1's own suite stalled.

Migrations: both verified `migrate` → `rollback` → `migrate`. The gate table's
reapplied schema was checked against the catalog directly — 28 columns, 7
indexes, **0 foreign keys**, `relrowsecurity = false`.

Static checks: `git diff --check` clean; no `.env`, credential, generated
artifact or large file in the change set. The only Pint failure in the changed
set is the pre-existing one in `FinancialIntegrationAccessPolicyService.php`,
deliberately left alone to preserve that file's docblock-only guarantee (proven
at token level: with comments stripped, its code stream is byte-identical to
HEAD).

## Scope discipline

No push, no merge, no pull request. No image built. No AWS, ECR, ECS, staging or
production resource was touched. No real provider credential and no real client,
bank, payment, trust or legal-matter data was used anywhere — every provider in
every test is a counting stub or the in-repo `TestProvider`. The rejected
Checkpoint 8.1 worktree (`/home/ubuntu/firmsbase-integration-core`) and its
recovery snapshot were read only and never modified.

## Full-suite runs

Three strictly sequential runs on a clean, committed worktree — results appended
below once measured.

## Disclosed process errors

Twice I ran `vendor/bin/pint` across whole directories instead of the changed
files, reformatting ~860 unrelated files. Both times everything outside the
intended set was reverted and re-verified. Separately, a first attempt at
purging the gate table between tests used a global `TestCase::setUp()` hook; it
poisoned the shared audit session for unrelated tests and was replaced with an
opt-in trait.

---

<!-- APPENDED 2026-07-31 (Checkpoint 8.2, "webhook-renewal cycle identity +
     durable-metadata safety sweep + report correction" mission). Nothing
     above this marker was rewritten, reordered, or deleted; every number
     below was independently re-measured against this branch's HEAD at
     3e53216e6c9385d1a305f1b7cedf940df4ef6c16 by running the actual test
     files listed, not by re-reading a prior report's claim. -->

## Corrective addendum — report-accuracy review

This section exists because a later mission's explicit instruction was to
verify every prior test/assertion-count claim on this branch against measured
command output, not to trust it. Two genuine discrepancies were found in the
table above; everything else checked was accurate.

### Confirmed inaccuracies, now corrected

| Claim in the table above | Measured (re-run against HEAD `3e53216`) |
|---|---|
| Trust-ledger firewall (§A12): **17 tests, 1061 assertions** | **12 tests, 820 assertions** — `php artisan test tests/Feature/FinancialEvidence/FinancialEvidenceTrustLedgerFirewallTest.php` |
| `GmailMailboxRoutingLifecycleTest` (§A7b): **9 tests, 53 assertions** | **10 tests, 58 assertions** — `php artisan test tests/Feature/Integrations/GmailMailboxRoutingLifecycleTest.php` |

Neither discrepancy reflects a weakened or missing test — both actual counts
are the file's real, currently-passing content; the table above simply
recorded a stale or mistaken number at write time. No test was deleted or
disabled to produce either correction.

### Confirmed accurate (independently re-measured, not merely re-read)

| Suite | Table claim | Measured |
|---|---|---|
| `ProviderOperationAttemptServiceTest` (§A4) | 21 tests, 95 assertions | 21 tests, 95 assertions ✓ |
| `ProviderBillableCallPipelineDurableGateTest` (§A5) | 11 tests, 48 assertions | 11 tests, 48 assertions ✓ |
| `PullSyncJobConcurrencyBoundaryTest` (§A6) | 12 tests | 12 tests, 30 assertions ✓ |
| `RenewGraphSubscriptionJobDurableGateTest` (§A7) | 8 tests, 45 assertions | 8 tests, 45 assertions ✓ |
| `OAuthStagedWebhookBootstrapTest` (§A7b) | 11 tests, 38 assertions | 11 tests, 38 assertions ✓ |
| `DurableGateNegativeControlsTest` (§A13/§A14) | 7 tests, 19 assertions | 7 tests, 19 assertions ✓ |
| Provider unit suite (§A10) | 225 tests, 731 assertions | 225 tests, 731 assertions ✓ |

### A correction to a claim made in this repository's OWN external session
### handoff, not in this file

The external handoff at `/home/ubuntu/firmsbase-recovery/checkpoint-8-2-current-handoff.md`,
written by the session that added the webhook-bootstrap retry and
reconciliation workflow (commits `50cf2a9`/`4eb3400`/`3e53216`), stated
`OAuthStagedWebhookBootstrapTest.php` has **67** tests. It has **11** tests
(38 assertions, confirmed in the table above) — the same file this report's
own §A7b table already correctly recorded. That handoff has been corrected in
place (see this branch's external handoff file, updated in the same session
as this addendum) rather than left standing. Likely cause: 67 was probably an
assertion figure from a different, broader combined run that was mislabeled
as this file's own test count — a caution against repeating a number without
re-running its specific source, exactly the discipline this addendum itself
was instructed to apply.

### `tests/Feature/Integrations/` combined count — superseded, not wrong

The table above's **1885 tests, 8247 assertions** was accurate for this
branch's state on 2026-07-30, when this report was written (base commit
`5e62113`/`63fcd46`-era). Two later, legitimate sessions added tests on top
of it (the PushSyncJob claim/call/apply/recover redesign, and this mission's
own additions below) — as of `3e53216`, before this mission's own new test
file, the same directory measured **1922 tests, 8433 assertions**, still 0
failures. This is forward growth, not a defect in the original figure.

### PushSyncJob — omission, not inaccuracy

This report's own commit table (`## Commits` above) does not mention
PushSyncJob at all, because **PushSyncJob's own claim/call/apply/recover
redesign had not happened yet** when this file was committed (`0a5486e`) —
it landed three commits later, in commit `3bad196` ("Refactor PushSyncJob
into claim/call/apply phases with a durable at-most-once gate"), in a
separate session. Nothing in this report claims PushSyncJob was already
fixed; it is simply silent on a defect class (PushSyncJob shared the exact
same wall-clock-idempotency-key and ambient-transaction defects §A6/§A10
fixed for PullSyncJob/RenewGraphSubscriptionJob, just not yet applied to
PushSyncJob) that a later session closed. Recorded here so a reader of this
report alone knows to look at `3bad196`'s own commit message, not to assume
PushSyncJob was in scope of the work above it.

### Everything else in the mission's report-accuracy checklist: verified accurate as recorded

Independently re-checked against this file, `checkpoint8-remediation-report.md`,
and the actual code/tests currently on this branch — no further inaccuracy
found in any of the following (each already correctly stated where it is
recorded, and not repeated here in full to avoid duplicating an already-
accurate record):

- The original `PullSyncJob` wall-clock idempotency-key defect and its
  correction (§A6/§A10 above; `checkpoint8-remediation-report.md`'s own C3
  section).
- The rejected Checkpoint 8.1 FK-backed audit-ledger design and the specific
  `FOR UPDATE`/`FOR KEY SHARE` deadlock mechanism that rejected it
  (`checkpoint8-remediation-report.md`'s own "Checkpoint 8.1 rejected"
  section — independently re-read, and its mechanism claim matches this
  report's own "Load-bearing decisions" section above; not re-litigated).
- The plaintext Plaid cursor finding and its correction (commit `84230d7`;
  proven by `DurableOperationMetadataRedactionTest`, extended further by this
  addendum's own metadata sweep below).
- The Gmail local-domain exception-boundary correction (commit `f23fa79`).
- The Plaid JWT synthetic-JWK root cause and its deterministic regression
  test (commit `cdf74a48b9edc4b260ecfd9dadc6dbc1b4efdd04`) — the JWK
  fixed-width coordinate defect, not a `PlaidProvider.php` production defect.
- The bootstrap queue-retry defect (dead `$tries`/`backoff()` under the old
  always-caught-every-exception design) and its correction, the durable
  non-Plaid subscribe gate, the Platform Admin reconciliation production
  workflow, and its authorization/audit/CAS behavior — all recorded in this
  session's own external handoff update, cross-checked here against the
  actual commits (`50cf2a9`, `4eb3400`, `3e53216`) and re-run tests, and
  found accurate except for the single "67 tests" slip corrected above.

### This mission's own contribution: renewal-cycle identity + metadata sweep

**Old renewal-key limitation.** By the time this mission started (HEAD
`3e53216`), the wall-clock renewal/subscribe-cycle defect described in
`checkpoint8-remediation-report.md`'s C3 section was **already fixed** — not
by this mission, but by the two prior CP8.2 sessions (`63fcd46` for
`RenewGraphSubscriptionJob`'s `renewalCycleToken`/`GoogleWorkspaceProvider`'s
`watchCycleToken()`, `50cf2a9` for `ProviderConnectionService`'s
`$subscribeCycle`). Both derive their identity purely from persisted state
(subscription row id, provider-side subscription id, expiry) with zero
wall-clock or random component, and both were already covered by
`RenewGraphSubscriptionJobIdempotencyKeyTest`/`RenewGraphSubscriptionJobDurableGateTest`/
`GmailMailboxRoutingLifecycleTest`. This mission's own mapping (Phase 2)
independently re-verified this design end-to-end — including OAuth
reconnect/revoke lifecycle (a full disconnect always mints a new
`firm_integrations` row; subscription rows are never deleted or superseded
on disconnect; a new connection's row id is folded into every operation key,
so it cannot collide with any prior connection's finalized cycle) — and
found it structurally sound, not defective. No production code change was
required for renewal-cycle identity itself.

**What this mission added:** the test coverage that mapping exposed as
missing (`tests/Feature/Integrations/RenewalCycleIdentityMatrixTest.php`, 6
tests, 47 assertions): same-cycle stability across a day rollover (not just
the minute rollover the prior session tested), a new connection's durable
row never colliding with an old, fully-disconnected connection's finalized
cycle, a second worker racing a live cycle being refused rather than
double-invoking the provider, cross-firm operation-key isolation at the job
level, a `reconciliation_required` cycle resisting bypass by a fresh-looking
attempt even after a 10-day wall-clock jump, and a malicious/defensive
provider response's `access_token` field being stripped before it ever
reaches `redacted_result_metadata`.

**One deliberately out-of-scope finding, flagged rather than fixed:**
`GoogleWorkspaceProvider::callCalendarWatch()`/`callDriveWatch()`'s own
`usageIdempotencyKey` for the actual `watch()` HTTP call is keyed on a
freshly-minted `Str::uuid()` `$channelId` per attempt — not derived from the
same deterministic `watchCycleToken()` every other call in this class uses.
This is already disclosed, by name, in that file's own inline comment
("Left unchanged deliberately... see this change's report for the
recommended follow-up") from a prior pass. It sits one layer below this
mission's own scope (it is the provider's inner HTTP-usage idempotency key,
not the outer `ProviderOperationAttemptService` logical-operation-key gate
this mission's Phase 2/3 is about — the outer gate already ensures the
`providerCall` closure containing it runs at most once per logical cycle
regardless), and Calendar/Drive watches are not named in this mission's own
Phase 2 mapping list (only Gmail watch and Microsoft Graph subscriptions
are). Left as a flagged follow-up, not fixed in this pass, to avoid scope
creep into a resource type this mission was not asked to redesign.

### Durable-metadata repository-wide sweep — results

`DurableOperationMetadataRedactionTest.php` was widened (not replaced) with
a second, broader source-level firewall
(`test_no_call_site_writes_a_raw_secret_shaped_variable_into_any_durable_gate_field`)
scanning every `app/` file for ~30 categories of sensitive variable
(OAuth/refresh tokens, Plaid Link/access/public tokens, mailbox addresses,
raw HTTP bodies/headers, webhook signing keys, Drive/Gmail continuation
tokens) co-occurring on the same source line as any of 7 durable-gate field
markers (`local_processing_state`, `redacted_result_metadata`,
`reconciliation_reason`, `provider_request_reference`,
`usageIdempotencyKey`, and their camelCase call-site forms), with two
negative controls: one proving the scan actually flags a planted violation,
one proving a longer variable name that merely starts with a forbidden one
(`$linkTokenMode` vs. `$linkToken`) is never mistaken for it (an early
version of this exact sweep tripped on that false positive during
development, plus a second false positive on a function signature
co-locating two unrelated parameter names on one line — both are why the
scan skips signature lines and uses word-boundary matching, not naive
substring `str_contains()`).

**Result: zero live violations found** across Plaid, Microsoft 365, Google
Workspace, PullSyncJob, PushSyncJob, RenewGraphSubscriptionJob, the OAuth
callback, webhook bootstrap, and every reconciliation action. Every
`redactedResultMetadata`/recovery-evidence closure in the codebase already
redacts to a small, explicit, non-secret field allowlist (confirmed
directly in `RenewGraphSubscriptionJob::recoveryEvidenceFor()`,
`ProviderConnectionService::subscriptionRecoveryEvidenceFor()`) rather than
passing a provider response through unfiltered.

### Optional broad-suite failures from the prior session, and their fix

The prior session's optional full-suite run (10,204 tests) reported 2
failures, both in `FirmIntegrationSuperAdminBoundaryStructuralTest` — the
new reconciliation Filament files were missing from that test's own
explicit cascade allowlist. Fixed in commit `3e53216`, re-verified
(8/8 passing on that file alone, 36/36 combined with the two new/changed
test files). Not re-litigated further here; see the external handoff for
the full account.

### Known unrelated schema-wipe test-infrastructure issue

Still open, still untouched by this mission (out of scope, per this
mission's own explicit instruction not to expand into a general test-
infrastructure rewrite): the ad-hoc-multi-directory-test-run schema-wipe
cascade, tracked as a separately spawned follow-up task in a prior session.
Nothing in this mission's own test runs (each invoked with an explicit,
narrow file/directory list, or with no path argument for the one optional
broad run) re-triggered it.

### Distinction between implementation-agent evidence and independent review

Every number in this report — including every correction in this addendum —
was measured by the same implementing agent that wrote the corresponding
code, running `php artisan test` against disposable local databases it
created and destroyed itself. That is real, reproducible, command-verified
evidence, and it is what this addendum re-verified rather than trusted. It
is **not** an independent review: no reviewer without access to this
session's own reasoning has examined this branch's diff, and this report's
own final verdicts (`REMEDIATION_COMPLETE_READY_FOR_REVIEW` and, for this
addendum, the mission-specific status recorded in the external handoff) have
never claimed otherwise. Three sequential clean full-suite runs and a fresh
independent review are both explicitly still pending — recorded as the next
mission in the external handoff, not performed here.
