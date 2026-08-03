# Deployment Runbook (ECS Staging)

**Date:** 2026-07-13
**Branch:** `feature/ecs-readiness-foundation`

Applies once staging infrastructure exists (see [staging-readiness-report.md](../staging-readiness-report.md) for what's still required before this runbook can be executed for real). This is the sequence [ecs-pipeline.yml](../../../.github/workflows/ecs-pipeline.yml) automates; this document is what an operator runs by hand if the pipeline is unavailable, or reads to understand what the pipeline is doing.

## Preconditions

- [ ] `main` is green (verify + test jobs passed — see [ci-cd pipeline](../../../.github/workflows/ecs-pipeline.yml)).
- [ ] Staging infrastructure exists (`terraform apply` has been run for `infrastructure/ecs/environments/staging/` by someone with real AWS credentials and the required inputs from [staging-readiness-report.md](../staging-readiness-report.md) — **not done by this branch**).
- [ ] Required secrets (`APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY`) already exist in Secrets Manager at the ARNs the staging Terraform environment references. The HMAC key is a **new, dedicated** secret — never reuse `APP_KEY`'s value or ARN — generated outside Terraform (e.g. `php artisan tinker --execute="echo bin2hex(random_bytes(32));"` piped into `aws secretsmanager create-secret`); only its ARN is ever supplied to Terraform (`platform_notifications_recipient_fingerprint_hmac_key_secret_arn`).

## Sequence

1. **Build**: `docker build` the image (see [container-architecture.md](../container-architecture.md)) with `--build-arg GIT_SHA=$(git rev-parse HEAD)`. This must be a commit containing both the merged SES consumer application code and its required migrations.
2. **Scan**: run the vulnerability scanner (Trivy in CI). Do not proceed past a CRITICAL/HIGH finding without an explicit, documented exception.
3. **Push**: push to ECR, tagged with the git SHA. Record the resulting **digest** (`sha256:...`) — this, not the tag, is what every following step references.
4. **Create the dedicated HMAC secret** (if not already done — see Preconditions above) outside Terraform's own value storage, and supply only its ARN as a `-var`/tfvars input. Never paste the value into a tfvars file or a `terraform apply -var` on a shared shell history.
5. **Register migrate task definition**: new revision of the `migrate` family (see `infrastructure/ecs/environments/staging/main.tf`'s `module.migrate`) referencing the new digest.
6. **Run migration**: `aws ecs run-task` with that revision. **Wait for task completion and check exit code** (see [database-migrations.md](../database-migrations.md)). Non-zero exit code → **stop here**, do not proceed to the next step. Investigate before retrying. `ses-consumer` must never start against a schema that doesn't yet have `notification_provider_correlations`/`ses_event_receipts`/`platform_notification_correlations`/`platform_notification_suppressions`.
7. **Apply IAM, task definition, log group, and service infrastructure**: `terraform apply` for `infrastructure/ecs/environments/staging/` — this both registers the `web`/`worker`/`critical-worker`/`scheduler`/`ses-consumer` task definition revisions referencing the new digest AND (on a first rollout of this role) creates the `ses_consumer` IAM task role, its dedicated CloudWatch log group, and its ECS service.
8. **Update services**: `aws ecs update-service --force-new-deployment` for each long-running service, one at a time (web first, so a bad deploy is caught by the ALB health check before workers pick up potentially-incompatible job payloads — though expand-contract discipline, see [database-migrations.md](../database-migrations.md), should mean order doesn't matter for a correctly-written migration). `ses-consumer` starts at its configured desired count (1 by default — `var.ses_consumer_desired_count`).
9. **Watch the rollout**: ECS's deployment circuit breaker (enabled on every service — see [infrastructure-architecture.md](../infrastructure-architecture.md)) automatically rolls back a service that fails to reach a steady state. Confirm each service, including `ses-consumer`, reaches `desiredCount == runningCount` (with all tasks passing their health check, for web) before moving to the next.
10. **Smoke test (web)**: `curl` `/up` and `/readyz` against the staging ALB DNS name / hostname. Both must return 2xx.
11. **Smoke test (ses-consumer)**: send an SES mailbox-simulator bounce (`bounce@simulator.amazonses.com` or the account's equivalent) addressed through a real, currently-correlated send. Confirm: (a) the event is processed exactly once (one `ses_event_processed` log line, one new `SesEventReceipt` row for that `idempotency_key`); (b) the corresponding SQS message is deleted (queue's `ApproximateNumberOfMessagesVisible` returns to its pre-test value); (c) the expected durable receipt and suppression state exists (`ses_event_receipts` row; `notification_events`/`platform_notification_suppressions` row as applicable); (d) the DLQ remains empty (`ApproximateNumberOfMessagesVisible` on the DLQ is 0); (e) no recipient address, HMAC key, or other secret appears anywhere in the `ses-consumer` CloudWatch log group for this test.
12. **Record release evidence**: git commit, image digest, task definition revisions, migration result, deployment result, timestamp (automated by the `release-evidence` job in CI — see [ci-cd pipeline](../../../.github/workflows/ecs-pipeline.yml)).

## What this runbook deliberately does not do

- Deploy to production — there is no production step. See `infrastructure/ecs/environments/production/README.md`.
- Retry a failed migration automatically.
- Use a mutable tag (`latest`) as the thing actually deployed — every step after "push" references the digest.
