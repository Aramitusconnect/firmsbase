# RLS and Tenancy

## 1. Counts (verified against live migration source at HEAD)

- **12 DirectTenant tables under FORCE ROW LEVEL SECURITY**, all with the canonical tenant-isolation policy shape, **plus 1 documented deviation** (`integration_oauth_states`, which carries one additional narrow policy beyond the canonical shape).
- **4 platform-owned tables with no RLS at all** (`integration_providers`, `integration_webhook_routing_index`, `integration_webhook_receipts`, `integration_platform_overview_summaries`) — each has no `firm_id` column, by design.

## 2. The canonical policy

Every FORCE-RLS table in this framework gets exactly this policy shape (verified byte-for-byte against `database/migrations/2026_09_02_020002_prepare_row_level_security_and_force_rls_on_firm_integrations_table.php` as the representative example, and cross-checked against the other 10 canonical activations):

```sql
ALTER TABLE "<table>" ENABLE ROW LEVEL SECURITY;

CREATE POLICY "<table>_tenant_isolation"
ON "<table>"
USING (
    firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
)
WITH CHECK (
    firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
);

ALTER TABLE "<table>" FORCE ROW LEVEL SECURITY;
```

One combined, symmetric, implicit `FOR ALL` policy per table — no separate read/write policies. This is the proven shape this codebase has used for all of its forced tables (the migration docblocks cite the repository's own Wave 11 `webhook_subscriptions` activation as precedent). The 11 tables using exactly this shape, unmodified: `firm_integrations`, `integration_credentials`, `integration_sync_runs`, `integration_sync_items`, `integration_external_mappings`, `integration_sync_cursors`, `integration_conflicts`, `integration_outbox_events`, `integration_inbound_webhook_events`, `integration_connection_health`, `integration_usage_records`.

## 3. The one documented deviation: `integration_oauth_states`

`integration_oauth_states` (`database/migrations/2026_09_04_040002_prepare_row_level_security_and_force_rls_on_integration_oauth_states_table.php`) carries the canonical policy above **plus one additional, narrow, `FOR SELECT`-only policy**:

```sql
CREATE POLICY "integration_oauth_states_self_lookup"
ON "integration_oauth_states"
FOR SELECT
USING (
    initiating_user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint
);
```

**Why this exists**: the OAuth callback lookup must happen *before* any firm context can be established — the browser returns from the provider carrying only an opaque `state` value, with no `firm_id` anywhere in that request. This carve-out is what lets the callback resolve its own pending state row using only the caller's authenticated user identity.

**Why it's safe** (independently verified against the migration's own docblock and cross-checked for the properties that make this class of carve-out safe rather than dangerous):

- **`FOR SELECT` only** — PostgreSQL never consults a `FOR SELECT`-only permissive policy for INSERT/UPDATE/DELETE, so it structurally cannot widen write authorization. This mirrors the codebase's proven `firm_users_self_lookup` precedent, including the specific bug class that precedent's own docblock documents fixing (an earlier version that OR'd a self-lookup clause into the same `USING` expression as the base policy also silently widened `WITH CHECK`, since Postgres defaults `WITH CHECK` to the same expression when none is given separately — this migration avoids that by using a fully separate policy object with no `WITH CHECK` clause at all).
- **Scoped by caller identity**, not by any row attribute or request-suppliable value — `app.current_user_id` is a session setting only an authenticated user's own session can populate.
- **Narrow predicate** — returns only rows where `initiating_user_id` exactly equals the caller's own id; a caller cannot vary this to enumerate another user's row.
- **Not the sole predicate application-side** — `IntegrationOAuthStateService::resolveAndConsume()` additionally filters on `opaque_token_hash` itself in application code, so a user with multiple in-flight connect/reauthorize flows cannot have an arbitrary one of their own pending states returned instead of the specific one their callback is actually continuing. That is a functional-correctness requirement layered on top of, not a substitute for, this policy's tenant-isolation guarantee.
- Multiple permissive policies for the same command combine with `OR` in PostgreSQL — a session with real firm context active continues to see exactly what the base policy alone would show it; the self-lookup policy only ever *widens* what a session with only `app.current_user_id` set (no firm context at all) may `SELECT`, and only to that one caller's own rows.

This is a **narrow, reviewed, self-lookup carve-out** — structurally different from, and not to be confused with, a row-attribute-based carve-out (e.g. gating on a `credential_type` value), which would be a rejected pattern in this codebase.

## 4. Known, deliberately-deferred gap (applies to every forced table)

PostgreSQL's row-security semantics exempt foreign-key `ON DELETE CASCADE` actions from row-security policy evaluation entirely. Deleting a `firms` (or, for tables scoped through it, a `firm_integrations`) row will always cascade-delete dependent rows in every forced table listed above, regardless of which tenant's context is currently active. This is identical, expected behavior to every other cascade-on-`firms` table already forced elsewhere in this repository — not a defect specific to this framework.

## 5. Verifying live state

`php artisan security:rls-report` (`App\Console\Commands\RlsSecurityReportCommand`) cross-checks the static coverage registry against the live PostgreSQL catalog (`pg_class`/`pg_policies`) and is the authoritative, real tool for confirming current RLS/FORCE state — never take a documentation snapshot (including this one) as more current than a live run of that command. See [runbooks/rls-policy-mismatch.md](runbooks/rls-policy-mismatch.md).

## 6. What this document does not claim

No composite foreign-key or trigger-based cross-table tenant-consistency enforcement is described here because none exists in this framework beyond the single-column `firm_id` policies above — each policy validates only the row's own `firm_id`, not that any foreign key on the row transitively points to a row belonging to the same firm. This is the same structural limitation documented for other parts of this codebase in `docs/security/cross-firm-pivot-remediation-tasks.md`; nothing in this framework closes or reopens that gap.
