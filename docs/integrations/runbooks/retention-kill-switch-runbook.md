# Retention Kill Switch Runbook

## Scope

`INTEGRATIONS_RETENTION_SWEEP_FIRM_DATA_ENABLED` (`integrations.retention.sweep_firm_data_enabled`, default `false`) gates exactly 3 firm-data, client/matter-adjacent retention sweeps inside `App\Jobs\RetentionSweepJob`: sync items, sync runs, and resolved conflicts. It does **not** gate the platform-owned webhook-receipts sweep (no client/matter linkage) or the outbox/OAuth-state sweeps — those run regardless of this flag's value. See [configuration.md](../configuration.md) item 18 and [known-limitations.md](../known-limitations.md) KR-01.

## Why this flag exists (read this before touching it)

`RetentionSweepJob`'s firm-data sweeps contain **zero** legal-hold awareness — no `LegalHold`/`legal_hold` reference anywhere in `sweepSyncItems()`/`sweepSyncRuns()`/`sweepResolvedConflicts()`, and no resolution layer exists that could even perform such a check today (`resource_type`+`local_id` → `client_id`/`matter_id` mapping does not exist for this data). This flag defaults `false` specifically to prevent automated, unattended, irreversible deletion of data that might be under active legal hold.

**This flag is a containment lever, not a fix.** Setting it to `false` does not resolve the underlying gap — it prevents the vulnerable code path from running at all.

## Required role

FirmOwner-tier or platform SuperAdmin/PlatformAdmin judgment is required to authorize changing this flag's value in any real environment — this is a legal-risk decision, not a routine operational toggle. This runbook does not itself grant that authority.

## Steady-state expectation

With the flag `false` (the only value ever authorized to date), `RetentionSweepJob`'s firm-data sweep methods log a warning and skip — **this is the expected, intended steady state**, not a failure to be fixed. Do not treat a "sweep skipped, firm-data retention disabled" log line as an incident. See [runbooks/retention-sweep-failure.md](retention-sweep-failure.md) for what an actual sweep *failure* (as opposed to this intended skip) looks like.

## What would be required before this flag could ever be safely set to `true`

1. A legal-hold resolution layer connecting `integration_sync_items`/`integration_sync_runs`/`integration_conflicts` rows back to the `client_id`/`matter_id` they relate to, so `LegalHoldService::checkHold()` (which exists and is used elsewhere in this codebase, but has no caller anywhere in this retention path) can actually be consulted before any delete.
2. That resolution layer wired as a precondition inside `RetentionSweepJob`'s firm-data sweep methods specifically — not merely available elsewhere in the codebase.
3. A human/legal decision explicitly authorizing enabling firm-data retention sweeps, separate from and in addition to the engineering work in (1)/(2).

**None of the above exists today.** Do not build a workaround that flips this flag before all three exist. See [feature-flag-rollout.md](../feature-flag-rollout.md) — Stage 4 (GA) is the earliest proposed rollout stage where this flag would even be considered, and only after the resolution layer ships.

## Verifying current state

```
php artisan tinker --execute="dd(config('integrations.retention.sweep_firm_data_enabled'))"
```

or inspect the deployed environment's `INTEGRATIONS_RETENTION_SWEEP_FIRM_DATA_ENABLED` value directly. There is no Filament UI surface for this flag today — it is a plain environment variable, not an in-app toggle.

## Prohibited actions

- Never flip this flag as part of a routine deploy.
- Never build an ad hoc, one-off legal-hold check as a workaround instead of the real resolution layer described above.
- Never decrypt or inspect underlying client/matter data to manually "check" for holds as a substitute for (1)–(3) above — that is a separate, much larger scope than this flag governs.

## Escalation

Any request to enable this flag in a real environment should be escalated to whoever owns legal-hold policy for the firm/product, not decided unilaterally by an engineer or operator acting on this runbook alone.
