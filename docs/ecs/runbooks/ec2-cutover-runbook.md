# EC2 → ECS Cutover Runbook (Production — Planning Document Only)

**Date:** 2026-07-13
**Branch:** `feature/ecs-readiness-foundation`

**This runbook is not executed by this mission.** This branch does not provision production infrastructure, does not change production DNS, does not shift production traffic, and does not disable any EC2 service (see mission boundaries). This document exists so that when a production cutover is later authorized, there is a reviewed sequence to follow rather than an improvised one — and so [staging-readiness-report.md](../staging-readiness-report.md) can point at something concrete when it lists "what's still required."

## Prerequisites before this runbook can start (none met by this branch)

- [ ] Staging has run on ECS successfully for a meaningful soak period (team-defined; not decided here).
- [ ] A production `infrastructure/ecs/environments/production/` Terraform root module exists and has been reviewed (see `environments/production/README.md` — does not exist yet).
- [ ] Production-sized RDS/Redis decisions have been made and provisioned (see [infrastructure-architecture.md](../infrastructure-architecture.md) "hardening follow-ups" and [staging-readiness-report.md](../staging-readiness-report.md) "required database/Redis decisions").
- [ ] Production ACM certificate issued and validated for the real production hostname.
- [ ] Production secrets (`APP_KEY` — **must be the same key currently in use on EC2**, not a freshly generated one, or every existing encrypted value/session becomes unreadable — `DB_PASSWORD` for the production database, `REDIS_PASSWORD`) provisioned in Secrets Manager.
- [ ] A human decision on whether production ECS connects to the **same** RDS instance the EC2 deployment currently uses, or a migrated/replicated one — this is a data-continuity decision this document cannot make.
- [ ] Deployment circuit breaker, alarms, and on-call paging (SNS subscription — see [alarm-inventory.md](../alarm-inventory.md)) are live and tested in staging first.

## Planned sequence (once authorized)

1. **Provision production ECS infrastructure** (`terraform apply` against a reviewed `environments/production/`) — cluster, IAM roles, ALB **on a new, not-yet-public hostname or with DNS not yet pointed at it**, ECS services at `desiredCount` sized for production load but **not yet receiving real traffic**.
2. **Deploy the exact digest already running in staging** (never rebuild — the whole point of digest-based promotion, see [container-architecture.md](../container-architecture.md) "Immutable digest promotion" — is that what passed staging is bit-for-bit what goes to production).
3. **Run the migration task against the production database** during a low-traffic window, with the EC2 deployment still fully serving traffic and unaffected (the migration only touches schema; expand-contract discipline, per [database-migrations.md](../database-migrations.md), means the currently-running EC2 code must remain compatible with the post-migration schema until the cutover completes).
4. **Verify the new ECS stack independently**, without customer traffic: smoke tests, a manual QA pass, `/readyz` returning healthy, alarms quiet.
5. **Shift a small percentage of traffic** (weighted DNS or ALB-level canary, depending on what the real DNS/routing setup supports — not decided here) from EC2 to ECS. Watch error rates/latency/alarms closely.
6. **Progressively increase the ECS traffic share** while EC2 continues serving the remainder, watching the same signals at each step.
7. **Full cutover**: DNS points entirely at the ECS ALB. EC2 continues running, fully idle of new traffic, as the immediate rollback target.
8. **Soak period** with EC2 kept warm (not decommissioned) — length is a team decision, not specified here.
9. **Decommission EC2** only after the soak period passes with no rollback needed, and only as its own separate, explicitly authorized action — **this step is never automatic and is not part of any pipeline in this repository.**

## Scheduler single-execution contract (added by cutover-safety hardening)

The schedule previously used `withoutOverlapping()` 18 times and `onOneServer()`
zero times. Those guard different failure modes — the first stops a task
overlapping itself, the second stops it running on two hosts — so during any
window where the EC2 and ECS schedulers both exist, nothing prevented duplicate
execution of financially and operationally consequential work.

