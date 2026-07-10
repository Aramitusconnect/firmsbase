# Section 40 — Limited Pilot Safety Gate

**Date:** 2026-07-10
**Branch:** `feature/section-40-limited-pilot-safety-gate`
**Base commit:** `c8116bc` — "Merge branch 'feature/section-39a-checkpoint-post-pilot-critical-rls'"
**Gate service:** [app/Services/Section40LimitedPilotSafetyGateService.php](../../app/Services/Section40LimitedPilotSafetyGateService.php)
**Gate tests:** [tests/Feature/Governance/Section40/Section40LimitedPilotSafetyGateTest.php](../../tests/Feature/Governance/Section40/Section40LimitedPilotSafetyGateTest.php)

---

## What this gate is

**This permits internal pilot/login/panel/domain smoke testing only.**

This is a **limited internal pilot readiness gate** — it answers one narrow question: is it currently safe to begin **internal** login/panel/domain smoke testing against FirmsBase. It is not, and must never be read as, a public launch gate or a full production launch gate.

**This does not permit public production launch.** The gate service itself hardcodes `public_production_launch_recommended => false` unconditionally — it is not derived from a condition that could someday flip true by accident. A separate, later gate is required before any public production launch.

**39A-4 remains outstanding.** 43 tenant-owned tables (email, forms, e-signature, accounting/expenses, trust accounting, webhooks, AI governance) have no RLS preparation at all.

**Remaining prepared-but-unforced FORCE RLS work remains outstanding.** 44 of the 52 RLS-prepared tables are not yet forced.

## What Section 40 allows

- Determining, via this read-only gate, whether internal login/panel/domain smoke testing may begin.
- Reporting (not fixing) the current security foundation status: RLS coverage, login policy, 2FA policy, emergency support approval wiring, seed/secret audit.

## What Section 40 does not allow

- No login UI implementation.
- No domain or HTTPS connection.
- No 39A-4 implementation.
- No new product features.
- No payment integrations, LawPay, Stripe, accounting, private storage, AI features, or client portal features.
- No modification of `ComplianceGapRegistryService` to hide gaps.
- No weakening of any RLS test.
- No `BYPASSRLS`, no unsafe admin bypass.
- No public routes.

All of the above were confirmed **not** introduced by this section — see verification detail below and `Section40LimitedPilotSafetyGateTest::test_gate_service_itself_created_no_routes_controllers_or_ui` / `test_compliance_gap_registry_service_was_not_modified`.

## Current FORCE RLS status

All 8 pilot-critical tables remain forced (directly re-confirmed via `pg_class.relforcerowsecurity`, not assumed):

| Table | FORCE RLS |
|---|---|
| clients | forced |
| firm_users | forced |
| documents | forced |
| deadlines | forced |
| tasks | forced |
| matters | forced |
| invoices | forced |
| payments | forced |

`Section40LimitedPilotSafetyGateService::isPilotCriticalRlsFullyForced()` returns `true`.

## Remaining RLS limitations

- **44 prepared-but-unforced tables** — `Section40LimitedPilotSafetyGateService::remainingPreparedUnforcedTables()`, sourced from the same `RowLevelSecurityCoverageMappingService::preparedTables()` the existing RLS firewall tests use.
- **43 fully uncovered tenant-owned tables** requiring Section 39A-4 classification — `Section40LimitedPilotSafetyGateService::uncoveredTenantTables()`, sourced from `RowLevelSecurityCoverageMappingService::missingPreparedTables()`.
- The documented transitive firm-ownership mismatch residual risk (Matter/Invoice/Payment foreign keys not cross-validated by RLS) carried forward from the Section 39A checkpoint remains unresolved and out of scope here.

## Security foundation readiness (all confirmed present and wired)

| Check | Result | Source |
|---|---|---|
| Login policy wrapper readiness (Section 39D) | ready | `LoginPolicyService` |
| Firm user 2FA policy readiness (Section 39B) | ready | `FirmUser2faPolicyService` |
| Emergency support access approval readiness (Section 39C) | ready | `EmergencyAccessGovernanceGapService::isHighRiskApprovalWired()` |
| Seed/default secret audit readiness (Section 39E) | clean | `SeedDataSecurityAuditService::isClean()` |
| No public legal document URLs | confirmed | live route table scan (no terms/privacy/legal/tos/dpa/subprocessor route) |
| No known cross-firm data exposure | confirmed | derived from pilot-critical FORCE RLS state, backed by the Sections 39A-3A-3H proof-test suites |
| No active high-risk blocker for internal login testing | **confirmed** | `hasNoActiveHighRiskBlockerForInternalLoginTesting()` — all of the above combined |

## Gap registry status

`ComplianceGapRegistryService.php` untouched by this section. **Gap count: 21** (unchanged). `rls_prepared_not_enforced` still tracked. The gate's own `evaluate()['gap_registry_count']` re-reads this live count on every call — it is never hardcoded, so it cannot silently drift from the real registry.

## Safe to proceed to Section 40 limited pilot safety gate?

**Yes.** All pilot-critical RLS tables are forced, and every other required internal-pilot security check (login policy, 2FA policy, emergency support approval, seed audit, no public legal URLs) passes. `hasNoActiveHighRiskBlockerForInternalLoginTesting()` returns `true`.

## Safe to proceed to login/domain after Section 40?

