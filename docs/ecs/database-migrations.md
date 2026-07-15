# Database Migration Strategy

**Date:** 2026-07-13
**Branch:** `feature/ecs-readiness-foundation`

## Migrations never run automatically

No ECS task — web, worker, scheduler — runs `php artisan migrate` as a side effect of starting. `docker/entrypoint.sh` explicitly does not run migrations (see [container-architecture.md](container-architecture.md) "Entrypoint behavior," step 2). The **only** way a migration runs is the dedicated `migrate` one-off task (`docker/commands/migrate.sh`, `infrastructure/ecs/environments/staging/main.tf`'s `module.migrate`, `create_service = false`), invoked explicitly via ECS `RunTask` — by a human operator or by the CI/CD pipeline's deploy step (see [ci-cd pipeline](../../.github/workflows/ecs-pipeline.yml)), never automatically by a web/worker task starting or scaling.

## `docker/commands/migrate.sh` behavior

```
php artisan migrate:status --no-interaction || true   # logged for audit trail, failure here doesn't abort
php artisan migrate --force --no-interaction            # the actual migration; exec'd so its exit code is the task's exit code
```

`--force` is required outside `local`/`testing` environments (Laravel refuses to run migrations in production-like environments without it — a built-in safety rail this script deliberately keeps rather than working around). The task's exit code is the migration's exit code — ECS `RunTask`'s reported `exitCode`/`stopCode` on the task is directly what the CI/CD pipeline gates the rest of the deploy on (see [ci-cd pipeline](../../.github/workflows/ecs-pipeline.yml) "migration result" evidence field).

## Migration lock behavior

Laravel's migrator uses a database-level lock (a row in the `migrations` table's locking mechanism, active by default since Laravel 8) to prevent two concurrent `migrate` invocations from racing. This matters specifically because the `migrate` ECS task is a `RunTask`, not a service — nothing prevents an operator or a mis-triggered pipeline step from launching it twice concurrently. Laravel's own lock is the safety net; this branch does not add a second, redundant application-level lock on top of it.

## Timeout and failure behavior