`App\Services\Scheduling\ScheduledCommandSingleExecutionContract` now records,
per command, the risk class, whether single-server execution is required, what
atomic protection (if any) sits behind it, and why.
`ScheduledCommandSingleExecutionContractTest` asserts the live schedule matches
it in both directions, so a new scheduled command cannot inherit a
duplicate-execution decision by accident.

**`onOneServer()` is a cache lock, and it is only as good as the store behind
it.** Two hosts exclude each other only when all of these agree:

| Setting | Why |
| --- | --- |
| `CACHE_STORE` | Same driver, supporting atomic locks |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_DB` | Same endpoint and logical database |
| `CACHE_PREFIX` | Same prefix |
| `APP_NAME` | Only because `config/cache.php` derives `CACHE_PREFIX` from it when unset |

`APP_NAME` is the sharp edge: two hosts pointed at the same Redis but with
different `APP_NAME` values produce different lock keys and both run every task,
while looking correctly configured.

Five commands have **no atomic second layer** — they guard duplicates with a
read-then-write existence check, and `domain_events` has no unique constraint to
catch a lost race:

    automation:sweep:invoice-overdue
    automation:sweep:deadlines
    automation:sweep:matter-budgets
    automation:sweep:document-request-reminders
    automation:sweep:leverage-recommendations

For these, scheduler single-execution is the only real defence. The integration
outbox and both automation dispatchers are safer — they claim work with
`FOR UPDATE SKIP LOCKED`.

`scheduler:heartbeat:record` is deliberately **not** single-served: it is how an
operator detects that two schedulers are live. Pinning it to one host would mask
the exact condition this section exists to prevent.

### Required scheduler handoff (no planned overlap)

Even with `onOneServer()` in place, do not run two schedulers on purpose:

1. ECS scheduler service starts at `desiredCount = 0`.
2. Deploy and verify ECS web and workers while the EC2 scheduler still runs.
3. Stop the EC2 scheduler. **Prove it is stopped** — no new
   `scheduler:heartbeat:record` rows from the EC2 host.
4. Scale the ECS scheduler to `desiredCount = 1`.
5. Confirm heartbeats resume from exactly one host.

Rollback is the same sequence reversed. A brief gap in scheduled work is far
cheaper than one duplicated financial sweep.

## Durable storage blocker (added by cutover-safety hardening)

`documents.storage_disk` + `storage_path` is the canonical abstraction and is
disk-agnostic — `ClamAvVirusScanner` already reads through
`Storage::disk($storageDisk)`. One path opts out of it:

`app/Filament/ClientPortal/Pages/PlaidUploadFallbackPage.php` moves a
client-uploaded file to `client-portal-uploads/{firm}/{matter}/{uuid}-{name}` on
**`Storage::disk('local')`** and records `storageDisk: 'local'`. This is
customer-supplied financial evidence on a durable-looking path.

On EC2 this survives because the instance has a persistent disk. On Fargate that
disk is ephemeral: the file disappears when the task is replaced, while the
database row still points at it.

Setting `FILESYSTEM_DISK=s3` does **not** fix this — the disk is hardcoded, not
read from configuration.

This was deliberately **not** patched in the hardening branch. Changing the
target disk sends new uploads to S3 but leaves existing rows pointing at files
that exist only on the EC2 volume, so the code change is safe only as part of a
migration that also relocates those files and rewrites their `storage_disk`.
That migration is out of scope here and must be separately scoped and approved.

**Cutover prerequisite:** relocate existing `storage_disk = 'local'` document
rows to the durable disk and change the fallback page to record the configured
disk, before ECS serves Client Portal traffic.

## Rollback during cutover

At every step from 5 onward, rolling back means shifting DNS/routing back toward EC2 — which remains fully capable of serving 100% of traffic until step 9 is explicitly authorized and executed. This is the entire reason step 9 is deliberately the last, separate, most-delayed step: EC2 is the safety net for every earlier step in this runbook, and remains so until a human decides the ECS deployment has proven itself.

## What this mission built to make this runbook possible later

Everything else in `docs/ecs/` and `infrastructure/ecs/` — the container image, the task definitions, the IAM roles, the alarms, the staging deploy pipeline. This runbook is the one document in the branch that describes work explicitly **not done**, by design, and states plainly what would need to be true before it could be.
