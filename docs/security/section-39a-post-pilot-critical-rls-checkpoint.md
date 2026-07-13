# Section 39A Checkpoint — Post Pilot-Critical FORCE RLS Status and Go/No-Go Recommendation

**Date:** 2026-07-10
**Branch:** `feature/section-39a-checkpoint-post-pilot-critical-rls`
**Base commit (main HEAD at inspection time):** `83c80e6` — "Merge branch 'feature/section-39a3h-force-rls-payments'"
**Scope:** Inspection and reporting only. No application code, migrations, or tests were modified for this checkpoint.

---

> **Correction (2026-07-13):** §4 ("44 prepared-but-unforced") and §5 ("43" uncovered tenant tables), and the corresponding references in §8–11, are a 2026-07-10 snapshot and are now stale. Following the Section 39A-3L FORCE RLS rollout (merged into `main`), **all 52 RLS-prepared tables are now forced — 0 prepared-but-unforced tables remain.** Separately, a later Wave 0/1 inventory-sweep correction identified more true tenant-owned tables than the original 39A-4 scope had captured, so the accurate current uncovered count is **61**, not 43 (a scoping correction, not a regression — no table went from covered to uncovered). See `docs/governance/rls-gap-registry.md` for the current authoritative gap accounting. The narrative, table lists, and verification detail below are preserved as the historical record of this checkpoint's original inspection and are not otherwise rewritten.

## 1. Current main commit

```
83c80e6 Merge branch 'feature/section-39a3h-force-rls-payments'
973d0b7 Complete Section 39A-3H FORCE RLS on payments
7ac7285 Complete Section 39A-3G FORCE RLS on invoices
05eee28 Complete Section 39A-3F FORCE RLS on matters
b686dfd Complete Section 39A-3E FORCE RLS on tasks
65b06fa Complete Section 39A-3D FORCE RLS on deadlines
7f42171 Complete Section 39A-3C FORCE RLS on documents
35183f5 Complete Section 39A-3B FORCE RLS on firm users
```

`main` was confirmed clean and up to date with `origin/main` (`git pull` reported "Already up to date") before this checkpoint branch was created.

## 2. Current FORCE RLS tables

Confirmed by direct `psql` query against `pg_class.relforcerowsecurity` (not ORM/service assumptions) on a freshly-migrated `firmsbase_test` database:

| Table | RLS enabled | FORCE RLS | Section |
|---|---|---|---|
| clients | t | **t** | 39A-3A |
| firm_users | t | **t** | 39A-3B |
| documents | t | **t** | 39A-3C |
| deadlines | t | **t** | 39A-3D |
| tasks | t | **t** | 39A-3E |
| matters | t | **t** | 39A-3F |
| invoices | t | **t** | 39A-3G |
| payments | t | **t** | 39A-3H |

All 8 pilot-critical tables are forced. No table outside this list has FORCE RLS active (verified across all 52 RLS-enabled tables — see §3).

## 3. Pilot-critical FORCE RLS completion status

**Complete.** All 8 tables named in the pilot-critical scope (clients, firm_users, documents, deadlines, tasks, matters, invoices, payments) have permanent `FORCE ROW LEVEL SECURITY` active, each via its own single-table migration (`2026_07_30_900001` through `2026_08_06_900001`), each independently proven via a dedicated `*ForceRlsActivationTest.php` file plus the shared firewall/coverage tests.

## 4. Remaining prepared-but-unforced tables

Computed via `RowLevelSecurityCoverageMappingService::preparedTables()` (the same source used by the existing RLS firewall/coverage tests) minus the 8 now-forced tables.

**Count: 44** *(Corrected 2026-07-13: 0 remain unforced — Section 39A-3L completed forcing all 52 prepared tables. See correction note above. The table list immediately below is preserved as the historical 2026-07-10 snapshot.)*

```
firm_settings, security_events, firm_licenses, firm_entitlements,
firm_entitlement_events, activation_checklists, tenant_encryption_keys,
client_communication_preferences, communication_consents,
communication_consent_events, lead_sources, consultation_outcomes,
firm_leads, consultations, contacts, parties, firm_practice_areas,
installed_template_packs, intake_submissions, conflict_check_runs,
timeline_events, employee_rates, time_tracking_sessions, time_entries,
payment_plans, payment_plan_events, payment_classification_events,
document_requests, calendar_events, notification_events,
notification_templates, document_chase_rules, document_chase_events,
matter_readiness_scores, readiness_score_events, firm_activation_events,
health_checks, backup_restore_tests, incident_events,
maintenance_windows, pilot_feedback_items, seat_allocations,
template_upgrade_previews, template_upgrade_logs
```

These tables have `ENABLE ROW LEVEL SECURITY` + a firm-matching policy already in place (Phases 1–6 preparation migrations), but not yet `FORCE`. They remain inert for the app's own database connection (table-owner role bypasses non-forced RLS), matching the current, unmodified `rls_prepared_not_enforced` gap registry entry. None of these were modified, forced, or otherwise touched by this checkpoint.

## 5. Remaining uncovered tenant tables needing 39A-4

