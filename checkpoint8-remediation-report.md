# Checkpoint 8 — Release-Candidate Security and Correctness Remediation

**Remediation branch:** `fix/fvli-release-candidate-remediation`
**Starting SHA:** `a305b6acc86c623473a130c6db850d6f8d672f2a` (the `feature/firmsvault-live-integrations` release-candidate SHA the independent post-push review evaluated and returned `NOT_READY_FOR_STAGING` for)
**Final SHA:** see `git log -1` after this report's own commit (this file is the last commit on this branch)

This checkpoint authorized local corrective development only — no changes to the release-candidate branch, no merges, no PRs, no deployment, no AWS/staging/production changes, no real provider credentials, no real firm/client/bank/payment data. All of that held throughout; see confirmations at the end of this report.

---

## Commit list

| # | Commit | Defect class |
|---|---|---|
| 1 | `b51b4ac` Fix matter-bound Plaid connection and consent authorization | C1, H1, H5 |
| 2 | `1762f14` Enforce financial-tier access and close cross-matter record IDORs across Financial Evidence surfaces | C2, H2 |
| 3 | `396a0a8` Harden snapshot account authorization | H3 |
| 4 | `2ee5e18` Fix Plaid actor identity and PlaidItemResource financial-tier policy | H4, H6 |
| 5 | `6065c3d` Make billable retries deterministic and reservation-aware | C3 |
| 6 | `c323497` Strengthen trust-ledger firewall coverage | H7 |
| 7 | `5957550` Apply migration/configuration hardening and close a missed approve-tier read gate | M1, M3, M4, + 1 pattern-sweep finding |
| 8 | (this report) Add Checkpoint 8 final report | — |

C2 and H2 are combined into one commit rather than the checkpoint's suggested separate commits 2/3: the same agent pass touched the same 10 files to add both the financial-tier check and the matter-scoped record lookups together, and splitting that after the fact into two commits would have required re-deriving hunk-level boundaries with real risk of breaking the code, for no real audit-trail benefit — the commit message discloses both defect classes explicitly.

---

## Critical findings and exact corrections

### C1 — Wrong Plaid connection binding (`PlaidDateRangeConfirmationPage.php`)
**Before:** resolved the FirmIntegration to authorize via `where('firm_id',...)->where('status','active')->latest('id')->first()` — the firm's most recently created active Plaid connection, not the one bound to the current matter's own `FinancialEvidenceMatterRequest`.
**After:** resolves through a new shared `ClientPortalPlaidConnectionResolverService`, which finds the matter's own newest non-cancelled request, loads its `firm_integration_id`-bound connection by id+firm_id, verifies provider identity and status, and fails closed (audited, no sensitive data) on any mismatch. Never uses `latest()`/`first()`/a client-supplied id as a binding source.

### C2 — Missing financial-tier authorization (6 Financial Evidence panels)
**Before:** `FinancialEvidenceOverviewPanel`, `SummaryPanel`, `ReviewQueuesPanel`, and the three `ReviewQueues/*` sub-panels were gated only by matter access (`MatterAccessPolicyService::canAccessMatter()`), which grants Paralegal/LegalAssistant/Receptionist/BillingStaff access to any assigned matter — none called `FinancialIntegrationAccessPolicyService`, whose docblock states those roles must never receive financial-tier permission.
**After:** a second independent gate (`GatesFinancialEvidenceMatterAccess::gateFinancialTierAccess()`) runs at mount, every data-loading closure, and every mutation, resolving the acting FirmUser scoped to the matter's own firm (not the first active membership across any firm — a related bug found and fixed in the same pass). Three further instances of the identical gap were found and fixed in sibling files during this same work (`TransactionSearchPanel`'s table listing, `ReportsPanel`'s missing mount gate, `NotesPanel`'s null-actor silent skip).

