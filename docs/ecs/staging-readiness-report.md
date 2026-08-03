# ECS Staging Readiness Report

**Date:** 2026-07-13
**Branch:** `feature/ecs-readiness-foundation`
**Starting commit:** `cc2cb8a` (`main` and this branch were identical at start — no divergence to reconcile)

This is the top-level summary. Every claim here is backed by a more detailed document linked inline — this report doesn't repeat their reasoning, it classifies and indexes it.

## Classification legend

- **Ready** — done, verified, nothing further needed.
- **Ready with configuration** — code/infra is complete; needs an environment-specific value (a real VPC ID, a real secret) to activate, not a code change.
- **Blocked** — cannot proceed without something this mission cannot supply (real AWS credentials, a human decision, another team's code change).
- **Deferred** — deliberately not built because the thing it would support doesn't exist yet in the application (e.g., real document storage).
- **Requires human approval** — a decision with real cost/risk/compliance weight (data location, retention, production cutover) that this mission should not make unilaterally even if it *could* technically execute it.

## 1. Completed work

| Area | Status | Doc |
|---|---|---|
| EC2 dependency audit | Ready | [ec2-dependency-audit.md](ec2-dependency-audit.md) |
| Container architecture design | Ready | [container-architecture.md](container-architecture.md) |
| Production Dockerfile + entrypoint/command scripts | Ready with configuration (needs a real `docker build` — see §4) | `Dockerfile`, `docker/` |
| Liveness (`/up`) + readiness (`/readyz`) endpoints | Ready — verified end-to-end against real Postgres/Redis (see §4) | `app/Http/Controllers/ReadinessController.php` |
| Storage readiness analysis | Ready (finding: no business data on local disk exists to migrate) | [storage-readiness.md](storage-readiness.md) |
| Redis/queue/session/cache architecture | Ready with configuration | [queue-and-redis-architecture.md](queue-and-redis-architecture.md), [env.ecs.example](env.ecs.example) |
| Graceful shutdown design + partial verification | Ready — signal handling verified for queue worker and scheduler (see §4) | [graceful-shutdown.md](graceful-shutdown.md) |
| Terraform IaC skeleton (16 modules + staging environment) | Ready with configuration — `terraform validate` passes, real AWS credentials needed to `apply` | [infrastructure-architecture.md](infrastructure-architecture.md), `infrastructure/ecs/` |
| IAM least-privilege design | Ready | [iam-matrix.md](iam-matrix.md) |
| Observability + alarms | Ready with configuration (custom-metric alarms deferred, see §3) | [observability.md](observability.md), [alarm-inventory.md](alarm-inventory.md) |
| Database migration strategy | Ready | [database-migrations.md](database-migrations.md) |
| CI/CD pipeline | Ready with configuration — needs real AWS OIDC role/ECR/cluster names as repo variables, and one known tooling-portability gap (see §3) | `.github/workflows/ecs-pipeline.yml` |
| Deployment/rollback/EC2-cutover runbooks | Ready | `docs/ecs/runbooks/` |

## 2. Docker image architecture (summary — full detail in [container-architecture.md](container-architecture.md))

One multi-stage image (`frontend` → `vendor` → `runtime`), base `dunglas/frankenphp` (PHP 8.3 + FrankenPHP single-process web server), non-root `app` user (UID/GID 1000), one `ENTRYPOINT` (`docker/entrypoint.sh`) dispatching to `docker/commands/{web,worker,scheduler,migrate,maintenance}.sh` by command argument. No `.env`, no `.git`, no dev dependencies, no credentials in any layer (`.dockerignore` verified — see §4).

## 3. Unresolved code blockers

| Item | Classification | Detail |
|---|---|---|
| No `Schedule::` entries registered anywhere | **Blocked** on a product decision (not this mission's authority) | `routes/console.php` has zero scheduled commands. The scheduler ECS service will run correctly but has nothing to dispatch. See [ec2-dependency-audit.md](ec2-dependency-audit.md) §3. |
| No CloudWatch custom-metric emission (`PutMetricData`) from `QueueHealthService`/`SchedulerHealthService` | Deferred — application code change, out of this mission's boundary | [observability.md](observability.md). IAM grant and alarm definitions are prepared and gated off (`enable_custom_metric_alarms=false`) until this exists. |
| `aws/aws-sdk-php` / `league/flysystem-aws-s3-v3` not in `composer.json` `require` | Deferred — no code uses S3 yet | [storage-readiness.md](storage-readiness.md). One-line `composer require` when real document I/O is built. |
| `WebhookDestinationValidationService` doesn't re-validate destination at send time (SSRF-relevant, notable now that `169.254.169.254` is the ECS/Fargate IMDS address) | Deferred — pre-existing, application-domain code, flagged for the owning team | [ec2-dependency-audit.md](ec2-dependency-audit.md) §12 |
| CI test job (`tools/rls-test/` reuse) has a hardcoded lock-file path (`/home/ubuntu/firmsbase/rls-checkpoints/...`) not portable to a generic CI runner without a workaround | Blocked on `tools/rls-test/lib.sh` owner making the lock path configurable | `.github/workflows/ecs-pipeline.yml` comment; worked around with a `sudo mkdir` in this branch's pipeline but flagged as fragile |
| Correlation/request ID logging | Deferred — would require a new global middleware, flagged rather than added silently per mission boundary on middleware changes | [observability.md](observability.md) |

## 4. Local verification actually performed (and what could not be)

**No Docker daemon and no Terraform CLI were pre-installed in the sandbox this branch was authored in.** What was done instead:

| Check | Result |
|---|---|
| `php -l` on every new/changed PHP file | Pass |
| `bash -n` on every new/changed shell script | Pass |
| `git diff --check` | Pass (no whitespace errors) |
| `composer validate --strict` | Pass |
| `vendor/bin/pint --test` on changed PHP files | Pass |
| `package.json`/`package-lock.json` JSON validity | Pass |
| **Terraform** (historical, 2026-07-13): downloaded Terraform 1.9.8 directly (network access was available even though the binary wasn't preinstalled), ran `fmt`, `init -backend=false`, `validate` (Success), and `plan` with fake AWS credentials (got past all variable/resource-attribute evaluation, failed only at the real STS credential check — proof nothing could have been created) — this was a valid dry run at the time, back when no backend existed and any Terraform 1.7+ binary satisfied `required_version`. **Terraform 1.9.8 is no longer approved for this environment as of the 2026-08-03 S3 backend configuration** (`versions.tf` now requires `>= 1.15.0, < 2.0.0`, since the backend's `use_lockfile` locking needs 1.11+; the approved binary is the pinned `/home/ubuntu/bin/terraform-1.15.8` install — see `docs/ecs/state-adoption-plan.md` §5 and `scripts/tf-guard.sh`, which now refuses `plan`/`apply` with any binary below 1.15.0). | Pass — two real bugs found and fixed in the process (security-group rule description character restrictions; `auth_token` requiring `aws_elasticache_replication_group` not `aws_elasticache_cluster`) |
| **Readiness endpoint**: created a throwaway, self-cleaned scratch Postgres database (via passwordless local sudo to the Postgres superuser — not the RLS mission's shared test infrastructure, no credentials from `tools/rls-test/` were used or needed) and a local `php artisan serve`, then exercised `/readyz` and `/up` directly over real HTTP | All 4 scenarios verified: DB reachable → 200 `{"status":"ready",...}`; DB unreachable → 503 with generic `"error"` token (no leaked exception detail); Redis configured+reachable → 200 with `"redis":"ok"`; Redis configured+unreachable → 503 with generic `"error"` token, confirmed via an injected fake exception message containing a fake hostname/password that did **not** appear in the response body |
| **Signal handling**: real `php artisan queue:work` and `php artisan schedule:work` processes started, sent `SIGTERM` after reaching their idle loop | Both exited cleanly, exit code 0, in 12-16ms |
| **Entrypoint logic**: fail-fast on missing env vars, unknown-role rejection, valid-role dispatch to the correct command script | All verified against the real script logic (path-adjusted only where the hardcoded `/var/www/html` doesn't exist outside a real container — expected and correct, not a defect) |
| **Docker image build** (`docker build .`) | **Not performed — Docker is not installed in this sandbox.** The Dockerfile has been reviewed line-by-line against documented FrankenPHP/Composer/Laravel conventions but not built. **This is the single largest unverified item in this branch.** |
| **Full PHPUnit suite** (`tools/rls-test/run-artisan-test.sh ... -- test`) | **Not run in this sandbox** — the RLS mission's disposable-database secret (`.rls-test-secrets/rls_test_runner_39a3l.pgpass`) does not exist in this worktree, by design (it's mission-scoped and this branch correctly does not have or create it). The new test file (`tests/Feature/Ecs/ReadinessControllerTest.php`) is `php -l`-clean and its assertions mirror the manually-verified HTTP behavior above, but has not itself been executed by PHPUnit. **Required follow-up**: run it via the approved tooling in an environment that has that secret (or in CI, which provisions its own ephemeral role per §3's caveat) before merge. |

## 5. Unresolved AWS blockers / required inputs

All of the following are **Blocked** (this mission has no real AWS credentials in its environment, deliberately) or **Requires human approval** (a decision, not a technical gap):

| Input | Classification | Where it's consumed |
|---|---|---|
| VPC ID + public/private subnet IDs | Requires human approval | `infrastructure/ecs/modules/networking`; must be an existing VPC, ideally the one the current RDS instance already lives in |
| Existing RDS instance identifier + its security group ID | Requires human approval (is staging sharing production's RDS, or getting its own?) | `infrastructure/ecs/environments/staging/variables.tf` |
| ACM certificate ARN for the staging hostname | Blocked — no DNS name chosen yet, and this mission does not create DNS records | `infrastructure/ecs/modules/alb` |
| Staging hostname / Route 53 record | Requires human approval — this mission explicitly does not touch DNS | Referenced but not created anywhere in this branch |
| `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD` in Secrets Manager | Blocked — no AWS account access | `infrastructure/ecs/modules/iam`, `env.ecs.example` |
| SNS topic ARN for alarm notifications + who subscribes to it | Requires human approval (on-call/paging is an operational decision) | `infrastructure/ecs/modules/cloudwatch_alarms` |
| AWS account, region, and an OIDC-federated deploy role for CI | Requires human approval | `.github/workflows/ecs-pipeline.yml` |
| ~~Terraform remote state backend (S3+DynamoDB or Terraform Cloud) — requires human approval~~ **Resolved 2026-08-03**: approved and configured as S3 with native lockfile locking (bucket `firmsbase-terraform-state-603013471426-us-east-1`, key `environments/staging/ecs/terraform.tfstate`, no DynamoDB table) | Configured — code-only; state prefix confirmed empty, no `terraform init` has been run against it, `plan`/`apply` remain blocked pending the import checkpoints in `docs/ecs/state-adoption-plan.md` §8 | `infrastructure/ecs/environments/staging/versions.tf` |

## 6. Required database decisions

- Whether staging uses a copy/snapshot of production data, a synthetic dataset, or a genuinely separate empty schema — **requires human approval**, has real data-sensitivity implications this mission should not decide (mission boundary: "do not onboard firms").
- RDS instance class/sizing for staging vs. eventual production — **requires human approval**.
- Backup/snapshot retention policy — **requires human approval**, see [database-migrations.md](database-migrations.md) "Schema backup and restore."

## 7. Required Redis decisions

- `cache.t4g.micro` single-node default in this branch's Terraform is a **Ready with configuration** starting point for staging specifically (no durable data at risk — see [storage-readiness.md](storage-readiness.md)); production sizing/Multi-AZ is a separate **requires human approval** decision, not addressed here.

## 8. Required S3 decisions

- Bucket naming/region, and **whether to provision the prepared `s3_documents` Terraform module at all before real document-storage application code exists** — reasonable to provision early (cheap, empty, encrypted, no data-sensitivity risk while unused) or to defer until the feature is built. **Requires human approval** on timing preference, not a technical blocker either way.

## 9. Required IAM approvals

The design in [iam-matrix.md](iam-matrix.md) is complete and least-privilege by construction, but **provisioning any real IAM role requires an AWS account administrator to run `terraform apply`** — this mission's sandbox cannot and did not create any real IAM entity. Review of the *design* (not the act of creating it) is the approval this section asks for.

## 10. Tenant-isolation dependencies

**None found.** The full dependency audit ([ec2-dependency-audit.md](ec2-dependency-audit.md) §17) specifically checked whether containerization requires any change to `TenantContextService`, RLS policies, tenant middleware, or webhook tenant-resolution rules, and found none. The one place this branch's work touches tenant-adjacent territory at all — the new `/readyz` endpoint — was deliberately built to never establish tenant context or read tenant data, specifically to stay outside this boundary. If a future phase of this work (e.g., wiring `Schedule::` entries, or real document storage with per-tenant S3 prefixes) surfaces a real tenant-context requirement, it is to be documented for the tenant-isolation mission separately, per this mission's explicit instructions — not the case today.

## 11. Exact staging deployment sequence

See [runbooks/deployment-runbook.md](runbooks/deployment-runbook.md) for the full 10-step sequence. Summary: build → scan → push (digest) → migrate (RunTask, must succeed) → update web/worker/critical-worker/scheduler services → watch circuit-breaker-protected rollout → smoke test `/up` + `/readyz` → record release evidence.

## 12. Exact rollback sequence

See [runbooks/rollback-runbook.md](runbooks/rollback-runbook.md). Summary: automatic circuit-breaker rollback for a failed deployment (no operator action); for a bad-but-successfully-deployed release, re-deploy the previous digest per service; for a migration-involved rollback, forward-fix rather than `migrate:rollback` unless the migration was purely additive.

## 13. What would block staging deployment today, in priority order

1. **No real AWS account/credentials available to this mission** — every AWS-side item above is blocked on this, structurally, not by choice.
2. **VPC/subnet/RDS decisions** (§5, §6) — human approval required before `terraform apply` has valid inputs.
3. **A real `docker build`** has never been run — this is the highest-value next verification step for anyone continuing this work, ideally before any AWS resources are provisioned.
4. **DNS/ACM certificate** for the staging hostname — nothing in §11's sequence can complete without an HTTPS listener that has a real certificate.
5. **Scheduler has nothing to schedule** (§3) — not a deployment blocker (the service will run fine), but worth deciding before considering the scheduler "done" in a product sense.

## Confirmation

No production resources, DNS records, traffic, or database contents were changed by this branch. No live AWS resource was created (structurally impossible — no real AWS credentials existed in the environment this branch was authored in, and `terraform apply` was never invoked). No RLS/tenant-isolation semantic was changed. No firm was onboarded.
