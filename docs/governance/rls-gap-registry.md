# RLS Gap Registry

**Status:** Active governance record — current as of 2026-07-13 (Wave 0/1 tenant-isolation classification effort).
**Owning area:** tenant_isolation.
**Relationship to `ComplianceGapRegistryService`:** this document is a prose companion to the `rls_prepared_not_enforced` gap tracked in `app/Services/ComplianceGapRegistryService.php`. That service is intentionally described there without hardcoded counts ("this description intentionally does not hardcode them, since every rollout batch changes them") — this document is where the current counts and the individual open items below are actually enumerated and kept current. A sibling agent (1A) is building the authoritative merged code-side classification structure; this document must stay consistent with it but does not attempt to replace it.

---

## 1. Primary ongoing gap: 37 uncovered tenant-owned tables

**Count: 37** (was 61 as of the Wave 0/1 snapshot this document was originally written against; Section 39A-5, Checkpoint 1 closed one of them — `customer_success_health_scores`; Section 39A-5 Wave 1 closed three more — `ai_retrieval_indexes`, `deployment_configs`, `firm_ai_settings`; Section 39A-5 Wave 2 closed four more — `email_visibility_rules`, `private_enterprise_settings`, `matter_expenses`, `email_message_links`; Section 39A-5 Wave 3 closed five more — `ai_usage_events`, `ai_tool_actions`, `firm_ai_provider_keys`, `ai_approval_requests`, `ai_approval_events`; Section 39A-5 Wave 4 closed seven more — `chart_of_accounts`, `expense_categories`, `expenses`, `expense_receipts`, `expense_approvals`, `accounting_export_batches`, `accounting_export_lines`; Section 39A-5 Wave 5 closed four more — `email_accounts`, `email_messages`, `email_attachments`, `email_sync_events` — by giving each a real RLS policy and FORCE activation in the same batch; see `docs/governance/future-table-requirements.md` for the closure shape this followed). These are tenant-owned tables with **no RLS preparation at all** — no `ENABLE ROW LEVEL SECURITY`, no policy. The original 61 was a corrected, larger figure than the previously-documented "43 uncovered" snapshot from 2026-07-10 (see `docs/governance/section-40-limited-pilot-safety-gate.md` and `docs/security/section-39a-post-pilot-critical-rls-checkpoint.md` for the corrected historical record). The growth from 43 to 61 was a **scoping correction**, not a regression: a later Wave 0 inventory sweep found more true tenant-owned tables than the original 39A-4 scope had captured — no table that was previously covered lost its coverage.

- **Severity:** High — matches the severity already assigned to `rls_prepared_not_enforced` in `ComplianceGapRegistryService`.
- **Suggested owning gate:** the ongoing 39A-4-successor classification/preparation effort (Wave 1 and its successor waves).
- **Status:** open.
- **Authoritative live count:** `RowLevelSecurityCoverageMappingService::missingPreparedTables()`. This document intentionally does not enumerate all 37 table names, to avoid a second stale hardcoded list — the code-side service is the source of truth for the exact list at any given time; sibling agent 1A owns keeping that list current as classification work proceeds.
- **What "closing" this gap requires, per table:** classification (tenant-owned, confirmed ownership path), an RLS preparation migration (`ENABLE ROW LEVEL SECURITY` + policy), and eventually a FORCE migration plus activation test — see `docs/governance/future-table-requirements.md` for the full requirement shape expected of any table crossing from uncovered to covered.

Separately, **0 prepared-but-unforced tables remain** as of the Section 39A-3L FORCE RLS rollout (all 52 prepared tables are now forced). This closes out what was previously a second, separate open figure in the 2026-07-10 snapshot docs — it is not part of the 61 above, and is noted here only to avoid the two figures being conflated by a future reader.

## 2. `offboarding_exports` — open investigation, not counted in the 61 and not an exemption

`offboarding_exports` remains classified **Uncertain**. It is deliberately **not** counted among the 61 uncovered tenant tables above, and it is **not** an approved exemption under `docs/governance/rls-exemption-policy.md`. It is tracked here as a distinct, standalone open item because:

