# `firms` Root Tenant Table — RLS Policy Design Record

**Status:** Design decision recorded 2026-07-13. **Resolved 2026-08-20: EXPLICITLY APPROVED ROOT-TENANT EXEMPTION — see "Resolution" section below.** This document remains a full design record in case a future mission revisits the exemption.
**Table:** `firms` (`database/migrations/2026_07_04_100003_create_firms_table.php`).
**Primary key:** confirmed `bigint` auto-increment (`$table->id()`), with a separate `uuid` column (`$table->uuid('uuid')->unique()`) that exists as a public-facing identifier but is **not** the primary key. The eventual policy predicate should use the primary key (`id`), not `uuid`, unless a future review deliberately decides otherwise — this document records the decision as PK-based per the primary key actually in use.

---

## Why `firms` is different from every other tenant-owned table

Every other tenant-owned table in FirmsBase is scoped by a `firm_id` column that points *to* a firm — its RLS policy predicate checks that the row's `firm_id` matches the session's active firm context. `firms` itself has no `firm_id` column; it *is* the firm. Its own primary key **is** the tenant boundary. A policy shaped like every other table's ("does this row's `firm_id` match?") does not apply — the correct predicate compares the row's own `id` to the session's active firm context directly.

## Design decision: direct primary-key match, not a recursive/child lookup

The eventual RLS policy predicate for `firms` will be:

```sql
id = current_setting('app.current_firm_id', true)::bigint
```

(The `bigint` cast is pending final confirmation against the live schema at implementation time, but the migration inspected for this design record confirms `firms.id` is a standard auto-increment bigint primary key, not a uuid or other type — so `::bigint` is expected to be correct.)

This is a **direct primary-key match**, not a recursive or child-table ownership lookup (e.g. not "does some child row exist with this firm's id and the current user's grant"). A recursive lookup would be slower, harder to reason about, and would create a circular dependency risk (a policy on `firms` that queries another firm-scoped table to decide whether a `firms` row is visible). The direct primary-key match is the simplest predicate that correctly expresses "you may see the one firm you are currently operating as."

## Required behavior

- **Normal runtime access must be limited to the active firm.** Any query against `firms` issued through the application's normal database connection, under normal `SET LOCAL app.current_firm_id` tenant context, must see at most one row — the firm currently in context. No normal application code path should ever be able to enumerate or read a different firm's row.
- **Platform-wide firm administration must use a distinct, explicit, audited administrative execution path.** Operations that legitimately need to see or act on multiple firms (platform admin tooling, cross-firm reporting, billing reconciliation, etc.) must go through a separate, clearly-named execution path — not the normal per-request tenant context, and not a table-owner bypass.
  - This administrative path must never weaken the normal policy itself (e.g. it must not be implemented by making the `firms` RLS policy permissive-by-default and relying on application code to "usually" filter correctly — that inverts the security boundary).
  - It must not rely on `BYPASSRLS` or table-owner bypass as its mechanism — those bypass RLS unconditionally and are not auditable at the policy layer. The precedent set elsewhere in this codebase (see `docs/security/support-access-policy-design.md` for the analogous support-access requirement) is that any legitimate cross-tenant capability must be its own explicit, audited, scoped mechanism, not a generic bypass.

## Required tests when this is eventually implemented

Whoever implements this policy must prove all five of the following, matching the pattern already established by every prior FORCE RLS activation batch in this codebase (`*ForceRlsActivationTest.php` files under `tests/Feature/`):

