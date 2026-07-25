# Runbook: Platform Admin Emergency MFA Reset

## Symptom / use case

A platform administrator — most critically the SOLE active SuperAdmin — has lost their authenticator app device AND their recovery codes, and cannot pass the MFA challenge to log into the `admin` panel. No other active SuperAdmin exists who could run the in-panel reset action on their behalf (if one does exist, use that path instead — see "Prohibited actions" below).

## Real source involved

`App\Console\Commands\PlatformAdminEmergencyMfaResetCommand` (`platform-admin:emergency-mfa-reset`), `App\Filament\MultiFactor\AuditedAppAuthentication`, `App\Services\PlatformAdminAuditEventRecorder::recordConsoleEvent()`.

## Read this before running: what this command actually is

This is a **console-only, password-only** recovery mechanism. It does not itself prove possession of anything — its authority comes entirely from the operator already having direct server/console access (SSH plus deploy credentials, or equivalent), a materially higher trust boundary than the panel's own password+MFA login. It bypasses the panel's MFA challenge entirely for the target account. Treat running it with the same seriousness as any other direct-database-write emergency operation.

It is blocked by default outside `local`/`testing` environments and requires `--confirm-production` to run anywhere else. That flag is a **safety confirmation**, not an authorization check — the command cannot verify from within PHP that the operator is actually authorized; that verification is a human/process responsibility (see "Required role" below).

## Required role

Whoever has legitimate server/console access to run `php artisan` against the target environment, AND is following this runbook's escalation/approval expectations (see "Escalation condition"). There is no in-application role check — console access is the gate.

## Approved interface

`php artisan platform-admin:emergency-mfa-reset {email} --reason="..." [--confirm-production]`

- `{email}` — the target platform administrator's email.
- `--reason=` — required (prompted for interactively if omitted); always recorded in the audit trail.
- `--confirm-production` — required outside `local`/`testing` environments.

Do not call `AuditedAppAuthentication::saveSecret()`/`saveRecoveryCodes()` or write directly to `platform_admins.two_factor_secret`/`two_factor_recovery_codes`/`two_factor_reset_at` via `php artisan tinker` or a raw SQL update instead of this command — doing so skips the audit write entirely (a genuinely silent path) and skips the `two_factor_reset_at` stamp that forces the target's current session to log out immediately.

## Steps

1. Confirm this is genuinely the sole-SuperAdmin-locked-out scenario (or an equivalent case with no other available in-panel path) — see "Prohibited actions" if a normal in-panel reset is possible instead.
2. Get explicit approval per your organization's break-glass process before running this in any non-local environment (this runbook does not define that process — it is external to this command).
3. Run `php artisan platform-admin:emergency-mfa-reset {email} --reason="<why>"` (add `--confirm-production` outside local/testing).
4. Confirm the command reports success and inspect the `security_events` row it wrote (see "Evidence to capture").
5. Have the target log in with their password; they will be forced through Filament's normal enrollment flow (`EnsurePlatformAdminMfaIsEnrolledAndVerified`'s enrollment-check step) and must set up a new authenticator app + recovery codes before reaching anything else.
6. If the target had an active session at the time of the reset, confirm it was forced to log out on its next request (the `two_factor_reset_at` stamp is what does this — no separate step required).

## Prohibited actions

Running this command when a normal in-panel reset is available — i.e. another active SuperAdmin exists and can run `App\Filament\Actions\Platform\ResetPlatformAdminMfaAction` from the Platform Administrators resource instead. That path requires an authenticated, MFA-verified SuperAdmin actor and produces a `mfa_reset_by_admin` audit row attributed to a real `PlatformAdmin`; this command's `mfa_reset_by_emergency_command` row is attributed to `actor_type = 'console'` with no `PlatformAdmin` actor at all, since none is assumed to exist in the scenario this command exists for. Prefer the stronger-attribution path whenever it is actually available.

Running this against a production-like environment without following your organization's break-glass approval process first, even though the command's `--confirm-production` flag will technically let you.

Writing directly to `platform_admins`' MFA columns or calling `AuditedAppAuthentication` methods outside this command instead of using it — see "Approved interface" above.

## Evidence to capture

- The `security_events` row this command wrote: `actor_type = 'console'`, `actor_id = NULL`, `event_type = 'mfa_reset_by_emergency_command'`, `category = 'platform_admin_mfa'`, `firm_id = NULL`. Its `metadata` JSON carries `target_platform_admin_id`/`target_platform_admin_uuid`, the `reason` given, the `environment` the command ran in, and best-effort `os_user`/`hostname` values (these are a supplementary signal only — see "Read this before running").
- Server/SSH access logs for who actually ran the command and when (the authoritative record of operator identity — the `security_events` row's `os_user`/`hostname` metadata is best-effort only and should never be treated as sufficient attribution on its own).
- The approval/escalation record from your organization's break-glass process (external to this command).
- Confirmation the target successfully re-enrolled afterward.

## Escalation condition

Any use of this command outside a documented, pre-approved break-glass process. Any use where an in-panel reset was actually available (see "Prohibited actions"). Repeated use against the same account in a short window. Any `--confirm-production` run without a corresponding approval record.

## Recovery verification

The target's `platform_admins.two_factor_secret`/`two_factor_recovery_codes` are cleared and `two_factor_reset_at` is set to the reset time; any session the target had open at reset time is denied on its next request and redirected to login; the target can log in with their password and is routed straight into Filament's forced-enrollment flow with nothing else reachable; the `mfa_reset_by_emergency_command` audit row exists and its metadata is complete and consistent with the approved break-glass request.
