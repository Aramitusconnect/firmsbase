# `firms` Root Tenant Table — RLS Policy Design Record

**Status:** Design decision recorded 2026-07-13. **No implementation yet.** This document is a design record for whichever future agent/task implements this policy — likely Wave 3/4.
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
