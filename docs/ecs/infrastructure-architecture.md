# Infrastructure Architecture (Terraform)

**Date:** 2026-07-13
**Branch:** `feature/ecs-readiness-foundation`

## No prior IaC convention existed

The repository had no `terraform/`, `cloudformation/`, CDK, or Pulumi directory before this branch (confirmed by direct search). This branch establishes a Terraform-based skeleton under `infrastructure/ecs/`, per the mission's default when no convention exists.

## Validated, not applied

Every file under `infrastructure/ecs/` was validated with a real Terraform install in the environment this branch was authored in:

```
terraform fmt -recursive       # applied — all files are canonically formatted
terraform init -backend=false  # succeeded — all 16 modules resolve, providers (hashicorp/aws ~> 5.0, hashicorp/null) installed
terraform validate             # Success! The configuration is valid.
terraform plan (fake credentials)  # got past all variable/expression evaluation and every resource
                                    # attribute check; failed only at the real AWS STS credential
                                    # check (`InvalidClientTokenId`) — proof that nothing could
                                    # have been created even if `apply` had been attempted, since
                                    # this sandbox has no real AWS credentials at all.
```

`terraform apply` was never run, and no real AWS credentials exist in the environment this branch was built in — it is not just policy but structurally impossible for this branch's work to have created a live AWS resource. Two real bugs were caught and fixed by this process before landing: security-group rule `description` fields rejecting `->` (AWS's allowed-character regex for that field), and `auth_token`/`transit_encryption_enabled` only being valid on `aws_elasticache_replication_group`, not the plainer `aws_elasticache_cluster` resource originally used.

## Structure

```
infrastructure/ecs/
  modules/
    networking/          # data-source validation of an EXISTING VPC/subnets — creates nothing
    security_groups/      # ALB SG, ECS task SG, RDS ingress rule (against an existing RDS SG)
    ecr/                   # single repository for the one application image
    kms/                    # one customer-managed key (secrets + S3 encryption)
    iam/                     # task execution role + 6 per-role task roles (see docs/ecs/iam-matrix.md)
    alb/                      # ALB, HTTPS listener, HTTP->HTTPS redirect, target group (readiness health check)
    ecs_cluster/               # Fargate-only ECS cluster, Container Insights enabled
    ecs_service/                # generic module: task definition + optional service + optional autoscaling
    elasticache/                 # single-node staging Redis (replication group, for auth_token support)
    s3_documents/                 # prepared (not yet used by app code) private/encrypted/versioned bucket
    cloudwatch_alarms/             # the alarm set from docs/ecs/alarm-inventory.md
  environments/
    staging/     # wires every module together with staging-sized values
    production/   # README placeholder ONLY — no resources, see that file
```

## VPC requirements (not created by this mission)

`modules/networking` takes an existing VPC ID and two subnet-ID lists (public, private) as input and validates them (subnet-to-VPC membership, via a `null_resource` precondition) rather than creating a VPC. Required before `terraform apply` can ever run:

- A VPC with both public subnets (route to an Internet Gateway, for the ALB only) and private subnets (route to a NAT Gateway, outbound-only — for every ECS task) across at least 2 Availability Zones.
- If this environment is meant to reach the *existing* EC2-hosted application's RDS instance, the VPC must be the same VPC (or peered/routable to it) that instance already lives in.

This is deliberately not decided by this branch — see [staging-readiness-report.md](staging-readiness-report.md) "required AWS inputs."

## RDS and ElastiCache