1. **Correct-firm access** — a session with `app.current_firm_id` set to firm A can read/see firm A's own `firms` row.
2. **Wrong-firm denial** — a session with `app.current_firm_id` set to firm A cannot read/see firm B's `firms` row.
3. **No-context denial** — a session with no tenant context set (no `app.current_firm_id`) sees no `firms` rows at all (fails closed, not open).
4. **Platform administration only through the approved path** — the distinct administrative execution path can legitimately see/act on multiple firms, and *only* that path can; no other code path or ad hoc query can achieve the same breadth.
5. **Exact tenant-context restoration afterward** — after the administrative path completes (however it establishes its broader view), the normal per-request tenant context is exactly restored — no leaked elevated context, no lingering broadened visibility for subsequent operations in the same process/request lifecycle. This mirrors the "nested `runWithFirmContext()` self-wrap hazard" convention already discovered and documented in the Section 39A checkpoint (`docs/security/section-39a-post-pilot-critical-rls-checkpoint.md`, §6) — the administrative path must not leave context in a state that silently widens visibility for whatever runs next.

## Non-goals of this document

This document does not implement the policy, the migration, the administrative execution path, or the tests above. It exists solely so that whoever picks up this work (expected Wave 3/4) has the decision, its rationale, and its required proof obligations already settled rather than re-litigated.

---

## Resolution (Non-Payment Completion Program, Workstream 9 — 2026-08-20)

**Status: EXPLICITLY APPROVED ROOT-TENANT EXEMPTION.** Implementing RLS on `firms` now is not adopted, and is not merely deferred-for-size — re-verification found the true blast radius is materially larger than this document originally scoped, for a reason the original design did not anticipate:

1. **The tenant-context bootstrap hazard.** `TenantContextService::resolveFirm()` — the single bootstrap hop every `runWithFirmContext($firmId, ...)` call funnels through when passed a bare id/uuid rather than a pre-loaded `Firm` object — issues a raw `Firm::query()->findOrFail($firm)` **before** `app.current_firm_id` is set. If `firms` carried this document's proposed policy (`id = current_setting('app.current_firm_id')::bigint`), that exact call would see zero rows during the very operation meant to establish tenant context — breaking `runWithFirmContext()` application-wide (192 call sites, many passing a bare id/uuid), not merely platform-admin cross-firm reads. A correct fix would require a self-lookup-style bootstrap clause in the policy (the same pattern already used for `firm_users`/`clients`/`payment_requests`/`marketplace_intakes`/`client_portal_invitations`), which this document did not scope or design.
2. **Confirmed blast radius.** 39 platform-admin Filament files perform raw, un-wrapped `Firm::query()`/`find()`/`where()` reads with no per-firm loop and no tenant context (chiefly to populate "Firm" filter dropdowns and resolve uuid↔id). The per-firm-loop pattern used elsewhere in the platform-admin surface (e.g. `PlatformFirmUserDirectoryService`) does **not** constitute the "separate, distinct, audited administrative execution path" this document requires, because it is itself seeded by an unrestricted `Firm::query()->get()` — the loop iterates firms discovered by exactly the kind of read RLS-on-`firms` would remove. No such administrative path exists anywhere in the codebase today; building one was out of scope for a narrow completion pass.
3. **Compensating controls already in force.** `firms` carries only administrative/organizational metadata (name, legal_name, customer_type, deployment_mode, region/timezone/currency, activation_status) — no secrets, no client-privileged legal content, no trust/financial data. Every genuinely sensitive tenant-owned table (167+, per `RowLevelSecurityCoverageMappingService::forcedTables()`) remains under FORCE RLS regardless of `firms`' own RLS status. Every platform-admin surface reading `firms` already requires an independent `PlatformStaffAccessPolicyService` role gate before it can reach the query at all. `FirmResource`'s own docblock already documents `firms`' un-scoped `->query()` table as an intentional consequence of `firms` being the tenancy root, not an oversight.

Given (1)-(3), this is recorded as a permanent architectural decision, not a rolling "too large this pass" punt. Revisiting it would require, at minimum: designing and shipping the self-lookup bootstrap clause for `resolveFirm()`, migrating all 39 identified call sites to a genuine audited administrative execution path, and the five proof tests already specified above — a dedicated mission in its own right, not a narrow hardening pass.
