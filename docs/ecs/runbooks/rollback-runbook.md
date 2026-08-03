# Rollback Runbook (ECS Staging)

**Date:** 2026-07-13
**Branch:** `feature/ecs-readiness-foundation`

## Case 1: deployment circuit breaker already rolled back automatically

Every service (`modules/ecs_service`, `enable_deployment_circuit_breaker = true`) automatically rolls back to the previous task definition revision if the new one fails to reach a steady state (tasks crash-looping, failing health checks). **No operator action is required for this case** — confirm via `aws ecs describe-services` that `runningCount` matches the *previous* revision's `desiredCount`, and investigate why the new revision failed before attempting to deploy it again.

## Case 2: deployment succeeded, but the new code is bad (discovered after the fact)

Application-level bug not caught by tests/smoke test, discovered once real traffic hits staging.

1. **No schema change involved** (application-code-only bug): re-deploy the *previous* known-good image digest by registering a task definition revision pointing at it (every prior digest remains pullable from ECR — see the ECR lifecycle policy in [infrastructure-architecture.md](../infrastructure-architecture.md), which keeps the most recent 100 tagged images) and `update-service --force-new-deployment`, exactly like a forward deploy but targeting an older digest. This is the normal case and should be fast.
2. **A migration ran as part of the bad deploy**: **do not** reflexively run `migrate:rollback`. Per [database-migrations.md](../database-migrations.md) "Rollback limitations," the safe response depends on whether the migration was written expand-contract-safe:
   - If the migration only *added* structures (new nullable column/table) and the old application code never referenced them: rolling back the application code (step 1) is sufficient — the unused new column/table is harmless until a forward-fix removes it later.
   - If the migration is not safely reversible in isolation (e.g., it dropped or renamed something the old code needs): this requires a **forward fix**, not a rollback — write a new migration that restores compatibility, test it, and deploy that. This is a human decision point; do not automate past it.

## Case 3: rolling back a worker/scheduler/critical-worker service specifically

Same mechanism as web (step 1 above) — register the previous digest's task definition revision for that specific service, `update-service --force-new-deployment`. Services are independent; rolling back the web service does not require rolling back workers, and vice versa, **unless** the bad deploy included a schema change that only one of them is compatible with — in which case, per expand-contract discipline, that itself indicates the migration wasn't written safely and is the actual bug to fix.

## Case 4: rolling back the migrate task itself

The `migrate` role is a one-off task (`RunTask`), not a service — there is no "previous deployment" of it to roll back to in the ECS sense. See [database-migrations.md](../database-migrations.md) for schema-level rollback reasoning (forward-fix is almost always preferred over `migrate:rollback`).

## Case 5: rolling back or stopping ses-consumer specifically

Same mechanism as Case 3 for a code-level rollback (register the previous digest's `ses-consumer` task definition revision, `update-service --force-new-deployment`). Additionally, to simply **stop** the consumer without rolling back its image (e.g. a suspected upstream SES/SNS issue, or to pause processing during an investigation):

- Set `ses_consumer_desired_count = 0` and `terraform apply` (or `aws ecs update-service --desired-count 0` directly for a faster, out-of-band stop), then restore the count to resume.
- **Do not** delete or truncate `notification_provider_correlations`, `ses_event_receipts`, `platform_notification_correlations`, or `platform_notification_suppressions` — these are durable records, not caches, and their loss defeats the idempotency/correlation guarantees the whole consumer depends on.
- **Do not** purge the SQS queue. Unprocessed events remain safely queued (subject to their own retention/visibility settings) for the consumer to pick up once resumed — purging discards bounce/complaint events permanently, with no way to recover them from AWS afterward.
- **Do not** remove the `PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY` secret while stopping/rolling back `ses-consumer` — the **web** service also requires it (see [iam-matrix.md](../iam-matrix.md)) and removing it would break synchronous password-reset/owner-invitation sends, an unrelated and more severe regression than the one being rolled back.

## Verification after any rollback

1. `/up` and `/readyz` both return 2xx against the staging ALB.
2. `aws ecs describe-services` shows `runningCount == desiredCount` for every affected service, all on the intended (rolled-back) task definition revision.
3. Check [alarm-inventory.md](../alarm-inventory.md)'s alarms are back to `OK` state.
4. Record what happened — commit SHA/digest rolled back from, digest rolled back to, and why — as part of whatever incident/postmortem process the team uses (not defined by this branch).

## What this runbook deliberately does not do

- Automatically trigger on alarm — every rollback here is an operator (or, for Case 1, ECS's built-in circuit breaker) decision, not a fully automated alarm-to-rollback pipeline. Building that is a reasonable future enhancement, explicitly not included in this mission's scope.
- Touch production — this entire runbook is staging-only, consistent with every other document in this branch.