### C3 — Billable-call double-charge risk on retry
**Before:** `RenewGraphSubscriptionJob`/`PullSyncJob` built their usage-idempotency key from `now()->format('YmdHi')`, and `ProviderBillableCallPipeline` never checked whether a reservation was freshly created before firing the real outbound call — any transient failure on a retryable job (`tries=5`, backoff `[30,60,120,240]s`) could re-fire a real, billed Plaid API call for one logical operation.
**After:** idempotency keys are now deterministic (tied to subscription id/cycle, or sync run/cursor version — never wall-clock time), and the pipeline gates on reservation state before ever calling `providerCall()`: fresh reservations proceed; a stale `RESERVED` row with no recorded call-start is safely reclaimed; a row with a call already in flight, or any `FINALIZED_BILLABLE`/`FINALIZED_UNCERTAIN` row, is served from the existing reservation with zero new calls; a `FINALIZED_NON_BILLABLE` row is reclaimable (provably no billable work happened, so re-firing cannot double-charge, and refusing it would permanently swallow exactly the transient failures `tries=5` exists to recover from). A new nullable `provider_call_started_at` column distinguishes "crashed before the call" from "crashed with a call in flight." Exception handling widened from `SanitizedProviderHttpException`-only to `Throwable`, so `finalize()` always runs.

**Disclosed residual risk:** the job's own ambient `DB::transaction()` (from `runWithFirmContext()`) means a reservation/usage-record write can still be rolled back if the job throws after a successful, billed call but before commit. Closing this requires writing the reservation ledger outside the ambient transaction — infrastructure beyond this remediation's scope. Encoded as an explicit characterization test so the risk stays visible in the suite, not just in this report. **Recommend raising as a separate, dedicated follow-up.**

---

## High findings and exact corrections

