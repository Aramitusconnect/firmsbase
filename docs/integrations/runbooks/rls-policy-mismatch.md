# Runbook: RLS Policy Mismatch

## Symptom

Suspicion or confirmation that a table's live PostgreSQL RLS/FORCE state, or its policy definition, does not match what this documentation (or the migration history) says it should be.

## Real source involved

`App\Console\Commands\RlsSecurityReportCommand` (`security:rls-report`) — cross-checks the static coverage registry against the live PostgreSQL catalog (`pg_class`/`pg_policies`). This is the real, existing, correct tool for this scenario — not a new one built for this runbook.

## Required role

Platform-plane: SuperAdmin/PlatformAdmin/ImplementationSpecialist, or engineering directly with appropriate database access.

## Approved interface

```
php artisan security:rls-report
```

Run against the environment in question. This is the authoritative, current-state source — treat any documentation snapshot (including [rls-and-tenancy.md](../rls-and-tenancy.md) in this very tree) as potentially stale relative to a fresh run of this command.

## Steps

1. Run `security:rls-report` and compare its output against the expected state: 12 DirectTenant FORCE-RLS integration tables with the canonical policy shape, plus the one documented `integration_oauth_states` self-lookup deviation (see [rls-and-tenancy.md](../rls-and-tenancy.md) §§1–3), and 4 platform-owned tables (`integration_providers`, `integration_webhook_routing_index`, `integration_webhook_receipts`, `integration_platform_overview_summaries`) correctly showing no RLS.
2. If the report confirms a genuine mismatch (a table missing FORCE, a policy with unexpected predicate text, an extra undocumented policy, or a permissive bare-`true` policy anywhere): this is a critical finding — do not attempt an in-place manual `ALTER`/`CREATE POLICY` fix outside a reviewed migration. Escalate to engineering for a proper migration-based correction, following this codebase's established migration shape (see any `..._prepare_row_level_security_and_force_rls_on_..._table.php` migration as the template).
3. If the report shows everything matches: the mismatch was a documentation staleness issue or a misunderstanding, not a real database-state problem — correct the documentation is out of scope for an operational runbook response (flag it separately).
4. Cross-check specifically for a forbidden pattern: any policy carve-out scoped by a row *attribute* (e.g. a `credential_type = '...'` condition) rather than by caller identity — this class of carve-out is explicitly rejected in this codebase's own design discipline (see [rls-and-tenancy.md](../rls-and-tenancy.md) §3) and its presence would itself be the defect.

## Prohibited actions

Manually running `ALTER TABLE ... FORCE ROW LEVEL SECURITY` or `CREATE POLICY`/`DROP POLICY` directly against a live database as an ad hoc fix — every RLS policy change in this codebase goes through a reviewed migration, never a manual DDL statement outside that process, even to "quickly fix" a confirmed gap. Using `SET ROLE`/BYPASSRLS to explore the mismatch (see [suspected-cross-firm-access.md](suspected-cross-firm-access.md) — the same prohibition applies here).

## Evidence to capture

Full `security:rls-report` output, environment/database identifier, timestamp, specific table(s) and predicate(s) involved in the mismatch.

## Escalation condition

Any confirmed live mismatch — missing FORCE, altered predicate, extra policy, permissive policy — is a same-day critical engineering escalation. This overlaps with, but is more specific than, [suspected-cross-firm-access.md](suspected-cross-firm-access.md) (which starts from a suspected *access* event; this runbook starts from a suspected *policy-state* discrepancy — either can lead to the other).

## Recovery verification

`security:rls-report` re-run clean after any migration-based fix is deployed, plus a dedicated negative-case test proving the specific gap is closed (matching the `*ForceRlsActivationTest.php` pattern — see [testing.md](../testing.md)).