- No exemption has been granted — there is no documented Global/Platform classification with the required reason, readers, writers, and structural proof.
- No RLS preparation has been added — no invented ownership path has been assigned to it.
- A dedicated investigation, **Wave 1F**, is running in parallel and is tasked with recommending either (a) one canonical ownership path for the table, or (b) a required schema correction (e.g. adding a direct `firm_id` or a non-nullable FK to a firm-scoped table) if the current schema cannot honestly support tenant ownership as-is.
- Per direct migration inspection (`database/migrations/2026_07_28_900004_create_offboarding_exports_table.php`), the table currently has a nullable `offboarding_request_id` FK to `offboarding_requests`, an unconstrained `deletion_request_id` bigint (intentionally not a real FK — see the migration's own docblock on circular-reference ordering), and a nullable `export_job_id` FK to `export_jobs`. None of these FKs is guaranteed non-null, which is exactly why a canonical ownership path is not yet obvious and requires the Wave 1F investigation rather than a guess here.

**Policy implementation for this table is explicitly parked** until the Wave 1F report is reviewed by a human. This document does not resolve it — it only registers that it is open and describes why.

- **Severity:** Uncertain pending Wave 1F findings (not yet assigned).
- **Suggested owning gate:** Wave 1F investigation, followed by a human-reviewed decision.
- **Status:** open — parked pending investigation.

## 3. Cross-FK pivot mismatch remediation — registered Wave 3 task

The transitive/cross-firm pivot-mismatch risk — previously documented only for `matters`/`invoices`/`payments` in the Section 39A checkpoint (see the "Known residual risks" section of `docs/security/section-39a-post-pilot-critical-rls-checkpoint.md`) — has been reviewed and is **explicitly not accepted as residual risk**. It additionally affects `matter_parties`, `matter_assignments`, and `task_dependencies`, none of which carry their own `firm_id` column (all are scoped only transitively through a parent table's `firm_id`, confirmed by direct migration inspection).

A full remediation task is registered for **Wave 3**. See `docs/security/cross-firm-pivot-remediation-tasks.md` for the complete task registration, affected-table list, and required remediation shape (database-enforced tenant consistency preferred over RLS-on-parent-tables alone, plus required negative tests).

- **Severity:** High — RLS on the parent tables alone is explicitly declared insufficient to close this gap.
- **Suggested owning gate:** Wave 3 remediation task (registered, not yet implemented).
- **Status:** open — registered, awaiting Wave 3 implementation.

## 4. `firms` root-table policy — registered future design decision

The eventual RLS policy for the `firms` root tenant table itself has been decided at the design level but is **not yet implemented**. See `docs/security/firms-root-tenant-policy-design.md` for the full design record: a direct primary-key-match policy predicate, a strictly separate and audited platform-administration execution path, and the five required test categories for whenever this is implemented.

- **Severity:** Not yet assigned (design decision, no implementation risk exists until implementation begins).
- **Suggested owning gate:** likely Wave 3/4, per the design record.
- **Status:** open — registered design decision, awaiting a dedicated implementation task.

## 5. Support-access policy shape — registered task awaiting dedicated review

The required RLS/authorization shape for `support_access_requests` and `support_access_sessions` has been specified at the design level (no broadly permissive platform-admin bypass is acceptable). See `docs/security/support-access-policy-design.md` for the full required shape and an accurate description of the current `SupportAccessPolicyService`/`SupportAccessSessionService`/`SupportAccessRequestService` architecture this design record was checked against.

- **Severity:** High — a support-access RLS policy that is too permissive would defeat tenant isolation for every firm at once; this must not be implemented casually.
- **Suggested owning gate:** a dedicated, reviewed implementation task — explicitly deferred out of Wave 1.
- **Status:** open — registered design record, awaiting dedicated implementation task.

## Summary table

| Item | Count / scope | Status | Owning doc |
|---|---|---|---|
| Uncovered tenant-owned tables | 37 | open, ongoing | this document, §1 |
| Prepared-but-unforced tables | 0 (closed) | closed as of 39A-3L | this document, §1 (noted for clarity) |
| `offboarding_exports` | 1 table, uncertain | open, parked pending Wave 1F | this document, §2 |
| Cross-FK pivot mismatch | 6 tables (`matter_parties`, `matter_assignments`, `task_dependencies`, `matters`, `invoices`, `payments`) | open, registered Wave 3 task | `docs/security/cross-firm-pivot-remediation-tasks.md` |
| `firms` root-table policy | 1 table, design only | open, registered design decision | `docs/security/firms-root-tenant-policy-design.md` |
| Support-access policy shape | 2 tables, design only | open, registered design decision | `docs/security/support-access-policy-design.md` |
| Approved global exemptions | 2 tables (`module_catalog`, `readiness_scorecard_components`) | approved, subject to structural proof | `docs/governance/rls-exemption-policy.md` |