| # | File(s) | Correction |
|---|---|---|
| H1 | `PlaidConsentPage.php` | Consent resolution now goes through the same server-authoritative resolver as C1 instead of trusting the `#[Url] $firmIntegration` property; the URL value is cross-checked, never trusted. |
| H2 | `DuplicateTransfersQueuePanel`, `LargeDepositsQueuePanel`, `ReconciliationCandidatesQueuePanel`, `FinancialEvidenceTransactionSearchPanel` | Every submitted record id is now resolved through a `firm_id`+`matter_id`-constrained query (or the bank-account allowlist, where no `matter_id` column exists), never loaded by bare primary key first. |
| H3 | `FinancialEvidenceSnapshotsPanel.php` | Submitted `bank_account_ids` re-intersected against a freshly-derived, matter-scoped allowlist at submit time; any id outside it, or an empty resulting set, rejects the whole request with zero rows written. |
| H4 | `ViewPlaidItem.php` | Reconnect/disconnect now pass `$firmUser->user_id` (a real `users.id`), not `$firmUser->id` (a `firm_users.id`). |
| H5 | `PlaidConsentPage.php` | "Revoke Connection" resolves the correct actor identity and connection server-side, wraps both steps in try/catch, and never surfaces a raw provider exception. Previously threw uncaught on every attempt. |
| H6 | `PlaidItemResource.php` | Now gated by `FinancialIntegrationAccessPolicyService` (financial tier) instead of `IntegrationAccessPolicyService` (general tier), with `canViewAny()`/`canView(Model)` overrides added (needed because this resource shares its Eloquent model, and therefore Filament's default Policy resolution, with `FirmIntegrationResource`) plus a defense-in-depth `getEloquentQuery()` gate. |
| H7 | `FinancialEvidenceTrustLedgerFirewallTest.php` | Scan scope widened to 44 files (previously omitted real Plaid pages/controllers/providers); forbidden-term matching now catches the snake_case table name, every real `Trust*` model, and fully-qualified references. A negative fixture proves the widened firewall catches a planted violation. No live violation exists today — this closes a test-coverage gap, not a live defect. |

**Additional finding fixed, not in the original list** (surfaced by the repo-wide pattern sweep): `PlaidReclassificationApprovalsPage.php`'s `table()->records()` closure only checked for an authenticated FirmUser, missing the `canApprove()` re-check its own `canAccess()` already enforces — a read-only disclosure gap (mutations were always independently re-gated). Fixed to match the outer gate.

---

## Medium items — disposition

- **M1 (fixed):** `2026_09_13_130001`'s CHECK constraint now uses `NOT VALID` + separate `VALIDATE CONSTRAINT`, matching the established safe pattern. Rollback/reapply proven against a disposable database (migrate → rollback → reapply, no error, constraint validated both times).
- **M3 (fixed):** `PlaidMatterRequestsPage` now enforces `FinancialIntegrationAccessPolicyService`'s view check on both `canAccess()` and the table query, and re-validates a submitted `matter_id` belongs to the actor's own firm server-side before creating a request. The previously-uncaught `assertCanRequest()` now shows a notification instead of a raw 500.
- **M4 (fixed):** all 66 missing `INTEGRATIONS_*` keys added to `.env.example` (empty/placeholder values only), plus a boot-time log-only credential-presence warning and a corrected stale line in the deployment runbook.
- **M2 (written decision, not implemented — per this item's own instructions):** **dual-approval should be implemented for ordinary Plaid connect/disconnect.** `FinancialIntegrationAccessPolicyService`'s own docblock states, unhedged, that connect/disconnect/credential-rotation require "the same dual-approval discipline... symmetric, not weaker" as trust-account reclassification — with no textual qualifier narrowing this to reclassification only. The only working `assertDistinctApprovers()` caller today is `FinancialAccountReclassificationService`; `ViewPlaidItem`'s `reconnectAction()`/`disconnectAction()` call `ProviderConnectionService` directly, gated only by the page's single-actor `canView()` ceiling (FirmOwner/Attorney/BillingStaff) — today a lone BillingStaff member can unilaterally disconnect a client's Plaid connection. `checkpoint4-final-report.md` shows this was a disclosed scope decision at build time, but the governing docblock and `architecture.md` §4 were never revised to match what actually shipped, so the documented contract and shipped behavior disagree. **Recommend this become a dedicated fast-follow**, either implementing `assertDistinctApprovers()` on those two actions or formally narrowing the docblock/architecture doc if a compliance owner decides reclassification-only was always the intent.

---

## Pattern-sweep results

A dedicated read-only sweep searched the full mission diff for 13 additional instances of each fixed defect class, beyond the already-known set. Findings (all now fixed or explicitly dispositioned, all cross-verified against the actual committed `a305b6a` tree, not the moving working tree):

- **Fixed:** `PlaidReclassificationApprovalsPage.php`'s missing `canApprove()` re-check in `records()` (see above).
- **Dispositioned, not a defect:** 23 wall-clock-based `usageIdempotencyKey` values inside `PlaidProvider`/`Microsoft365Provider`/`GoogleWorkspaceProvider`'s own methods. Independently traced: these feed only `ProviderRequestExecutor::send()`'s outbound `Idempotency-Key` HTTP header (a real anti-double-charge mechanism on the provider's own side) and post-call usage-record dedup — never `ProviderUsageReservationService::reserve()` or the billable pipeline, which only 5 call sites reach, all now deterministic. Left as a secondary hardening opportunity, explicitly not a reservation-gate defect.
- **No further matches** for: general-tier policy used on financial pages (beyond the fixed H6), Livewire public/URL properties used as sole authorization source (beyond the fixed H1), submitted-ID-array allowlist gaps (beyond the fixed H3), FirmUser.id/User.id confusion (beyond the fixed H4 — a repo-wide actor-parameter audit covered every `ProviderConnectionService` call site), narrow exception catches around outbound calls (the sole chokepoint already widened to `Throwable` in commit 5), disconnect/revoke calls with a missing actor (`ProviderConnectionService::disconnect()`'s own guard already fails loudly on this), and further unsafe `CHECK` constraint migrations (only the one M1 instance existed).

---

## RLS / migrations / authorization

- **Migrations:** 2 new (1 nullable-column addition with a correct `down()`, 1 already-existing migration corrected to the safe `NOT VALID` pattern). Both prove clean rollback/reapply.
- **RLS:** no new tables or policies touched by this remediation; all fixes are application-layer authorization (matter/firm scoping on top of existing FORCE-RLS `firm_id` isolation), consistent with the original review's own finding that the RLS/database layer was already sound throughout.
- **Authorization:** every fix in this checkpoint adds or corrects an application-layer check; none weakens or removes an existing one. Every corrected action now enforces authorization inside the action/service itself, not only via page/nav visibility, per this checkpoint's own governing rule.

---

## Test evidence

**Per-cluster targeted results at implementation time** (each cluster ran its own scoped tests before merge; figures below are each cluster's own final clean run, all on disposable databases):
- Commit 1 area: 364 tests, 2033 assertions, 0 failed.
- Commit 2/3 area: 179 tests, 1414 assertions, 0 failed.
- Commit 4 area: 185 tests, 619 assertions, 0 failed (plus a 254-test broader spot-check, 252 passing — the 2 failures were another cluster's then-still-in-progress file, correctly flagged as out of scope and confirmed resolved once that cluster landed).
- Commit 5 area: 1808 tests (full `tests/Feature/Integrations`), 7946 assertions, 0 failed; negative controls confirmed each new test fails against the prior code.
- Commit 6 area: 15 tests, 1044 assertions, 0 failed.
- Commit 7 area: M1's rollback/reapply proof (no error); M3's 7 tests + the 185-test Ui suite + the 10-test trust-ledger firewall test, all passing.

**Post-implementation reconciliation** (after merging all clusters into one tree, before committing): a comprehensive targeted sweep across `tests/Feature/FinancialEvidence`, `tests/Feature/Integrations`, `tests/Unit/Integrations`, `tests/Feature/Security`, `tests/Feature/Governance` — 7,279 tests, 76,123 assertions, 195 failures. Every one of the 195 failures was independently verified (not assumed) to be a pre-existing, unrelated "no changes outside this section's own scope" firewall test (`git ls-files --modified --others`-based, e.g. `WorkflowStateMachineFirewallTest::test_no_new_migration_files_were_added`) that fires on *any* uncommitted repository diff, including these legitimate CP8 changes — not a regression. Confirmed by reading the actual failing assertion and its source (`git ls-files --modified --others --exclude-standard`), which resolves to empty once committed.

**Targeted areas covered:** Plaid date-range confirmation, Plaid consent, Client Portal authorization, Financial Evidence role matrix, review-queue IDOR, transaction-search IDOR, snapshot account authorization, Plaid Item authorization, reconnect/disconnect actor identity, revoke lifecycle, billable pipeline idempotency, queue retry/backoff, usage reservation lifecycle, webhook renewal, trust-ledger firewall, migration replay/rollback, RLS/tenant isolation (via `tests/Feature/Security`), Microsoft/Google/Plaid provider regression, PlatformAdmin/Firm-panel regression (via the 185+254-test Ui sweeps), Client Portal regression (via commit 1's 364-test sweep) — all covered above.

**3x full-suite sequential runs** (required because substantive Critical/High defects were corrected): results below.

Run against 3 independently-created disposable databases, strictly sequential (never overlapping), with the working tree fully clean and committed before each run started:

| Run | Tests | Passed | Assertions | Failed | Risky | Duration |
|---|---|---|---|---|---|---|
| 1 | 10,060 | 10,060 | 89,241 | 0 | 57 | 1,558.5s |
| 2 | 10,060 | 10,060 | 89,241 | 0 | 57 | 1,549.5s |
| 3 | 10,060 | 10,060 | 89,241 | 0 | 57 | 1,584.2s |

Identical test counts, identical assertion counts, zero failures, and identical risky-test count (57) across all three runs — matching the pre-CP8 baseline risky count, confirming this checkpoint introduced no new risky (no-assertion) tests. No security test was skipped in any run.

(An earlier first attempt at run 1 was discarded and re-run from scratch: this report's own draft file had been written directly into the repo working directory, uncommitted, while that run was executing, which tripped 44 unrelated "no changes outside this section's own scope" firewall tests — the same false-positive class already identified during the pre-commit targeted sweep. The draft was moved out of the repo before any of the three runs recorded above began; all three ran against a fully clean, fully committed tree throughout.)

---

## Static checks

- **Pint:** clean on every changed file after one auto-fix pass (2 new test files needed import ordering/formatting fixes; the two production commits already matched house style).
- **`git diff --check`:** clean, no conflict markers or trailing-whitespace errors, checked against the full 48-file diff.
- **Secret scan:** clean. No literal API keys/tokens/private-key material anywhere in the diff.
- **Network scan:** the only 2 new/changed `app/` files matching `Http::`/`GuzzleHttp`/`curl_` patterns (`ProviderBillableCallPipeline.php`, `ProviderCallOutcomeNormalizer.php`) are comment-only prose mentions, not real code — confirmed directly, and confirmed via the actual guard tests (`NoRealNetworkCallTest`, `NoRealNetworkCallInJobsTest`): 16/16 passing, `ProviderRequestExecutor::send()` remains the sole real HTTP call site.

---

## Known limitations

- The job-wide ambient-transaction rollback risk described under C3 is disclosed, not fixed — closing it requires infrastructure (a reservation ledger on a connection independent of the job's own transaction) beyond this remediation's narrow-fix mandate.
- M2 (dual-approval on Plaid connect/disconnect) is a written decision recommending implementation, not implemented in this checkpoint, per that item's own instructions.
- The 23 wall-clock-based `usageIdempotencyKey` values inside the three provider classes' own methods are dispositioned as a secondary hardening opportunity (they never reach the reservation ledger) rather than fixed — noted for future consideration.
- `PlaidReclassificationApprovalsPage`'s read-gate fix and H7's firewall-scope widening are both, by nature, closing gaps that had no live exploited data at the time of this review — not corrections of an active production incident.

## Confirmation

No real provider credentials or real customer/bank/payment/financial data were used anywhere in this remediation — every test runs against disposable, ephemeral local databases with synthetic fixture data, and every network-call boundary remains structurally forced through the same sanitizing executor verified clean above. Nothing was pushed, merged, deployed, or changed in AWS, staging, or production. `fix/preserve-force-rls-test-context` was not touched. The already-pushed release-candidate history on `feature/firmsvault-live-integrations` was not rewritten, amended, or force-pushed.

## Final verdict

**REMEDIATION_COMPLETE_READY_FOR_REVIEW**

All 3 Critical and 7 High findings from the independent post-push review are fixed with regression tests, plus 1 additional finding the pattern sweep surfaced and 3 Medium items (M1, M3, M4 fixed; M2 resolved as a written, non-code decision per its own instructions). Every fix is narrowly scoped to the identified defect — no architectural redesign of the Integration Framework, Admin Control Center, Microsoft 365, Google Workspace, Plaid, Matter, or Client Portal. No test was weakened to pass. Every corrected action enforces authorization inside the action/service itself. Static checks (Pint, `git diff --check`, secret scan, network scan) and 3 strictly sequential full-suite runs on fresh disposable databases (10,060/10,060, 89,241 assertions, 0 failures, 57 risky, identical every time) are all clean.

This verdict is **not** READY_FOR_STAGING — that determination is explicitly reserved for a new independent review of this checkpoint's final SHA, per this checkpoint's own governing instructions. Two items are disclosed as requiring follow-up decisions beyond this checkpoint's scope: the job-wide ambient-transaction rollback residual risk under C3, and the M2 dual-approval implementation decision.

---

<!-- APPENDED 2026-07-30 (Checkpoint 8.2, Phase A12). Nothing above this
     marker was rewritten, reordered, or deleted; this section is a pure
     addition recording the disposition of the Checkpoint 8.1 attempt. -->

## Checkpoint 8.1 rejected — superseded by Checkpoint 8.2

Checkpoint 8.1 attempted to close the C3 residual risk disclosed under "Known
limitations" above (the job-wide ambient-transaction rollback that can discard a
billable-call reservation) by moving the FK-bearing
`provider_billable_call_reservations` table onto an **independent database
connection**, so that a reservation write would survive a rollback of the
enclosing job transaction.

**That approach is rejected — it converts a rollback-durability bug into a hard
deadlock.** `provider_billable_call_reservations` carries a foreign key to
`firm_integrations`. `PullSyncJob` (`app/Jobs/PullSyncJob.php:173`) takes
`->lockForUpdate()` on the `firm_integrations` row and **holds that row lock for
the entire duration of the outbound provider call**. An INSERT into
`provider_billable_call_reservations` issued from a *separate* database session
must acquire `FOR KEY SHARE` on the referenced `firm_integrations` row to
validate its FK. `FOR KEY SHARE` conflicts with the `FOR UPDATE` lock the job is
already holding, and because the two statements are on different sessions
Postgres cannot resolve the wait by ordering them within one transaction — the
reservation INSERT simply blocks until the provider call completes, and in the
common case where the job's own progress depends on that reservation, the two
sessions wait on each other indefinitely.

This was **not** a theoretical objection: it was proven live against a
disposable database via direct `pg_stat_activity` / `pg_locks` inspection, which
showed the ambient job backend holding the `firm_integrations` row lock while the
independent-connection reservation INSERT sat blocked on the FK's `FOR KEY SHARE`
request. The failure is reachable in production, not only under test.

**Disposition:** Checkpoint 8.1 is rejected in full and superseded by
**Checkpoint 8.2**, which takes a structurally different route:

- an **FK-free operation ledger** — the durable record carries no foreign key to
  `firm_integrations`, so no cross-session FK validation lock is ever required
  and the deadlock above cannot arise; and
- **phased claim / call / apply execution** — the durable claim is committed
  before the provider call is made, the provider call happens outside any
  ambient transaction holding the connection lock, and the outcome is applied in
  a separate final phase.

Checkpoint 8.2's own findings, implementation, and verification are recorded
separately in [checkpoint8-2-remediation-report.md](checkpoint8-2-remediation-report.md).
