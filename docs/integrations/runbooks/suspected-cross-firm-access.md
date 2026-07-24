# Runbook: Suspected Cross-Firm Access

## Symptom

Suspicion that a session (user or operator) accessed, or could have accessed, integration data belonging to a firm other than its own — e.g. a connection, credential, sync item, or conflict row that shouldn't have been visible/mutable from the acting context.

## Real source involved

The 12 FORCE-RLS integration tables and their canonical/deviation policies (see [rls-and-tenancy.md](../rls-and-tenancy.md)), `RlsSecurityReportCommand` (`php artisan security:rls-report`), `FirmIntegrationPolicy`'s defense-in-depth `firm_id` re-checks.

## Global rule — read this before doing anything else

**Never use `SET ROLE` or any BYPASSRLS session "just to check" what another firm's data looks like.** This would defeat the exact control under investigation and is itself a boundary violation if used — even for a well-intentioned investigation. Confirming a suspected RLS gap must be done through the RLS mechanism itself (proving denial), never by stepping outside it to peek.

## Required role

Platform-plane: SuperAdmin/PlatformAdmin/ImplementationSpecialist. This is not a SupportAgent-delegable investigation given its sensitivity — a cross-firm access suspicion is itself a potential governance-boundary event, and investigating it should go through the highest-trust roles in this framework's platform tier.

## Approved interface

`php artisan security:rls-report` (`RlsSecurityReportCommand`) — cross-checks the static coverage registry against the live PostgreSQL catalog (`pg_class`/`pg_policies`). This is the correct, real tool for confirming current RLS/FORCE state; see [rls-policy-mismatch.md](rls-policy-mismatch.md) for its full use. `platform_integration_oversight`-category `security_events` review (via `IntegrationPlatformOversightReadService`) for reviewing what platform-plane accesses actually occurred and by whom.

## Steps

1. Do not attempt to reproduce or "verify" the suspected cross-firm access by actually performing a cross-firm read/write yourself, even in a test/staging context, without explicit authorization — this risks the exact harm being investigated.
2. Run `security:rls-report` to confirm every FORCE-RLS table's policy state matches the canonical shape (or the one documented `integration_oauth_states` deviation — see [rls-and-tenancy.md](../rls-and-tenancy.md)) and that FORCE is actually enabled at the database level, not merely intended in migration history.
3. Review `platform_integration_oversight` security-events for the time window in question — this is the authoritative attribution category (see [operations-superadmin.md](../operations-superadmin.md) §5; do not rely on `support_access` category attribution alone for this investigation, given its known misattribution issue for cross-actor actions).
4. If the suspected access was through the OAuth self-lookup carve-out on `integration_oauth_states` (the one documented policy deviation), confirm the specific row(s) involved genuinely matched the caller's own `initiating_user_id` — the policy is narrow and scoped to caller identity, not row attributes, so a legitimate self-lookup should not be mistaken for a cross-firm leak. See [rls-and-tenancy.md](../rls-and-tenancy.md) §3.
5. If `security:rls-report` reveals an actual live policy gap (not merely a suspicion but a confirmed missing/misconfigured policy), this is a critical, same-day engineering escalation — see [rls-policy-mismatch.md](rls-policy-mismatch.md).

## Prohibited actions

`SET ROLE`/any BYPASSRLS session for investigation purposes. Decrypting credentials as part of this investigation (see [suspected-secret-exposure.md](suspected-secret-exposure.md) — a separate, non-overlapping prohibition that still applies if credentials are in scope). Assuming a gap exists without running `security:rls-report` to confirm live state — documentation (including this tree) can be stale; the live database catalog is the only authoritative source.

## Evidence to capture

Suspected firm(s) involved, suspected actor/session, `security:rls-report` output, relevant `platform_integration_oversight` security-events entries, timestamp range.

## Escalation condition

Any confirmed (not merely suspected) cross-firm access is a critical, immediate security escalation — treat as a potential incident requiring the product's standard incident-response process, not merely this runbook's own steps.

## Recovery verification

`security:rls-report` clean; if a real gap was found and fixed, a dedicated negative-case test (matching the `*ForceRlsActivationTest.php` pattern — see [testing.md](../testing.md)) proving the specific cross-firm access is now denied.
