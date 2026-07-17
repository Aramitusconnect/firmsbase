# Migration Sequence — Historical Record (COMPLETED, DO NOT RERUN)

This file replaces `staging-deploy/migration-sequence.sh`, which has been
deleted. It contains **no executable commands** — it is a historical
record of a migration that has already run to completion, kept for audit
purposes only.

## Do not

- rerun the migration;
- roll back the migration;
- modify or delete the recovery snapshot referenced below;
- use migrator credentials (`firmsbase_migrator` / the `database-migrator`
  secret) in any runtime service — runtime services use `firmsbase_app` /
  the `database-app` secret exclusively.

## Verified completed state

- Migration repository exists.
- Migration files: **275**.
- Migrations recorded as run: **275**.
- Pending migrations: **0**.
- Migrator role used: **`firmsbase_migrator`**, via the
  `database-migrator` Secrets Manager secret — never the `database-app`
  secret used by runtime services.
- Post-migration verification: **exit code 0**.
- Recovery snapshot ID: **`firmsbase-staging-db-pre-migration-20260716-055138`**.

## Not recorded in this repository

The exact ECS task ARN and the exact registered task-definition revision
ARN for the migration run that produced the state above were **not**
captured in this repository or in this session, and are **not fabricated
here**. If those specific identifiers are needed for audit, retrieve them
from AWS CloudTrail or ECS task history directly — do not infer or
reconstruct them from this document.

## Historical procedure (reference only, never executed by any script in this repository)

The deleted `migration-sequence.sh` registered exactly one task
definition (`firmsbase-staging-migrate.json`, command `["migrate"]`,
using the migrator role/secret only), ran it once as a standalone Fargate
task (never a service), waited for it to stop, and required all of the
following before any runtime service could be created:

1. The task's container exit code was exactly `0`.
2. The task's `stopCode` was a normal `EssentialContainerExited`, not a
   task-level failure such as `ResourceInitializationError`.
3. The tailed CloudWatch log showed migration success text (`Migrating:`
   / `Migrated:` / `Nothing to migrate`) with no exception stack trace.
4. No RLS, permission-denied, or must-be-owner-of-relation error text
   appeared anywhere in the tailed log.

Per the verified completed state above, this gate passed. The runtime
registration workflow (`staging-deploy/01-register-runtime-task-definitions.sh`
through `staging-deploy/07-final-runtime-verification.sh`) registers only
the web, critical-worker, worker, and scheduler task definitions — it
never registers or runs the migrate task definition again.
