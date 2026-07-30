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