Computed via `RowLevelSecurityCoverageMappingService::missingPreparedTables()`.

**Count: 43** *(Corrected 2026-07-13: the accurate current count is 61 — a later Wave 0/1 inventory-sweep correction found more true tenant-owned tables than this checkpoint's original scope had captured. See correction note above and `docs/governance/rls-gap-registry.md`. The table list immediately below is preserved as the historical 2026-07-10 snapshot and is not itself wrong for that date — it is simply incomplete relative to the corrected inventory.)*

```
accounting_export_batches, ai_approval_events, ai_approval_requests,
ai_retrieval_indexes, ai_tool_actions, ai_usage_events, chart_of_accounts,
deletion_requests, deployment_configs, deployment_health_checks,
email_accounts, email_attachments, email_messages, email_message_links,
email_visibility_rules, expenses, expense_approvals, expense_categories,
expense_receipts, export_jobs, firm_ai_provider_keys, firm_ai_settings,
form_drafts, generated_documents, import_batches, key_destruction_requests,
legal_holds, migration_projects, offboarding_requests,
private_enterprise_settings, signature_certificates, signature_requests,
signature_request_recipients, trust_accounts, trust_balances,
trust_chargeback_events, trust_ledger_entries, trust_ledgers,
trust_reconciliations, trust_refund_requests, trust_transfer_requests,
webhook_events, webhook_subscriptions
```

These tenant-owned tables (Phase 7 onward: email, forms, e-signature, accounting/expenses, trust accounting, webhooks, AI governance, deployment/license/governance) have **no RLS preparation at all** — no `ENABLE ROW LEVEL SECURITY`, no policy. This is the full scope of future Section 39A-4 classification/policy work. None were modified by this checkpoint.

## 6. Known residual risks

- **Transitive firm-ownership mismatch (Matter/Invoice/Payment), documented and proven, not fixed at the DB layer.** PostgreSQL's RLS policy on each of these tables validates only the row's own `firm_id` column against session context — it cannot, via a single-column policy, verify that a matter's `client_id`, an invoice's `client_id`/`matter_id`, or a payment's `client_id`/`matter_id`/`invoice_id` transitively belongs to the same firm. This was proven empirically in 39A-3F (Matter/Client), 39A-3G (Invoice/Client/Matter), and 39A-3H (Payment/Client/Matter/Invoice): a raw insert with a correct `firm_id` but a foreign key pointing to a different firm's row **succeeds**. Mitigated today only at the factory level (all `forX()` state helpers derive every foreign key from one consistent source model), and proven-not-assumed via explicit tests in each `*ForceRlsActivationTest.php` file. A future composite/trigger-based DB constraint enforcing cross-table firm consistency is recommended but remains out of scope.
- **PostgreSQL foreign-key constraint checks bypass RLS entirely.** Confirmed independently in 39A-3F, 39A-3G, and 39A-3H — forcing a parent table never breaks child-table inserts referencing it, since FK validation runs as the table owner regardless of the querying role's RLS context. This is why the transitive-mismatch risk above is possible at all, and is a structural property of Postgres, not a bug in this codebase.
- **Nested `runWithFirmContext()` self-wrap hazard (discovered in 39A-3H, now an established convention).** A method that self-wraps its own body in `TenantContextService::runWithFirmContext()` is unsafe to call from *inside* another method's already-active `runWithFirmContext()` closure — the inner call's `finally` block clears context for the whole call stack the instant it returns, breaking the outer closure's subsequent reads/writes. This surfaced when `ManualPaymentService::submit()` was wrapped end-to-end in 39A-3H and called `PaymentClassificationService::recordDecision()` / `PaymentApplicationService::applyToInvoice()`, both of which self-wrapped. Fixed by removing the self-wraps from those two helper methods (they now rely on caller-established context, with an explanatory comment) and pushing the wrapping responsibility to every call site instead. **This convention should be checked against going forward**: any new service method added to a forced-table code path must not self-wrap if it is (or might later be) called from within another wrapped operation.
- **Pre-existing risky test (unrelated to RLS).** `ProfessionalReviewGapRegistryTest::test_overall_gate_status_referenced_gaps_are_all_real_registry_entries` (Section 37) has 0 assertions and is flagged risky by PHPUnit on every run, including every run performed for this checkpoint. Confirmed pre-existing and out of scope for the RLS rollout.
- **Places where tests required explicit tenant-context wiring (informational, not a defect).** Across 39A-3F/G/H, several tests needed to wrap post-operation read assertions (`assertDatabaseCount`, `assertDatabaseHas`, bare `Model::where(...)->count()`) in `runWithFirmContext(...)` once the underlying service correctly began clearing context after returning. This is expected, correct behavior of the FORCE RLS activation — the tests were previously passing only because no context was ever set/cleared around them.
- **`RowLevelSecurityCoverageMappingService`'s own docblock and `coverageSummary()` are now stale.** The class docblock states "RLS enforcement... is NOT active anywhere in this repository" and `coverageSummary()` hardcodes `'enforcement_active' => false`. Both are inaccurate as of 39A-3A onward — FORCE RLS is now active for 8 tables. This does not affect correctness (all firewall/coverage tests query `pg_class` directly rather than trusting this flag), but the stale doc/flag should be corrected in a future section to avoid misleading future readers of this class.

## 7. Known DB constraint recommendations

- A composite or trigger-based database constraint that validates cross-table firm consistency (e.g. a `BEFORE INSERT OR UPDATE` trigger on `matters`/`invoices`/`payments` checking that the referenced `client_id`/`matter_id`/`invoice_id` row's own `firm_id` matches the new row's `firm_id`) is recommended to close the transitive-mismatch gap described in §6. This has been recommended and deferred in every one of 39A-3F, 39A-3G, and 39A-3H's own reports — repeating it here as a durable, unresolved item rather than a new finding.
- No such constraint has been added anywhere in the codebase to date. This checkpoint does not add one, per the "inspect and report only" boundary.

## 8. Gap registry status

- `app/Services/ComplianceGapRegistryService.php` confirmed **unchanged** since Section 35 (`git log` shows no commits touching this file between Section 35 and the current `main` HEAD).
- Gap count: **21** (unchanged).
- `rls_prepared_not_enforced` is **still tracked** — correct, since 44 prepared tables remain unforced (§4).

## 9. Safe to proceed to Section 40 limited pilot safety gate?

**Yes, conditionally safe** — provided Section 40 explicitly documents the RLS limitations below rather than presenting RLS as fully complete:

- All 8 pilot-critical tenant tables (clients, firm_users, documents, deadlines, tasks, matters, invoices, payments) have permanent FORCE RLS active and independently verified isolation (cross-firm read/update/delete/insert-claiming-ownership all correctly blocked).
- The full test suite (2831 tests, 16444 assertions) and all 9 focused RLS/governance filters pass cleanly, twice, with and without `--stop-on-failure`.
- The documented residual gap (transitive firm-ownership mismatch via foreign keys) is a known, proven, factory-mitigated limitation — not a silent unknown.
- 44 prepared-but-unforced tables and 43 fully-uncovered tenant tables remain outside pilot scope and must be explicitly called out as a Section 40 pilot-scope limitation, not silently omitted.

## 10. Safe to proceed to login/domain setup after Section 40?

**Not yet assessed by this checkpoint** — that determination depends on Section 40's own pilot safety gate findings (which have not been run as part of this inspection-only checkpoint) and would additionally require 39A-4 classification of the 43 uncovered tenant tables to be complete or explicitly scoped out. This checkpoint recommends deferring that specific go/no-go call to after Section 40 runs, per the boundary against starting login/domain work here.

## 11. Recommended next step

Of the three options:
1. Continue 39A-3I+ for all remaining prepared tables (44 remaining).
2. Start 39A-4A uncovered tenant table classification (43 tables).
3. Proceed to Section 40 limited pilot safety gate with documented RLS limitations.

**Recommendation: Option 3 — proceed to Section 40 limited pilot safety gate**, with Section 40 required to explicitly document:
- The 8 pilot-critical tables are forced and independently verified; the pilot's actual tenant-isolation surface is limited to these 8 tables.
- 44 prepared-but-unforced tables and 43 fully-uncovered tenant tables are out of scope for this pilot and remain tracked via the unchanged `rls_prepared_not_enforced` gap and the 39A-4 backlog.
- The transitive firm-ownership mismatch residual risk (§6) and its factory-level mitigation.

This matches the pattern established across 39A-3A–H: pilot-critical coverage is complete and independently proven, the full suite is clean, and further FORCE-RLS/classification work is real but non-blocking for a *limited* pilot scope — provided the limitation is documented rather than assumed away.

---

## Verification detail

### Phase 1 — focused filters (each `--stop-on-failure`)

| Filter | Result |
|---|---|
| `RlsForceRollout` | 146/146 passed, 413 assertions |
| `RlsContextRollout` | 36/36 passed, 222 assertions |
| `RlsEnforcement` | 34/34 passed, 437 assertions |
| `Rls` | 253/253 passed, 1265 assertions |
| `PrePilotRemediationBacklog` | 53/53 passed, 380 assertions |
| `ProfessionalReviewGate` | 60/60 passed, 289 assertions, 1 pre-existing risky (see §6) |
| `AcceptanceTestMatrix` | 47/47 passed, 567 assertions |
| `DataModelContract` | 48/48 passed, 174 assertions |
| `CrossCutting` | 49/49 passed, 326 assertions |

### Full suite

- `php artisan test --stop-on-failure`: **2831/2831 passed**, 16444 assertions, 1 pre-existing risky.
- `php artisan test` (no stop-on-failure): **2831/2831 passed**, 16444 assertions, 1 pre-existing risky.

### Other checks

- `git diff --check`: clean (no whitespace errors).
- `php artisan migrate:fresh --env=testing`: clean, all migrations including the 8 FORCE RLS migrations applied successfully.
- Direct `psql` query of `pg_class.relforcerowsecurity`: confirms exactly the 8 expected tables forced, no accidental extras.
