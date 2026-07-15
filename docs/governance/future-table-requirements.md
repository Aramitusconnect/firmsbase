# Future Table Requirements — RLS CI Firewall

**Status:** Active governance policy, recorded 2026-07-13.
**Owning area:** tenant_isolation / RLS classification.
**Enforced by:** the Wave 1B CI firewall (built in parallel with this document; see that wave's own PR/branch for the actual CI implementation — this document describes the requirement it enforces, not the CI code itself).

---

## Purpose

Prior to this effort, new tables could be added to the schema without any structured classification step — a table might be tenant-owned, global, platform-only, or genuinely ambiguous, and nothing forced that determination to be made and recorded at the time the table was created. This is how the codebase accumulated 61 uncovered tenant-owned tables and at least one genuinely uncertain table (`offboarding_exports`) without any single point catching the gap as it was introduced.

Going forward, the Wave 1B CI firewall requires every migration that creates a new table to satisfy the requirements below. A migration that fails to satisfy them should fail CI, not merely generate a warning that can be ignored.

## Required for every new table

### 1. Classification is required

Every new table must be classified as one of:

- **Tenant-owned (direct)** — has its own `firm_id` (or equivalent) column.
- **Tenant-owned (inherited/transitive)** — no `firm_id` of its own, but scoped through a parent table's `firm_id` (e.g. `matter_parties` via `matter_id` → `matters.firm_id`). See `docs/security/cross-firm-pivot-remediation-tasks.md` for why this category carries its own cross-FK consistency risk that must be addressed, not merely acknowledged.
- **Pivot** — a many-to-many join table, which may or may not carry its own `firm_id` (see the remediation doc above for the tradeoffs).
- **Hybrid** — carries some tenant-scoped and some global data, or is tenant-owned but also read cross-tenant in a specific, narrow, documented way.
- **Global** — no tenant boundary; requires an approved exemption (see requirement 3).
- **System** — infrastructure/framework tables (e.g. jobs, cache, sessions) not part of the domain model.
- **Audit** — append-only event/log tables; classification should note whether the audit rows themselves are tenant-scoped (most are, via the `firm_id` of the event they describe) even if the auditing mechanism itself is generic.
- **Uncertain** — the classification is genuinely unclear at migration time. This is not a forbidden classification, but it must never silently coast forward — see requirement below on uncertain tables.

A migration introducing a new table with no classification recorded anywhere is a CI failure, not a silent gap.

### 2. Ownership path is required if tenant-owned

If a table is classified tenant-owned (direct, inherited, pivot, or hybrid), the migration (or its accompanying classification record) must state the concrete ownership path:

- For direct ownership: the `firm_id` column itself.
- For inherited/transitive ownership: the exact FK chain to the firm-scoped ancestor (e.g. "`matter_id` → `matters.firm_id`"), and whether that chain is a single hop or multiple.
- For hybrid tables: which columns/rows are tenant-scoped and which are not, and by what rule a query distinguishes them.

A table classified tenant-owned with no stated ownership path is exactly the failure mode that produced `offboarding_exports`'s "Uncertain" status (see `docs/governance/rls-gap-registry.md`, §2) — this requirement exists specifically to prevent that from recurring silently.

### 3. Exemption reason is required if exempt

If a table is classified Global/System (i.e., claimed exempt from RLS), it must satisfy every requirement in `docs/governance/rls-exemption-policy.md`: classification, reason, expected readers, authorized writers, a structural no-tenant-column proof, and CI protection against a later migration silently adding tenant ownership to the table. A table claimed exempt without all six fields recorded is a CI failure — it must instead be tracked as an open, unclassified gap rather than folded into the exemption list.

### 4. Policy coverage is required if RLS-enabled

If a migration adds `ENABLE ROW LEVEL SECURITY` to a table, it must also add at least one `CREATE POLICY` in the same migration (or an immediately-following one in the same batch). RLS-enabled-with-no-policy is worse than not enabling RLS at all in some Postgres configurations (default-deny behavior can vary), and is never an acceptable intermediate state to leave unmerged.

### 5. Activation test is required if FORCE-enabled

If a migration adds `FORCE ROW LEVEL SECURITY` to a table, the same change must be accompanied by a dedicated activation test proving, at minimum:

- Correct-firm access succeeds.
- Wrong-firm access (read/update/delete) is denied.
- An insert with a correct `firm_id` but a claim of ownership it shouldn't have is denied (or, if the table has FK-mediated transitive-ownership risk, that risk is explicitly called out per `docs/security/cross-firm-pivot-remediation-tasks.md` rather than silently assumed covered).
- No-context access is denied (fails closed).

This mirrors the pattern already established by every `*ForceRlsActivationTest.php` file in this codebase for the 52 prepared tables — the CI firewall's job is to make this pattern mandatory for every future FORCE migration, not just a convention future contributors happen to follow.

## Relationship to other governance documents

- `docs/governance/rls-exemption-policy.md` — the detailed process referenced by requirement 3 above.
- `docs/governance/rls-gap-registry.md` — where tables that fail to meet these requirements today (the 61 uncovered tables, `offboarding_exports`) are tracked as open gaps.
- `docs/security/cross-firm-pivot-remediation-tasks.md` — the registered remediation for the transitive/pivot-ownership risk referenced in requirements 2 and 5 above.

## Non-goals

This document describes the requirement the Wave 1B CI firewall enforces. It does not implement that CI firewall itself (a PHP/CI-config change, out of scope for this documentation-only task) and does not modify any existing migration.
