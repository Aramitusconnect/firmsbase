# Cross-Firm Pivot Mismatch Remediation — Registered Wave 3 Task

**Status:** Task registration only, recorded 2026-07-13. **No implementation in this document or in Wave 1.** This is a required task for Wave 3.
**Decision:** the human explicitly reviewed and **rejected treating this risk as acceptable residual risk.** RLS on the parent tables alone is declared insufficient and must not be relied upon as the sole mitigation.

---

## Background

The Section 39A checkpoint (`docs/security/section-39a-post-pilot-critical-rls-checkpoint.md`, §6) first documented a transitive firm-ownership mismatch risk for `matters`, `invoices`, and `payments`: PostgreSQL RLS on each of these tables validates only the row's own `firm_id` column against session context. It cannot, via a single-column policy, verify that a foreign key on that row (e.g. an invoice's `client_id`/`matter_id`) transitively points to a row belonging to the *same* firm. This was proven empirically in 39A-3F/G/H: a raw insert with a correct `firm_id` but a foreign key pointing to a different firm's row **succeeds**, because Postgres foreign-key constraint checks run as the table owner and bypass RLS entirely.

The Wave 0 classification effort identified that this same structural risk applies to three additional tables that were not RLS-in-scope in the original checkpoint, because they have **no `firm_id` column of their own at all** and are scoped only transitively through a parent table:

## Affected tables (complete list)

| Table | Own `firm_id`? | Scoping path | Confirmed via |
|---|---|---|---|
| `matters` | yes | direct | previously documented (39A checkpoint §6) |
| `invoices` | yes | direct, with `client_id`/`matter_id` FKs | previously documented (39A checkpoint §6) |
| `payments` | yes | direct, with `client_id`/`matter_id`/`invoice_id` FKs | previously documented (39A checkpoint §6) |
| `matter_parties` | **no** | transitive via `matter_id` → `matters.firm_id` | `database/migrations/2026_07_05_600016_create_matter_parties_table.php` — migration docblock explicitly states "no firm_id column of its own... Scoped transitively through matter_id -> matters.firm_id... no BelongsToTenant on the model, no RLS policy of its own." |
| `matter_assignments` | **no** | transitive via `matter_id` → `matters.firm_id` | `database/migrations/2026_07_05_600017_create_matter_assignments_table.php` — migration docblock: "no firm_id column of its own; scoped transitively through matter_id -> matters.firm_id, same reasoning as matter_parties." |
| `task_dependencies` | **no** | transitive via `task_id`/`blocked_by_task_id` → `tasks.firm_id` | `database/migrations/2026_07_07_800006_create_task_dependencies_table.php` — migration docblock: "No own firm_id — scoped transitively through task_id." Note this table has *two* task-referencing FKs (`task_id` and `blocked_by_task_id`), both of which must independently resolve to the same firm as each other and as the row itself — a strictly larger surface than the single-FK-mismatch case. |

For `matter_parties` and `matter_assignments`, the risk is two-directional: a row could pair a `matter_id` from firm A with a `party_id`/`user_id` that actually belongs to firm B. For `task_dependencies`, a row could pair a `task_id` from firm A with a `blocked_by_task_id` from firm B, creating a cross-firm dependency edge.

## Required remediation shape

The remediation must **prefer database-enforced tenant consistency** over relying on RLS-on-the-parent-tables alone or on application-level validation alone. Specifically, in order of preference per table (architecturally appropriate choice may differ per table):

1. **Explicit `firm_id` on the pivot, where architecturally appropriate** — adding a direct `firm_id` column to the pivot table itself (e.g. `matter_parties.firm_id`, `matter_assignments.firm_id`) allows a normal single-column RLS policy to apply directly to the pivot, and allows a composite foreign key (see next) to enforce that every FK on the row points to the same firm.
2. **Composite foreign keys** — where the target database/ORM version supports it, a composite FK of the form `(firm_id, matter_id) REFERENCES matters(firm_id, id)` (requiring `matters` to have a compound unique/PK on `(firm_id, id)`) makes a cross-firm mismatch a constraint violation at the database level, not just an RLS gap.
3. **Constraints or triggers where ordinary constraints can't express the invariant** — for cases ordinary composite FKs cannot express (e.g. `task_dependencies` needing both `task_id` and `blocked_by_task_id` to resolve to the same firm as each other, which is a cross-row comparison, not a simple FK target), a `BEFORE INSERT OR UPDATE` trigger (as previously recommended in the 39A checkpoint §7) that looks up each referenced row's `firm_id` and rejects a mismatch is required. This mirrors the precedent already in this codebase for expressing invariants ordinary constraints cannot (e.g. `task_dependencies`' existing `CHECK (task_id <> blocked_by_task_id)` constraint for the self-reference case — the cross-firm case is the same category of problem, one level harder).

**Application-level validation is retained**, not replaced — for clean, immediate error messages at the point of a bad request, rather than surfacing a raw database constraint violation to the end user. The database-level enforcement is the actual security boundary; the application-level check is a UX layer on top of it.

**Required negative tests:** the remediation must include tests proving that cross-firm association is impossible — i.e., an attempt to create a `matter_parties`/`matter_assignments`/`task_dependencies` row (or an `invoices`/`payments`/`matters` row) whose FK(s) point across firms must fail, and fail at the database layer, not merely be discouraged by application code. This follows the same pattern already established by every `*ForceRlsActivationTest.php` file in this codebase: prove the negative case, don't assume it from the positive case passing.

## Why RLS on the parent tables alone is insufficient

This is the core reason this risk was rejected as acceptable residual risk: RLS on `matters`/`invoices`/`payments` (all FORCE-enabled as of the Section 39A-3F/G/H batches) correctly prevents a session from reading or writing a row whose *own* `firm_id` doesn't match context. It does **nothing** to prevent that same session from writing a row with a correct `firm_id` but a foreign key pointing at another firm's row — because FK constraint validation in PostgreSQL runs as the table owner, entirely outside of RLS. This was proven, not assumed, in the 39A-3F/G/H test suites for the three parent tables, and the same structural gap applies identically to the three transitively-scoped pivot tables above, which don't even have RLS in scope today.

## Task registration

- **Registered for:** Wave 3.
- **Severity:** High.
- **Status:** open — registered, not yet implemented.
- **Owning doc for broader gap accounting:** `docs/governance/rls-gap-registry.md`, §3.