- ECS task `stopTimeout` for the migrate role: 120s (see [graceful-shutdown.md](graceful-shutdown.md)) — long enough for the migration lock/connection teardown to complete cleanly if the task is ever stopped mid-run, though a migration task is not expected to be manually stopped under normal operation.
- No PHP-level execution timeout is set for the migrate role specifically (unlike the web/worker roles' `max_execution_time`/`--timeout`) — a schema migration can legitimately take longer than a typical web request, and artificially timing it out mid-`ALTER TABLE` is a worse failure mode than letting it finish. The bound instead is ECS's own task-level limits and operator judgment (a migration task running unexpectedly long is itself alarm-worthy — see [alarm-inventory.md](alarm-inventory.md) `<role>-running-count-low`-style reasoning, though no dedicated "migration taking too long" alarm exists today — flagged as a follow-up in [staging-readiness-report.md](staging-readiness-report.md)).
- Non-zero exit code (migration failure) must **stop the deploy pipeline** before it proceeds to update the web/worker/scheduler services to the new image digest — see [ci-cd pipeline](../../.github/workflows/ecs-pipeline.yml). A failed migration must never be followed by deploying application code that assumes the migration succeeded.

## Rollback limitations

Laravel's `migrate:rollback` exists and works for schema that was written with a correct `down()` method, but this branch does not build an automated rollback-on-failure pipeline step, for the same reason application migrations in general should be additive-first (see "Expand-contract discipline" below): **the safe response to a failed migration is almost never "immediately roll back the schema."** A migration that failed partway through can leave the schema in a state `down()` wasn't written to reverse from (e.g., a `down()` written assuming the `up()` fully completed). The safer default is: stop the deploy, do not promote the new application image, investigate, and decide the specific remediation (forward-fix migration, manual intervention, or a deliberate, reviewed rollback) — not an automatic `migrate:rollback` triggered by CI. This is a human-in-the-loop decision point, documented as such in [staging-readiness-report.md](staging-readiness-report.md) rather than automated away.

## Expand-contract discipline (required practice, not enforced by tooling)

Because web/worker tasks deploy independently from the migration task, and an ECS rolling deployment briefly runs **old and new task revisions simultaneously** (see [graceful-shutdown.md](graceful-shutdown.md) "Deployment drain behavior"), every migration must follow expand-contract:

1. **Add** new compatible structures first (new nullable column, new table) — never rename or drop anything in the same migration that adds something, and never add a `NOT NULL` column without a default in the same step application code isn't yet writing to it.
2. **Deploy code that supports both the old and new form** — the running application must work correctly whether it's talking to the pre-migration or post-migration schema, because during a rolling deploy both old and new task revisions are live against the same database simultaneously.
3. **Backfill** existing rows to the new form (a separate migration or a queued job — see [queue-and-redis-architecture.md](queue-and-redis-architecture.md) `low-priority` queue for a good home for a backfill job on a large table).
4. **Stop using the old form** in application code (a code deploy, not a migration).
5. **Remove the old form later** (a separate, later migration — dropping a column/table only after confirming no running task revision anywhere still reads it).

This branch does not audit whether *existing* migrations in the repository already follow this discipline — that's a pre-existing-codebase question outside this mission's boundary (containerization/infra readiness, not an audit of migration history). It documents the discipline every *future* migration must follow now that deploys are rolling/zero-downtime via ECS, which is a meaningfully different deployment model than however the EC2 host currently deploys (likely a single-instance in-place deploy with a brief full-stop, where old/new code never run simultaneously against the same schema — worth confirming with whoever owns the current EC2 deploy process, flagged in [staging-readiness-report.md](staging-readiness-report.md)).

## RLS rollout overlap

This mission's boundaries explicitly forbid independently modifying `TenantContextService`, RLS policies, FORCE RLS migrations, or tenant ownership registries (see mission statement). Nothing in this document or the `migrate` ECS task changes how RLS migrations execute — they run through the exact same `php artisan migrate --force` path as any other migration. The one place this branch's work and the RLS rollout interact at all:

- The `migrate` ECS task runs with the `task-migrate` IAM role (see [iam-matrix.md](iam-matrix.md)), which has **no S3/document grant and no elevated database permission beyond what the `DB_USERNAME` configured in its environment already has** — this branch does not grant the migration task role any Postgres-superuser-equivalent AWS-side permission; the actual Postgres role/privileges used to run migrations (and whether that role can `ALTER TABLE ... FORCE ROW LEVEL SECURITY`) is a database-level concern set by `DB_USERNAME`/`DB_PASSWORD`, not by this ECS/IAM layer, and is unchanged by this branch.
- If containerization is ever found to require a tenant-context code change (it hasn't been — see [ec2-dependency-audit.md](ec2-dependency-audit.md) §17), this mission's rule is to document it as a dependency for the tenant-isolation mission rather than changing it here. No such dependency was found.

## Schema backup and restore

Not implemented by this branch — `app/Services/BackupRestore/FakeBackupRestoreDrillRunner.php` is the only existing implementation and is explicitly fake/simulated (see [ec2-dependency-audit.md](ec2-dependency-audit.md) §16). RDS automated backups/snapshots (AWS-managed, independent of application code) are the real backup mechanism and are a required input this branch does not decide — retention window, snapshot-before-migration policy, and restore-testing cadence are tracked as **requires human approval** in [staging-readiness-report.md](staging-readiness-report.md). The one concrete recommendation: take a manual RDS snapshot immediately before the *first* migration task run against a new staging database, and before any migration that drops or destructively alters a column/table (step 5 of expand-contract above), as a cheap, deliberate checkpoint — not a substitute for a real backup policy decision.
