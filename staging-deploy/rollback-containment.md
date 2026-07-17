# Rollback / Containment Procedure (prepared, NOT executed)

> **Superseded (historical record only, do not follow):** `runtime-verification-commands.sh`
> and the `create-service-*.sh` files it called have been deleted. The
> approved workflow is `staging-deploy/00-http-exposure-preflight.sh`
> through `staging-deploy/07-final-runtime-verification.sh`; each of those
> scripts prints its own containment commands on failure.
> `migration-sequence.sh` has also been deleted — the migration it
> describes already completed successfully; see
> `staging-deploy/migration-sequence-historical.md` for the historical
> record. `connectivity-probes.sh` is now a non-executing stub.

Applies to first-deployment failure at any stage of
`runtime-verification-commands.sh`. Does NOT apply to the migration step —
migration failure is handled entirely inside `migration-sequence.sh`'s own
stop condition (no service is ever created if migration fails).

## Principle
Containment first, deletion never as the first move. Task definitions and
CloudWatch logs are evidence; they are never deleted as part of rollback.

## If a runtime service (web/worker/critical-worker/scheduler) fails to
## stabilize or fails its verification step

1. **Set desired count to 0 immediately** (stops new task launches, keeps
   the service resource + its event history intact for diagnosis):
   ```
   aws ecs update-service --cluster firmsbase-staging-cluster --service <name> --desired-count 0
   ```

2. **Capture diagnostics before touching anything else**:
   ```
   aws ecs describe-services --cluster firmsbase-staging-cluster --services <name>
   aws ecs list-tasks --cluster firmsbase-staging-cluster --service-name <name> --desired-status STOPPED
   aws ecs describe-tasks --cluster firmsbase-staging-cluster --tasks <stopped-task-arns>
   aws logs tail /ecs/firmsbase-staging/app --log-stream-names <role>/app/<task-id> --since 1h
   ```

3. **Delete the service only after diagnostics are captured and reviewed**,
   and only if a redeploy will follow (not as a reflex):
   ```
   aws ecs delete-service --cluster firmsbase-staging-cluster --service <name> --force
   ```
   `--force` is required only because desired count may be nonzero at the
   time of deletion in some flows here it will already be 0 from step 1.

4. **Do not deregister the task definition.** Leave it ACTIVE (or let it
   sit as an unused revision) so the exact config that failed remains
   inspectable. Deregistration is optional cleanup, performed only after
   the failure has been root-caused and the evidence (steps 2's output) has
   been preserved elsewhere (ticket, doc, log export) — never as part of
   the incident response itself.

5. **The ECS deployment circuit breaker** (`enable=true, rollback=true`,
   already set in all four `create-service-*.sh` files) will automatically
   roll back a failing *deployment* (i.e., a new task definition revision
   pushed to an already-stable service via `update-service`) on its own.
   This manual procedure is for the **first-ever creation** of a service,
   where there is no prior stable revision to roll back to — the only
   containment available is stopping task launches (step 1) and, if
   necessary, deleting the service (step 3).

## Database rollback (never automatic)

- `php artisan migrate:rollback` (or any migration-reversal command) is
  **never** run automatically by any script in this package.
- If the migration step itself fails (`migration-sequence.sh` exits
  nonzero), no runtime services are created — see that script's own gate.
  This alone contains the blast radius without touching the database again.
- If a migration is later found to have caused a runtime problem (only
  discoverable after runtime services are up and being exercised), rollback
  requires a human-authored, human-reviewed rollback plan specific to that
  migration's schema change, following the expand/contract discipline and
  rollback-limitation notes in `docs/ecs/database-migrations.md` and
  `docs/ecs/runbooks/rollback-runbook.md` (as they exist at commit
  `008866f`). No blanket rollback command is provided here because a safe,
  generic one does not exist for arbitrary schema changes.
- Under no circumstances does any script in this package run migration
  commands using the `firmsbase_app` role — only `firmsbase_migrator` via
  `firmsbase-staging-migrate.json` may write schema changes.

## Order of containment preference (least to most destructive)
1. `update-service --desired-count 0` (reversible, instant, preserves all
   history) — always try this first.
2. Preserve diagnostics (read-only, non-destructive).
3. `delete-service` (destroys the service resource, not the task
   definition or logs — moderately reversible, requires recreation).
4. Task-definition deregistration (only after evidence is preserved
   elsewhere — task definitions are cheap to keep and expensive to lose for
   post-mortem purposes).
5. Database rollback (last resort, human-authored only, never scripted
   here).