**Only for internal smoke testing**, not public launch. Internal login/panel/domain smoke testing may proceed once this section's gate artifacts are reviewed and merged. Public production launch requires a separate later gate that addresses: full 39A-4 classification, remaining prepared-FORCE-RLS completion (or an explicit accepted-risk sign-off), legal documents (Terms of Service, Privacy Policy, DPA, Subprocessor list, Acceptable Use Policy, disclaimers), domain/HTTPS connection, and the full production hardening checklist (real backup runner, restore rehearsal, queue/scheduler supervision, real malware scanning engine, secret rotation, monitoring/alerting).

## Recommended next step

**Proceed to login/panel access wiring for internal testing.** All pilot-critical security prerequisites are in place and independently verified; 39A-4 and the remaining prepared-FORCE-RLS work are real but explicitly non-blocking for a narrowly-scoped internal smoke test, consistent with the Section 39A checkpoint's own recommendation.

---

## Verification detail

### Files changed

**Created:**
- `app/Services/Section40LimitedPilotSafetyGateService.php`
- `tests/Feature/Governance/Section40/Section40LimitedPilotSafetyGateTest.php`
- `docs/governance/section-40-limited-pilot-safety-gate.md`

**Modified (firewall/allowlist bookkeeping only — no assertion weakened):**
- `tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php` — added the new Section 40 service/test/report files to both its prefix-matched allowlist and its shared exact-match `changedOrUntrackedPaths()` list.
- `tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php` — added the new gate service to its `ALLOWED_MODIFIED_FILES` list.
- `tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php` — added a `docs/` prefix allowance.
- `tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php` — added a `docs/` prefix allowance.
- `tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php` — added a `docs/` prefix allowance.
- `tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php` — added a `docs/` prefix allowance.
- `tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php` — added a `docs/` prefix allowance.
- `tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php` — added a `docs/` prefix allowance.
- `tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php` — added a `docs/` prefix allowance.
- `tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php` — added a `docs/` prefix allowance.
- `tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php` — added the exact new report path to its chain-style exclusion list (this file uses a third, distinct allowlist mechanism from the other two above).

All ten of these firewall-test edits exist because this section, like every prior one, legitimately introduces new files that a prior section's own regression guard could not have anticipated — each edit is an additive, narrowly-scoped allowlist entry with an explanatory comment, never a removed or loosened assertion.

### `php -l` on every changed PHP file

Clean — no syntax errors, confirmed individually on every file listed above.

### `git diff --check`

Clean — no whitespace errors.

### `php artisan migrate:fresh --env=testing`

Clean — all migrations, including all 8 FORCE RLS migrations, applied successfully.

### Focused filters (each run individually, `--stop-on-failure`)

| Filter | Result |
|---|---|
| `Section40` | 15/15 passed, 53 assertions |
| `PrePilotRemediationBacklog` | 53/53 passed, 380 assertions |
| `ProfessionalReviewGate` | 60/60 passed, 289 assertions, 1 pre-existing risky |
| `AcceptanceTestMatrix` | 47/47 passed, 567 assertions |
| `DataModelContract` | 48/48 passed, 174 assertions |
| `CrossCutting` | 49/49 passed, 326 assertions |
| `RlsForceRollout` | 146/146 passed, 413 assertions |
| `RlsContextRollout` | 36/36 passed, 222 assertions |
| `RlsEnforcement` | 34/34 passed, 437 assertions |
| `LoginPolicy` | 29/29 passed, 91 assertions |
| `FirmUser2fa` | 26/26 passed, 62 assertions |
| `EmergencySupport` | 19/19 passed, 44 assertions |
| `SeedData` | 29/29 passed, 1388 assertions |

All 13 filters passed clean on the run that included every firewall-allowlist fix above. (First pass surfaced 6 legitimate firewall failures across `PrePilotRemediationBacklog`, `ProfessionalReviewGate`, `AcceptanceTestMatrix`, `EmergencySupport`, and `SeedData` — each caused by the new Section 40 files not yet being allowlisted; each was fixed narrowly and the filter re-run clean.)

### Full suite (twice)

- `php artisan test --stop-on-failure`: **2846/2846 passed**, 16499 assertions, 1 pre-existing risky. (First attempt caught 2 additional legitimate firewall trips — `AdminControlFirewallTest` and `AdminControlUiBoundaryTest`, both from the new `docs/governance/` report file not yet being allowlisted in those two files — fixed, re-run clean.)
- `php artisan test` (no stop-on-failure): **2846/2846 passed**, 16499 assertions, 1 pre-existing risky.

(2831 baseline from the Section 39A checkpoint + 15 new `Section40LimitedPilotSafetyGateTest` tests = 2846.)

### Risky/flaky tests

`ProfessionalReviewGapRegistryTest::test_overall_gate_status_referenced_gaps_are_all_real_registry_entries` (Section 37, zero-assertion test) — pre-existing, unrelated to this section, confirmed in every run across the last three sections (39A-3H, 39A checkpoint, 40). No flaky tests observed this section.

### Working tree

Dirty — 3 new files (gate service, gate test, this report) plus 11 modified firewall/allowlist test files, matching the "do not commit" instruction. No commit, push, or merge performed.

### Safe to commit

Yes — every change is either the new, narrowly-scoped read-only governance service/test/report, or an additive firewall-allowlist entry with an explanatory comment. No application behavior, migration, or route was touched; no existing assertion was weakened.
