# Runbook: Retention Sweep Failure

## Symptom

`integrations:retention:sweep` (daily, `withoutOverlapping()`) or the `RetentionSweepJob` it dispatches per firm fails, errors, or produces unexpected results — distinct from the *expected* firm-data-sweep skip described in [retention-kill-switch-runbook.md](retention-kill-switch-runbook.md) (that is a designed no-op, not a failure).

## Real source involved

`App\Console\Commands\SweepIntegrationRetentionCommand`, `App\Jobs\RetentionSweepJob`, `RetentionSweepAuditLogger`, `PlatformFirmIntegrationBoundedAccessService::previewRetentionSweepDryRun()`.

## Diagnosis

`RetentionSweepJob` sweeps, in order, sync items, sync runs, outbox events, OAuth states, resolved conflicts, then processed webhook events (redact-then-delete) — each batch in its **own** transaction via `TenantContextService::runWithFirmContext()`, called once per batch, never once per firm (see [jobs-and-scheduler.md](../jobs-and-scheduler.md) §3). This means a genuine failure partway through a firm's sweep leaves already-completed batches committed and only the in-flight batch rolled back — **not** an all-or-nothing failure for the whole firm. Distinguish:

- **A single batch error** (e.g. a transient database issue mid-batch): the next day's scheduled run will pick up where eligibility naturally leaves off — no special recovery action needed beyond confirming the next run succeeds.
- **A systemic error** (e.g. every firm's sweep failing the same way): indicates a real defect, escalate to engineering.
- **The `oauth_state_unconsumed_cleanup_not_configured` log line**: this is an intentional no-op, not a failure — `integrations.oauth_states.unconsumed_expired_retention_hours` deliberately has no default (see [configuration.md](../configuration.md) item 7); this line means a human has not yet configured it, which is the correct fail-safe default state.
- **The firm-data-sweep-skip warning**: also an intentional no-op when `INTEGRATIONS_RETENTION_SWEEP_FIRM_DATA_ENABLED` is `false` — see [retention-kill-switch-runbook.md](retention-kill-switch-runbook.md). Note this warning currently goes to the **default log stack**, not a dedicated retention log channel — a disclosed consistency gap worth knowing when searching logs, not itself a defect to fix operationally.

## Required role

Platform-plane investigation: SuperAdmin/PlatformAdmin/ImplementationSpecialist, or SupportAgent+ with active support-access session for a per-firm-scoped investigation.

## Approved interface

`PlatformFirmIntegrationBoundedAccessService::previewRetentionSweepDryRun(PlatformAdmin $admin, Firm $firm)` — dispatches `RetentionSweepJob::dispatch($firm->id, dryRun: true)`, a **zero-mutation preview only**. This is the one operator-facing tool for this scenario. **The live (non-dry-run) trigger is explicitly out of scope for direct operator invocation** — there is no "manually run a real sweep right now for this firm" button; the real sweep runs only on its scheduled `daily()` cadence via `SweepIntegrationRetentionCommand`.

## Steps

1. Determine whether the observed behavior is a genuine failure or one of the two intentional no-ops described above.
2. For a genuine failure, use `previewRetentionSweepDryRun()` to see what the sweep *would* do for the affected firm without mutating anything — useful for understanding scope/impact before the next real scheduled run.
3. Check `RetentionSweepAuditLogger` entries for the affected firm/date to see how far the sweep progressed before failing.
4. Do not manually trigger a real sweep outside the scheduled cadence — no such operator action exists, by design (the retention sweep is not something to run ad hoc on demand for arbitrary reasons).
5. If the platform-owned webhook-receipts sweep (run directly, synchronously, by `SweepIntegrationRetentionCommand` itself, not via a per-firm job) fails, this is a platform-wide concern, not firm-specific — escalate directly.

## Prohibited actions

Manually deleting rows from any swept table to "help" a stuck sweep along. Triggering a real (non-dry-run) sweep outside its scheduled cadence. Working around the `oauth_state_unconsumed_cleanup_not_configured` or firm-data-sweep-skip no-ops by inventing a value or flipping the kill switch without following [retention-kill-switch-runbook.md](retention-kill-switch-runbook.md)'s own discipline.

## Evidence to capture

Firm id(s) affected, `RetentionSweepAuditLogger` entries, which of the 6 sweep stages was in progress at failure, dry-run preview output if used.

## Escalation condition

Any failure affecting multiple firms identically, or any failure in the platform-owned webhook-receipts sweep, is a same-day engineering escalation. An isolated single-firm, single-day batch error that self-resolves on the next scheduled run does not require escalation.

## Recovery verification

Next scheduled `integrations:retention:sweep` run completes for the affected firm without repeat failure; `RetentionSweepAuditLogger` shows all 6 stages completing normally (or intentionally no-op'd, per the two documented cases above).