- **RDS**: assumed pre-existing (the application's current database). `modules/security_groups` adds an ingress rule to the RDS instance's *existing* security group (`var.existing_rds_security_group_id`) rather than creating a new RDS instance or SG — provisioning/sizing a new database, or deciding whether staging shares the production RDS instance vs. gets its own, is a data-sensitive decision outside this mission's authority (see mission boundary: "do not onboard firms," "do not run production migrations" — implicitly, do not make unreviewed decisions about where staging data lives relative to production data).
- **Redis (ElastiCache)**: `modules/elasticache` **does** provision a new single-node staging Redis replication group (`cache.t4g.micro` default), since this holds no durable business data (see [storage-readiness.md](storage-readiness.md) classification) and is low-risk/low-cost to stand up fresh for staging. AUTH token required (`transit_encryption_enabled`/`at_rest_encryption_enabled` both on).

## ALB and health-check configuration

| Setting | Value | Source |
|---|---|---|
| Listener | HTTPS 443 (TLS 1.2+ policy `ELBSecurityPolicy-TLS13-1-2-2021-06`) + HTTP 80 -> 301 redirect to HTTPS | `modules/alb` |
| Target group protocol/port | HTTP / 8080 (container port — see [container-architecture.md](container-architecture.md)) | `modules/alb` |
| Target type | `ip` (required for Fargate `awsvpc` networking — not `instance`) | `modules/alb` |
| Health check path | `/readyz` (see `app/Http/Controllers/ReadinessController.php`) | `modules/alb` var `readiness_health_check_path` |
| Health check interval | 15s | `modules/alb` |
| Health check timeout | 5s | `modules/alb` |
| Healthy threshold | 2 consecutive successes | `modules/alb` |
| Unhealthy threshold | 3 consecutive failures | `modules/alb` |
| Expected response | HTTP 200 with body `{"status":"ready","checks":{"database":"ok"[,"redis":"ok"]}}` | `ReadinessController` |
| Deregistration delay | 30s | `modules/alb`, coordinated with `stopTimeout` — see [graceful-shutdown.md](graceful-shutdown.md) |

## ECS task definitions summary

All six roles use the **same image digest** (`var.app_image_digest`, enforced by a Terraform variable validation rule requiring `@sha256:...`), differing only in command/role/sizing — see [container-architecture.md](container-architecture.md).

| Role | Command | CPU | Memory | Port | Service? | Desired count | Autoscaling | Task role |
|---|---|---|---|---|---|---|---|---|
| web | `["web"]` | 512 | 1024 | 8080 | Yes | 2 | CPU target-tracking, 60%, 2-6 | `task-web` |
| general worker | `["worker"]` (WORKER_QUEUES=default,documents,notifications,integrations,billing,low-priority) | 512 | 1024 | — | Yes | 2 | CPU target-tracking, 70%, 1-6 | `task-worker` |
| critical worker | `["worker"]` (WORKER_QUEUES=trust) | 512 | 1024 | — | Yes | **1 fixed** | **none** — never scales to zero | `task-critical-worker` |
| scheduler | `["scheduler"]` | 256 | 512 | — | Yes | **1 fixed** | none | `task-scheduler` |
| migrate | `["migrate"]` | 512 | 1024 | — | **No — RunTask only** | n/a | n/a | `task-migrate` |
| maintenance | `["maintenance", ...]` | 512 | 1024 | — | **No — RunTask only** | n/a | n/a | `task-maintenance` |

CPU/memory values are conservative starting points appropriate for staging load, not load-tested — flagged in [staging-readiness-report.md](staging-readiness-report.md) as "Ready with configuration" (needs a real load test to tune before production).

Autoscaling policy basis is CPU target-tracking, not the (more precise, but not-yet-available) queue-depth custom metric — see `modules/ecs_service` and [observability.md](observability.md) for why: the custom metric requires application-level `PutMetricData` emission that doesn't exist yet (`QueueHealthService`/`SchedulerHealthService` currently only persist to Postgres, see [ec2-dependency-audit.md](ec2-dependency-audit.md)). CPU-based scaling is a safe, immediately-usable default; switching the general worker's policy to queue-depth-based is a documented, low-effort follow-up once that metric exists.

## Hardening follow-ups (documented, not implemented in this branch)

Called out explicitly rather than silently deferred:

- **VPC endpoints** for ECR/S3/Secrets Manager/CloudWatch Logs/STS, to remove the current `0.0.0.0/0:443` egress rule in `modules/security_groups` in favor of endpoint-scoped access. Not added here because it requires additional VPC configuration (endpoint subnets, endpoint policies) that depends on the (not-yet-supplied) real VPC layout.
- **Read-only root filesystem** (`readonlyRootFilesystem: false` today in `modules/ecs_service`) with `storage/`/`bootstrap/cache` mounted as ECS `tmpfs` volumes instead of writable container layers. Deferred as a hardening pass, not required for staging correctness — the writable-path defensive check in `docker/entrypoint.sh` already fails loudly if this assumption is ever violated.
- **Production environment** — see `environments/production/README.md`.
- **Remote Terraform state backend** — not configured (see `environments/staging/versions.tf`); this branch's `terraform validate`/`plan` dry runs used local state only, which must never be used for a real `apply`.
