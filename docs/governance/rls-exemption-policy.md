# RLS Exemption Policy

**Status:** Active governance policy.
**Owning area:** tenant_isolation / RLS classification.
**Applies to:** any database table considered for classification as `Global`, `Organization`, `Platform`, or otherwise exempt from firm-level Row Level Security (RLS) preparation and enforcement.

---

## Purpose

FirmsBase's tenant-isolation model assumes every table is tenant-owned (and therefore requires RLS preparation, a policy, and eventually FORCE enforcement) **unless it has been explicitly exempted through this process**. An exemption is a security-relevant decision — it is a standing claim that a table can never contain a row that belongs to exactly one firm and must never leak across firms. That claim must be justified, recorded, and structurally protected against silently becoming false later (for example, a future migration adding a `firm_id` column to a table that was exempted years earlier).

This document formalizes what is required before any table may be classified as exempt, so that "exempt" is always a reviewed, documented decision rather than an assumption made in passing during classification work.

## Required fields for every exemption

Every table proposed for exemption must have all of the following recorded, either in this document (for tables using this document as their registry of record) or in the equivalent structure a sibling classification effort maintains in code (e.g. `RowLevelSecurityCoverageMappingService`'s exemption list) — the two must stay consistent with each other:

1. **Classification** — the exemption category (e.g. `Global`, `Organization-level`, `Platform/system`), not just "exempt." Different categories imply different expected readers/writers and different risk if the assumption is later violated.
2. **Reason** — a concrete, falsifiable statement of *why* the table cannot contain firm-scoped data. "No firm_id column and no foreign-key path to a firm-scoped table" is a reason. "Doesn't seem tenant-specific" is not.
3. **Expected readers** — which parts of the system are expected to query this table (e.g. "read by every firm's practice-area selection UI," "read only by platform admin catalog tooling").
4. **Authorized writers** — which services/migrations/seeders are the legitimate writers of this table. An exemption is far riskier if arbitrary application code can write to the table than if only a small, known set of platform-level services can.
5. **Structural no-tenant-column proof** — a verifiable, structural check (not a one-time human read of the migration) that the table has no `firm_id`/tenant-scoping column and no non-nullable foreign key into a firm-scoped table. This proof should be automatable and re-run on every future migration (see CI protection below).
6. **CI protection** — confirmation that the automated inventory/classification firewall (Wave 1B's CI gate) will fail if a future migration ever adds a tenant-ownership column (`firm_id` or equivalent) to an exempted table, forcing a human reclassification review rather than allowing the table to silently drift out of its exemption's justification.

A table missing any of the six fields above is not a valid exemption — it is an unclassified table that happens to currently look global, and must be tracked as an open gap (see `docs/governance/rls-gap-registry.md`) rather than folded into the exemption list.

## Approval process

1. Whoever proposes an exemption documents all six fields above.
2. The structural no-tenant-column proof must be independently verifiable — normally by inspecting the table's migration(s) directly for the absence of a `firm_id`/tenant column and any FK into a firm-scoped table.
3. A human reviews and approves the exemption before it is treated as final policy. Until approved, the table remains in whatever classification (usually "uncertain" or "newly discovered") it had before the exemption was proposed.
4. Once approved, the exemption is recorded here (or in the code-side registry it mirrors) with all six fields, and the CI protection referenced in field 6 is confirmed wired for that table.
5. If a later migration adds tenant-ownership to an exempted table, CI must fail and require an explicit reclassification review — an exemption is never allowed to be quietly invalidated by an unrelated schema change.

## Worked example: the two 2026-07-13 approved exemptions

Both of the following tables were reviewed and approved as `Global` exemptions as part of the Wave 1 tenant-isolation classification effort, subject to the structural proof described in field 5 below (a sibling agent, 1A, is adding this as an automated structural test in code).

### `module_catalog`

| Field | Value |
|---|---|
| Classification | Global |
| Reason | Platform-wide reference catalog of installable practice-area/feature modules, addressed by its own unique `module_code` string. Confirmed by direct migration inspection (`database/migrations/2026_07_04_300001_create_module_catalog_table.php`): no `firm_id` column, no foreign key into any firm-scoped table. The migration's own docblock states it is "global reference data... Not firm-scoped." |
| Expected readers | Every firm's module/entitlement resolution and template-pack installation code paths (read-only, platform-wide). |
| Authorized writers | Platform-level migrations/seeders only (e.g. `2026_07_09_900023_seed_phase6_module_catalog_entries.php`, `2026_07_21_900006_seed_phase14_module_catalog_webhook_entry.php`, `2026_07_23_900009_seed_phase15_module_catalog_ai_entry.php`). No firm-facing write path exists or is expected to exist. |
| Structural no-tenant-column proof | Direct migration inspection confirms columns are limited to `id`, `module_code`, `module_name`, `category`, `description`, `is_active`, `requires_admin_approval`, timestamps — no tenant-scoping column of any kind. |
| CI protection | Required: Wave 1B's future-migration CI firewall must fail if any later migration adds a `firm_id` (or equivalent tenant-scoping) column to `module_catalog`, forcing a reclassification review rather than a silent exemption drift. |

### `readiness_scorecard_components`

| Field | Value |
|---|---|
| Classification | Global |
| Reason | Platform-wide pluggable registry of readiness-scorecard component definitions (e.g. `intake_complete`, `documents_approved`, `forms_ready`). Confirmed by direct migration inspection (`database/migrations/2026_07_07_800013_create_readiness_scorecard_components_table.php`): no `firm_id` column. The migration's own docblock explicitly states "GLOBAL platform catalog, no firm_id, same pattern as ... practice_areas/matter_types" and that registering a new component is "a data row plus registry code, never a schema change." |
| Expected readers | Readiness-scorecard computation code paths across all firms (read-only, platform-wide component registry). |
| Authorized writers | Platform-level migrations only, as new components ship with new phases/modules. No firm-facing write path exists or is expected to exist. |
| Structural no-tenant-column proof | Direct migration inspection confirms columns are limited to `id`, `component_key`, `label`, `description`, `status`, `introduced_in_phase`, `weight`, timestamps — no tenant-scoping column of any kind. |
| CI protection | Required: Wave 1B's future-migration CI firewall must fail if any later migration adds a `firm_id` (or equivalent tenant-scoping) column to `readiness_scorecard_components`, forcing a reclassification review. |

Both exemptions are approved **subject to** the structural proof test referenced above landing and passing (owned by sibling agent 1A). Neither table's exemption should be treated as final/immutable — like every exemption under this policy, it stands only until a future migration or audit finds cause to revisit it.

## Relationship to other governance documents

- `docs/governance/rls-gap-registry.md` — tracks tables that are *not yet* classified or exempted (the 61 uncovered tenant tables, plus open items like `offboarding_exports`), as distinct from this document's approved-exemption registry.
- `docs/governance/future-table-requirements.md` — describes what the Wave 1B CI firewall requires of *every* new table going forward, including the exemption-reason requirement this document formalizes.
