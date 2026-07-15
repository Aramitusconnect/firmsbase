# Deployment Runbook (ECS Staging)

**Date:** 2026-07-13
**Branch:** `feature/ecs-readiness-foundation`

Applies once staging infrastructure exists (see [staging-readiness-report.md](../staging-readiness-report.md) for what's still required before this runbook can be executed for real). This is the sequence [ecs-pipeline.yml](../../../.github/workflows/ecs-pipeline.yml) automates; this document is what an operator runs by hand if the pipeline is unavailable, or reads to understand what the pipeline is doing.

## Preconditions

- [ ] `main` is green (verify + test jobs passed — see [ci-cd pipeline](../../../.github/workflows/ecs-pipeline.yml)).
- [ ] Staging infrastructure exists (`terraform apply` has been run for `infrastructure/ecs/environments/staging/` by someone with real AWS credentials and the required inputs from [staging-readiness-report.md](../staging-readiness-report.md) — **not done by this branch**).
- [ ] Required secrets (`APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`) already exist in Secrets Manager at the ARNs the staging Terraform environment references.

## Sequence

1. **Build**: `docker build` the image (see [container-architecture.md](../container-architecture.md)) with `--build-arg GIT_SHA=$(git rev-parse HEAD)`.
2. **Scan**: run the vulnerability scanner (Trivy in CI). Do not proceed past a CRITICAL/HIGH finding without an explicit, documented exception.
3. **Push**: push to ECR, tagged with the git SHA. Record the resulting **digest** (`sha256:...`) — this, not the tag, is what every following step references.
4. **Register migrate task definition**: new revision of the `migrate` family (see `infrastructure/ecs/environments/staging/main.tf`'s `module.migrate`) referencing the new digest.
5. **Run migration**: `aws ecs run-task` with that revision. **Wait for task completion and check exit code** (see [database-migrations.md](../database-migrations.md)). Non-zero exit code → **stop here**, do not proceed to step 6. Investigate before retrying.
6. **Register web/worker/critical-worker/scheduler task definitions**: new revisions of each family referencing the same new digest.
7. **Update services**: `aws ecs update-service --force-new-deployment` for each of the four long-running services, one at a time (web first, so a bad deploy is caught by the ALB health check before workers pick up potentially-incompatible job payloads — though expand-contract discipline, see [database-migrations.md](../database-migrations.md), should mean order doesn't matter for a correctly-written migration).
8. **Watch the rollout**: ECS's deployment circuit breaker (enabled on every service — see [infrastructure-architecture.md](../infrastructure-architecture.md)) automatically rolls back a service that fails to reach a steady state. Confirm each service reaches `desiredCount == runningCount` with all tasks passing their health check before moving to the next.
9. **Smoke test**: `curl` `/up` and `/readyz` against the staging ALB DNS name / hostname. Both must return 2xx.
10. **Record release evidence**: git commit, image digest, task definition revisions, migration result, deployment result, timestamp (automated by the `release-evidence` job in CI — see [ci-cd pipeline](../../../.github/workflows/ecs-pipeline.yml)).

## What this runbook deliberately does not do

- Deploy to production — there is no production step. See `infrastructure/ecs/environments/production/README.md`.
- Retry a failed migration automatically.
- Use a mutable tag (`latest`) as the thing actually deployed — every step after "push" references the digest.
