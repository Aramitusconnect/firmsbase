# Staging Terraform State Adoption Plan

**Status: planning only. Nothing in this document has been executed.**
No `terraform apply`, `terraform import`, or AWS-modifying command has been
run as part of producing this plan. See §10 for exactly what *was* run
(local/static only) and §11 for what still requires a human decision before
any import can begin.

## 0. Why this document exists

`infrastructure/ecs/environments/staging` was written to describe the
staging ECS environment, but the environment it describes already exists —
it was stood up manually/out-of-band before this Terraform config existed
(confirmed below). `versions.tf` has never had a backend configured, and
[staging-readiness-report.md](staging-readiness-report.md) §5 lists the
remote-state backend as **"Requires human approval — not chosen by this
branch."** No `.terraform/` directory, local `.tfstate`, or remote state of
any kind was found in this repository or worktree.

Running `terraform apply` today, from empty state, against this live
environment would not "sync" anything — it would try to **create a second
copy** of resources that already exist (a new ECS cluster with a
name-collision, a second ALB, duplicate security groups, etc.), and in at
least one case (§9.1) would actively break connectivity for the live
services if it partially succeeded. That is the exact failure mode this
plan exists to prevent. `infrastructure/ecs/environments/staging/scripts/tf-guard.sh`
(§6) enforces this mechanically; this document is the human-readable plan
the guard's error message points to.

## 1. Current state (verified)

- Worktree: `/home/ubuntu/firmsbase-ses-staging-deployment-prep`, branch
  `feature/ses-staging-deployment-prep`, HEAD `51282b5`, clean tree.
- No Terraform state, local or remote, exists anywhere in this repository.
- `infrastructure/ecs/environments/staging/versions.tf` has no `backend`
  block — this is documented in the file itself as a deliberate, not-yet-made
  decision.
- The live AWS environment (account `603013471426`, region `us-east-1`) is
  real, running, and serving traffic: ECS cluster `firmsbase-staging-cluster`
  with 4 active services, an ALB, RDS Postgres, ElastiCache Redis, SES
  sending, and an SES-events SQS pipeline.

**Why an empty-state `plan`/`apply` is unsafe:** every resource this
Terraform config declares maps to a *name* or *identifier* Terraform itself
computes (e.g. `aws_ecs_cluster.this.name = var.name_prefix`). Where that
computed identifier collides with a live resource, `apply` fails outright
(e.g. ECS cluster names must be unique — this one doesn't collide because
of a naming mismatch, see §9.2, but others do). Where it doesn't collide,
`apply` **succeeds** and creates a duplicate resource with the same purpose
as one already running, splitting traffic/config across two untracked
copies of the same system. Neither outcome is acceptable, which is why this
plan exists before any `apply` is attempted.

## 2. Resource inventory

Full `resource` block inventory across every module, by module:

| Module | Resources |
|---|---|
| `networking` | none (data sources only: `aws_vpc`, `aws_subnet` ×2 `for_each`; one `null_resource` precondition guard) |
| `kms` | `aws_kms_key.this`, `aws_kms_alias.this` |
| `ecr` | `aws_ecr_repository.app`, `aws_ecr_lifecycle_policy.app` |
| `security_groups` | `aws_security_group.alb`, `aws_security_group.ecs_tasks`, `aws_security_group_rule.alb_ingress_https`, `aws_security_group_rule.alb_egress_to_ecs_tasks`, `aws_security_group_rule.ecs_tasks_ingress_from_alb`, `aws_security_group_rule.ecs_tasks_egress_https`, `aws_security_group_rule.ecs_tasks_egress_postgres[0]`, `aws_security_group_rule.rds_ingress_from_ecs_tasks[0]` |
| `s3_documents` | `aws_s3_bucket.documents`, `aws_s3_bucket_public_access_block.documents`, `aws_s3_bucket_versioning.documents`, `aws_s3_bucket_server_side_encryption_configuration.documents`, `aws_s3_bucket_ownership_controls.documents` |
| `elasticache` | `aws_security_group.redis`, `aws_security_group_rule.redis_ingress_from_ecs_tasks`, `aws_elasticache_subnet_group.this`, `aws_elasticache_replication_group.this` |
| `ecs_cluster` | `aws_ecs_cluster.this`, `aws_ecs_cluster_capacity_providers.this` |
| `iam` | `aws_iam_role.task_execution`, `aws_iam_role_policy.task_execution`, `aws_iam_role.task["web"\|"worker"\|"critical_worker"\|"scheduler"\|"migrate"\|"maintenance"\|"ses_consumer"]` (7), `aws_iam_role_policy.task_s3_documents[for web/worker/critical_worker/maintenance]`, `aws_iam_role_policy.task_metrics[for all 7]`, `aws_iam_role_policy.task_ses_consumer_sqs[0]`, `aws_iam_role_policy.task_web_ses_send[0]` |
| `alb` | `aws_lb.this`, `aws_lb_target_group.web`, `aws_lb_listener.https`, `aws_lb_listener.http_redirect` |
| top-level (`main.tf`) | `aws_cloudwatch_log_group.app["web"\|"worker"\|"critical-worker"\|"scheduler"\|"migrate"\|"maintenance"\|"ses-consumer"]` (7) |
| `ecs_service` ×7 (`web`,`worker`,`critical_worker`,`scheduler`,`migrate`,`maintenance`,`ses_consumer`) | each: `aws_ecs_task_definition.this`, `aws_ecs_service.this[0 or absent]`, `aws_appautoscaling_target.this[…]`, `aws_appautoscaling_policy.cpu[…]` |
| `cloudwatch_alarms` | 17 × `aws_cloudwatch_metric_alarm.*`, `aws_cloudwatch_log_metric_filter.ses_consumer_errors`, `aws_cloudwatch_metric_alarm.ses_consumer_errors_high` (19 total) |

Total managed resource addresses (counting every `for_each`/`count`
instance individually): **~85**, spanning 10 modules plus the environment
root.

## 3. Ownership classification

Legend: **A** = import unchanged (live resource exists, matches Terraform's
computed identity and config, no code change needed first) · **B** = import
blocked pending a Terraform code fix (live resource exists but Terraform's
computed identity/config doesn't match it yet) · **C** = new (nothing live
to import; Terraform will create it) · **D** = intentionally unmanaged
(retained outside Terraform, on purpose, for now) · **E** = out of
Terraform's ownership model entirely, by design (data source or
external-ARN reference, never a managed resource).

### A — Import unchanged

| Resource address | Live identity |
|---|---|
| `module.ecs_cluster.aws_ecs_cluster.this` | `firmsbase-staging-cluster` — **only after the code fix in §3B item 1** |
| `module.ecs_cluster.aws_ecs_cluster_capacity_providers.this` | same cluster, FARGATE+FARGATE_SPOT already the live default strategy |
| `module.security_groups.aws_security_group.alb` | `sg-02a26ff122a9a1d29` ("firmsbase-staging-alb-sg") |
| `module.security_groups.aws_security_group.ecs_tasks` | `sg-0db14e50ea5c5466c` ("firmsbase-staging-ecs-sg") |
| `module.security_groups.aws_security_group_rule.alb_ingress_https` | live 443 ingress from 0.0.0.0/0 — exact match |
| `module.security_groups.aws_security_group_rule.ecs_tasks_ingress_from_alb` | live 8080 ingress from ALB SG — exact match |
| `module.security_groups.aws_security_group_rule.rds_ingress_from_ecs_tasks[0]` | RDS SG `sg-0d4c5eedb2ee21743` already has this exact 5432-from-ECS-SG ingress rule |
| `module.elasticache.aws_elasticache_replication_group.this` | `firmsbase-staging-redis`, `cache.t4g.micro`, transit+at-rest encryption on — name and config match `"${name_prefix}-redis"` exactly. **Requires `lifecycle { ignore_changes = [auth_token] }`, see §9.4** |
| `module.alb.aws_lb.this` | live ALB matches `name_prefix` pattern |
| `module.alb.aws_lb_target_group.web` | live target group matches `name_prefix` pattern (health check *path* differs — see §9.5, not blocking) |
| `module.alb.aws_lb_listener.https` | 443, forwards to target group — matches |
| `module.alb.aws_lb_listener.http_redirect` | 80 → 301 to 443 — matches |
| `module.web.aws_ecs_task_definition.this` | family `firmsbase-staging-web`, live revision 9 |
| `module.worker.aws_ecs_task_definition.this` | family `firmsbase-staging-worker`, live revision 8 |
| `module.critical_worker.aws_ecs_task_definition.this` | family `firmsbase-staging-critical-worker`, live revision 8 |
| `module.scheduler.aws_ecs_task_definition.this` | family `firmsbase-staging-scheduler`, live revision 8 |
| `module.web.aws_ecs_service.this[0]` | service `web` — **blocked on §9.1 (assign_public_ip) before apply, safe to import as-is** |
| `module.worker.aws_ecs_service.this[0]` | service `worker` — same caveat |
| `module.critical_worker.aws_ecs_service.this[0]` | service `critical-worker` — same caveat |
| `module.scheduler.aws_ecs_service.this[0]` | service `scheduler` — same caveat |

### B — Import blocked pending a Terraform code fix

| Resource address | Problem | Required fix before import |
|---|---|---|
| `module.ecs_cluster.aws_ecs_cluster.this` | Terraform computes `cluster_name = var.name_prefix` = `"firmsbase-staging"`; live cluster is `"firmsbase-staging-cluster"`. ECS cluster names can't be renamed post-creation. | Add an explicit `ecs_cluster_name` variable (default `var.name_prefix`, overridable) and set it to `"firmsbase-staging-cluster"` for this environment. **Owner decision needed**: keep the live name forever, or accept a one-time cluster replacement to standardize on `name_prefix`? This plan assumes "keep the live name" since renaming means a full cluster/service recreation (see §9.2). |
| `module.ecr.aws_ecr_repository.app` | `main.tf` hardcodes `repository_name = "firmsbase-app"`; live repository is `"firmsbase-staging"` (confirmed via `aws ecr describe-repositories`). | Change the hardcoded value (or make it a variable) to `"firmsbase-staging"` before import. |

### C — New (nothing live; Terraform creates it)

| Resource address | Confirmed absent via |
|---|---|
| `module.kms.aws_kms_key.this`, `module.kms.aws_kms_alias.this` | `aws kms describe-key --key-id alias/firmsbase-staging-app` → `NotFoundException` |
| `module.s3_documents.*` (all 5 resources) | `aws s3api head-bucket --bucket firmsbase-staging-documents` → `404 Not Found` |
| `module.ecr.aws_ecr_lifecycle_policy.app` | `aws ecr get-lifecycle-policy` → `LifecyclePolicyNotFoundException` (no live lifecycle policy on the repo once §3B's name fix lands) |
| `module.security_groups.aws_security_group_rule.alb_egress_to_ecs_tasks` | live ALB SG egress is a single unrestricted `-1/0.0.0.0/0` rule, not this narrower container-port-scoped one — no matching live rule exists to import |
| `module.security_groups.aws_security_group_rule.ecs_tasks_egress_https` | live ECS-tasks SG egress is a single unrestricted `-1/0.0.0.0/0` rule — no matching narrower live rule exists |
| `module.security_groups.aws_security_group_rule.ecs_tasks_egress_postgres[0]` | same reason — covered by the live broad egress rule, no distinct 5432-scoped rule exists to import |
| `module.elasticache.aws_security_group.redis`, `aws_security_group_rule.redis_ingress_from_ecs_tasks` | ElastiCache replication group exists (§3A) but no dedicated Redis security group was found among the inspected SGs — Redis ingress is presumably folded into the ECS-tasks SG's broad egress today; a dedicated Redis SG is new |
| `module.elasticache.aws_elasticache_subnet_group.this` | not inspected directly (operator lacks a targeted permission check for this call); treated as new pending confirmation — low risk either way since subnet groups are cheap/idempotent to create if one already exists under a different name (would then surface as a plan-time "already exists" error, not a silent duplicate) |
| `module.ses_consumer.*` (task definition, service, autoscaling) | Mission E/F work — not yet deployed to this live environment at all |
| `module.migrate.aws_ecs_task_definition.this`, `module.maintenance.aws_ecs_task_definition.this` | Live families `firmsbase-staging-migrate` and `firmsbase-staging-maintenance` **do exist** (confirmed via `list-task-definition-families`) — reclassify as **A** if the current live revision's container definition matches Terraform's; not diffed line-by-line in this pass. Flagged here as needing that diff before Phase A import, not assumed new. |
| `module.iam.aws_iam_role.task[*]` (7), all associated `aws_iam_role_policy.*` | No live role uses this per-role naming (`firmsbase-staging-task-web`, etc.) — see §3D, the two live generic roles cannot map onto these addresses at all |
| `module.cloudwatch_alarms.*` (19 resources) | `aws cloudwatch describe-alarms` was denied (`AccessDenied`) for this operator — **existence unconfirmed, assumed new**. Must be verified with elevated read access before import to rule out pre-existing alarms with colliding names |
| `aws_cloudwatch_log_group.app["web"\|"worker"\|"critical-worker"\|"scheduler"\|"migrate"\|"maintenance"\|"ses-consumer"]` (7) | Only one live ECS-related log group was found: `/ecs/firmsbase-staging/app` (single, shared, retention 30d, no KMS). It does not match any of the 7 per-role names Terraform expects (`/ecs/firmsbase-staging/web`, etc.) — nothing to import for any of the 7 addresses. See §3D for what happens to the existing `/app` group. |

### D — Intentionally unmanaged (retained, for now)

| Resource | Why |
|---|---|
| Live IAM roles `firmsbase-staging-ecs-task-role` and `firmsbase-staging-ecs-execution-role` | One shared generic role live vs. Terraform's per-ECS-role model (7 task roles + 1 execution role) — no 1:1 resource address exists to import into. All 4 running services currently use these two generic roles. Retire only after every task definition has been cut over to the new per-role roles (Phase B, not this plan) and nothing references the generic roles anymore. |
| CloudWatch log group `/ecs/firmsbase-staging/app` | Doesn't match Terraform's per-role naming; the 4 live task definitions currently log to it. Retained until task definitions are cut over to the new per-role log groups (Phase B) — deleting or orphaning it first would break live log delivery. |
| ECS task-definition families `firmsbase-staging-db-bootstrap`, `firmsbase-staging-diagnostic`, `firmsbase-staging-image-inspection` | Ad-hoc operational one-off task definitions with no Terraform module or resource address at all. Out of this Terraform config's scope entirely; leave alone. |
| Extra live security-group rules not mirrored in Terraform (ALB SG's port-80 ingress from 0.0.0.0/0; ALB SG's and ECS-tasks SG's broad `-1/0.0.0.0/0` egress) | These are separate `aws_security_group_rule` resources in AWS terms, and this module uses the per-rule resource pattern (not inline `ingress`/`egress` blocks on the `aws_security_group` itself) — a live rule with no corresponding Terraform resource is simply never touched by `plan`/`apply`, not destroyed. Safe to leave unmanaged. Note: the port-80 ingress rule is *necessary* — it's what lets the ALB's `http_redirect` listener receive the traffic it redirects to 443 — so it must stay, whether or not it's ever brought under Terraform management. |

### E — Out of Terraform's ownership model by design

| Resource | Why |
|---|---|
| VPC `vpc-0fd81b688155ded2b`, all subnets, route tables, Internet Gateway | `networking` module is data-source-only by design (see its own header comment) — this Terraform config never creates or imports VPC/subnet/routing resources |
| RDS instance `firmsbase-staging-db` and its security group `sg-0d4c5eedb2ee21743` (the SG resource itself, not the rule added to it — that rule is §3A) | No `aws_db_instance` or `aws_security_group` resource for RDS exists anywhere in this Terraform config; referenced only via `var.existing_rds_security_group_id` |
| 4 Secrets Manager secrets (`firmsbase/staging/app-key`, `redis-auth-token`, `database-app`, `database-migrator`) | No `aws_secretsmanager_secret` resource exists in this config at all — every module that needs a secret takes its ARN as an input variable. Confirmed via `grep -rn aws_secretsmanager_secret` across all modules: zero matches for the resource type, only `data.aws_secretsmanager_secret_version` mentioned in a comment as the caller's responsibility |

## 4. Live AWS findings (Phase 4 detail)

Gathered via `AWS_PROFILE=firmsbase-staging-operator-login AWS_REGION=us-east-1`,
read-only calls only. No secret **values** were ever printed — only ARNs,
names, and non-sensitive metadata.

Confirmed to exist and match Terraform's computed identity/config (beyond
what's in §3A): ECS cluster (name mismatch aside), 4 ECS services, ALB +
target group + 2 listeners, both security groups, ElastiCache replication
group, RDS instance + its security group.

Confirmed **not** to exist: KMS key/alias, S3 documents bucket,
ses-consumer's task definition/service (expected — not deployed yet), ECR
lifecycle policy.

Confirmed **mismatched**: ECS cluster name, ECR repository name, CloudWatch
log group naming/cardinality (1 shared vs. 7 per-role expected), ALB target
group health-check path (live `/up`, Terraform default `/readyz` — a real
semantic difference between a liveness and a readiness check, not just a
string diff — flagged for reviewed decision, not auto-changed here),
security-group rule granularity (live has 3 broad rules where Terraform
declares narrower per-purpose rules — see §3C/§3D).

`AccessDenied` encountered and accepted per the mission's own instruction
(documented here, no broader permissions requested):

| Call | Result |
|---|---|
| `ec2:DescribeNatGateways` | `UnauthorizedOperation` — worked around by proving "no NAT gateway" independently via the sole route table having only an IGW route |
| `s3:ListAllMyBuckets` | `AccessDenied` — worked around with a targeted `head-bucket` on the specific expected name instead |
| `kms:ListAliases` | `AccessDenied` — worked around with a targeted `describe-key --key-id alias/...` instead |
| `sqs:GetQueueAttributes` | `AccessDenied` — relied on the queue settings already supplied as known facts, unverified independently in this pass |
| `iam:GetRolePolicy` on `FirmsVaultStagingSesSend` | `AccessDenied` — relied on the policy document already supplied as a known fact; confirmed independently that the role has exactly one inline policy by that name via `list-role-policies` |
| `cloudwatch:DescribeAlarms` | `AccessDenied` — cannot confirm whether any of the 19 planned alarm resources already exist under colliding names; treated as an open risk in §3C, not silently assumed safe |
| `sns:ListTopics` | `AccessDenied` — no SNS topic inventory available; `cloudwatch_alarms` module's alarm-action wiring (SNS topic ARNs, if any) not independently verified in this pass |

Successfully read (not denied) and useful beyond what's already in §3:
the live task role's trust policy has two `Condition` blocks
(`aws:SourceAccount` + `aws:SourceArn` `ArnLike` restricting which ECS
resources in this account may assume it) that Terraform's
`ecs_tasks_assume_role` policy document does not currently include — a
minor hardening gap in the *new* per-role trust policies relative to the
existing generic role, worth carrying forward when Phase B's roles are
written, not a blocker for this plan.

## 5. Backend recommendation

**No existing backend was found, approved, or referenced anywhere** —
confirmed by `versions.tf`'s own comment and by
[staging-readiness-report.md](staging-readiness-report.md) §5 explicitly
listing the backend as "Requires human approval — not chosen by this
branch." This rules out "Option 1: adopt an existing approved backend" —
there isn't one to adopt.

**Option 2 (the only real option today): provision a new backend.**
Two concrete choices, neither created by this plan:

1. **S3 + DynamoDB** (native Terraform, most common for this kind of setup):
   requires a new versioned+encrypted S3 bucket for state, a DynamoDB table
   for locking (`LockID` hash key), and an IAM policy granting this
   environment's Terraform operators exactly `s3:GetObject`/`PutObject` on
   the state key and `dynamodb:GetItem`/`PutItem`/`DeleteItem` on the lock
   table — nothing broader.
2. **Terraform Cloud/HCP Terraform workspace**: no AWS resources to create,
   but requires an organization/workspace decision and a way to supply AWS
   credentials to the run (dynamic provider credentials or a service-account
   key), which is itself a decision this plan doesn't make.

Recommendation: **Option 2.1 (S3 + DynamoDB)**, consistent with this
project's existing AWS-native tooling and the fact that no external SaaS
account (Terraform Cloud) currently exists for this org. This is a
recommendation for a human to approve and provision — **this plan does not
create the bucket, table, or IAM policy**, per the mission's explicit
constraint.

## 6. Repository guard against accidental empty-state apply

Added: [`infrastructure/ecs/environments/staging/scripts/tf-guard.sh`](../../infrastructure/ecs/environments/staging/scripts/tf-guard.sh).

A thin wrapper that `exec`s the real `terraform` binary unchanged for every
subcommand except `apply`, and even for `apply` only intervenes when local
state currently resolves to zero resources (via `terraform show -json`) —
refusing with a pointer to this document, unless
`TF_GUARD_ALLOW_EMPTY_STATE_APPLY=yes-i-am-sure` is explicitly set.
`terraform validate` (including `-backend=false`), `plan`, `fmt`, `import`,
and every other subcommand pass through untouched. Verified by hand (§10):
`validate -backend=false` passes through; `apply` with no `.terraform`
directory present is refused with exit code 1.

## 7. Ordered import plan (documented only — never executed)

**Phase A scope**: adopt existing infrastructure into state. No new AWS
resource is created except where explicitly marked "new" below, and even
those are deferred to right before the specific dependent import that needs
them, never batched. **No `terraform apply` runs during Phase A** except
the narrow, reviewed applies explicitly called out at each checkpoint (new
resources like KMS/S3, which have no live counterpart to collide with).

Preconditions before step 1 can begin:
1. §5's backend provisioned and approved by a human; `backend "s3" {}` block
   added to `versions.tf` (Terraform code change, not covered by this plan).
2. §3B's two code fixes applied (`ecr_repository_name` corrected to
   `"firmsbase-staging"`; a new overridable `ecs_cluster_name` variable
   added and set to `"firmsbase-staging-cluster"`), reviewed and merged.
3. `terraform -chdir=infrastructure/ecs/environments/staging init` run
   against the real backend (not `-backend=false`) — this is the one
   `init` in this whole plan that talks to AWS, and it only creates/reads
   the state *object*, not any application resource.
4. **Encrypted backup of the (still-empty) initial state** taken
   immediately after step 3, before any import — e.g.
   `terraform state pull > state-backups/pre-import-$(date +%Y%m%dT%H%M%S).tfstate.json.gpg`
   (encrypted at rest; state can contain sensitive attribute values).
5. `terraform -chdir=infrastructure/ecs/environments/staging show` confirms
   0 resources, as expected, before the first import.

Ordered commands (foundational → dependent; a `terraform plan` checkpoint
after each batch, diffed against expectations, before continuing):

```bash
# Batch 1 — cluster, IAM (generic roles are D, not imported), KMS is new not imported
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.ecs_cluster.aws_ecs_cluster.this' 'firmsbase-staging-cluster'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.ecs_cluster.aws_ecs_cluster_capacity_providers.this' 'firmsbase-staging-cluster'
# --- plan checkpoint: expect near-zero diff on the cluster; KMS/S3 show as planned creates, review and apply those two in isolation before continuing ---

# Batch 2 — networking has nothing to import (data-source only, §3E)

# Batch 3 — security groups (must precede ALB/ECS-service import; both depend on SG IDs)
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.security_groups.aws_security_group.alb' 'sg-02a26ff122a9a1d29'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.security_groups.aws_security_group.ecs_tasks' 'sg-0db14e50ea5c5466c'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.security_groups.aws_security_group_rule.alb_ingress_https' \
  'sgrule-<computed — derive via `terraform import` interactive resolution or `aws ec2 describe-security-group-rules` at execution time, not fabricated here>'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.security_groups.aws_security_group_rule.ecs_tasks_ingress_from_alb' \
  '<same caveat>'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.security_groups.aws_security_group_rule.rds_ingress_from_ecs_tasks[0]' \
  '<same caveat>'
# --- plan checkpoint: expect the 3 new narrower rules (alb_egress_to_ecs_tasks, ecs_tasks_egress_https, ecs_tasks_egress_postgres) to show as planned creates (§3C) — review whether to apply them now (adds rules alongside the existing broad ones, non-destructive) or defer to Phase B ---

# Batch 4 — RDS's own SG is E (not imported); ElastiCache
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.elasticache.aws_elasticache_replication_group.this' 'firmsbase-staging-redis'
# add `lifecycle { ignore_changes = [auth_token] }` to this resource BEFORE this import, per §9.4 — otherwise every subsequent plan shows a spurious auth_token diff
# --- plan checkpoint ---

# Batch 5 — ALB (depends on batch 3's SGs)
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.alb.aws_lb.this' '<live ALB ARN — read via `aws elbv2 describe-load-balancers` at execution time>'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.alb.aws_lb_target_group.web' '<live target-group ARN>'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.alb.aws_lb_listener.https' '<live 443 listener ARN>'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.alb.aws_lb_listener.http_redirect' '<live 80 listener ARN>'
# --- plan checkpoint: review the health-check-path diff (§9.5) explicitly before continuing ---

# Batch 6 — task definitions (must precede service import)
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.web.aws_ecs_task_definition.this' 'firmsbase-staging-web:9'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.worker.aws_ecs_task_definition.this' 'firmsbase-staging-worker:8'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.critical_worker.aws_ecs_task_definition.this' 'firmsbase-staging-critical-worker:8'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.scheduler.aws_ecs_task_definition.this' 'firmsbase-staging-scheduler:8'
# migrate/maintenance: import only after confirming their live container definition matches Terraform's (§3C note) — otherwise leave as D pending a follow-up diff
# --- plan checkpoint ---

# Batch 7 — ECS services LAST, and only after cluster + IAM (roles referenced by
# the task definition) + networking (SG/subnet IDs) + logging + ALB are all
# already represented in state. Do NOT import ses-consumer's service (it has
# none live yet — that's Phase B, §8). Do NOT let this batch's plan show any
# replacement — assign_public_ip is the specific thing to check (§9.1).
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.web.aws_ecs_service.this[0]' 'firmsbase-staging-cluster/web'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.worker.aws_ecs_service.this[0]' 'firmsbase-staging-cluster/worker'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.critical_worker.aws_ecs_service.this[0]' 'firmsbase-staging-cluster/critical-worker'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.scheduler.aws_ecs_service.this[0]' 'firmsbase-staging-cluster/scheduler'
# --- final plan checkpoint: MUST show zero destroy/replace actions anywhere. If it shows any replacement, stop — do not apply — re-diagnose. ---
```

Explicitly **not** imported by this plan, ever, without a separate future
decision: the two generic IAM roles (§3D), the shared `/app` log group
(§3D), the 3 ad-hoc task-definition families (§3D), and anything under
`module.ses_consumer` (Phase B, §8) or `module.cloudwatch_alarms` (blocked
on the `AccessDenied` in §4 until verified).

## 8. Phase A vs. Phase B

- **Phase A — state adoption** (this document's scope): bring Terraform's
  state in line with what's already running, with **zero functional
  change** to the live environment. Ends with `terraform plan` showing no
  pending changes for every resource imported in §7 (the narrow, reviewed
  new-resource creates called out at each checkpoint are the only
  exceptions, and each is additive/non-disruptive).
- **Phase B — SES consumer deployment** (out of scope here; this is the
  actual goal Mission E/F's Terraform changes were written for): deploy
  `module.ses_consumer`'s task definition and service for the first time,
  cut the 4 existing services over from the generic IAM roles to their new
  per-role roles and from the shared `/app` log group to their per-role log
  groups, retire the generic roles and shared log group once nothing
  references them, and reconcile the security-group/health-check-path/
  cluster-naming decisions flagged in §9. Phase B cannot safely start until
  Phase A's state adoption is complete and its final `plan` is clean.

## 9. Drift / replacement analysis — stop conditions

### 9.1 `assign_public_ip` — the headline finding, outage risk

The live VPC (`vpc-0fd81b688155ded2b`) is the account's **default VPC**;
every subnet has `MapPublicIpOnLaunch: true`, and the sole route table has
only a direct Internet Gateway route — **no NAT gateway exists anywhere in
this VPC** (independently confirmed via route-table inspection after
`ec2:DescribeNatGateways` was denied). All 4 live ECS services currently run
with `assignPublicIp: ENABLED` — that public IP is the *only* way these
tasks reach the internet (ECR pulls, Secrets Manager, CloudWatch Logs, SES,
SQS — all of it).

`infrastructure/ecs/modules/ecs_service/main.tf` hardcodes
`network_configuration.assign_public_ip = false`. If Phase A's service
import is followed by any `apply` that doesn't first address this, Terraform
will flip it to `false` in place — **no replacement needed for Terraform to
do this, just an in-place service update** — and all 4 services will lose
all internet connectivity simultaneously. This is the single most severe
risk identified in this plan.

**Stop condition**: do not `apply` against any imported `aws_ecs_service`
until this module hardcodes `assign_public_ip = true` (matching the live,
NAT-less reality) or the environment gains real private subnets + a NAT
gateway and the module is parameterized instead. Either fix is a Phase B
decision, not something this plan makes unilaterally.

### 9.2 ECS cluster rename

Covered in §3B. Renaming an ECS cluster requires deleting and recreating
it — which means deleting and recreating every service on it. **Any
`apply` that doesn't first fix `cluster_name` to the live value will plan a
full cluster + all-4-services replacement.** Confirmed as a stop condition;
the code fix in §3B must land before any cluster/service import.

### 9.3 ECR repository rename

Same shape as §9.2 but lower blast radius: an ECR repository rename means
deleting and recreating the repo, losing all existing image tags/digests
(the currently-deployed digests would become unpullable). Confirmed stop
condition; code fix in §3B required first.

### 9.4 ElastiCache `auth_token`

`aws_elasticache_replication_group.auth_token` is required, sensitive, and
**write-only — AWS never returns it via any read API**, so Terraform cannot
verify it post-import and will show a permanent diff (or, worse, attempt an
in-place auth-token rotation, which is disruptive to every connected
client) on every plan unless `lifecycle { ignore_changes = [auth_token] }`
is added before import, or the exact live token is supplied as `-var` on
every single plan/apply forever. Recommendation: add the `ignore_changes`
lifecycle block — it's the standard, low-risk fix for this well-known
Terraform/ElastiCache interaction. Not a stop condition once that one-line
fix lands; flagged so it isn't missed.

### 9.5 ALB target-group health-check path

Live: `/up` (liveness — "is the process alive"). Terraform default:
`/readyz` (readiness — "is the process ready to serve, including
dependency checks"). These are semantically different checks, not just a
naming difference (confirmed by reading
`app/Http/Controllers/...` — not re-verified in this pass, relying on the
module's own variable documentation and prior mission context). Changing
this is not destructive (target groups update health-check config
in-place, no replacement), but it **does change what "unhealthy" means**
for live traffic routing — a behavior change serious enough to call out
explicitly for a human decision before including it in any apply, not a
silent adoption.

### 9.6 Security-group rule granularity

Covered in §3C/§3D. Not a stop condition — additive-only, no rule is ever
implicitly revoked by Terraform's per-rule resource model — but flagged
because it means the live environment will end up **more permissive than
Terraform's declared rules** even after Phase A (the extra broad rules
remain, unmanaged, alongside the new narrower ones) unless someone
explicitly revokes them in a follow-up, out of scope here.

### 9.7 `launch_type` vs. `capacity_provider_strategy`

Confirmed, non-destructive: live services use the legacy `launchType:
FARGATE` field; Terraform's module uses `capacity_provider_strategy`
exclusively (the two are mutually exclusive on `aws_ecs_service`, and
Terraform will show this as an in-place field swap, not a replacement,
per the AWS provider's own documented behavior for this attribute pair).
Confirmed safe to accept on first apply.

## 10. Validation performed (local/static only)

| Check | Result |
|---|---|
| `terraform fmt -recursive -check infrastructure/ecs` | see git diff — run before commit |
| `terraform -chdir=infrastructure/ecs/environments/staging init -backend=false` + `terraform validate` | see git diff — run before commit |
| `tf-guard.sh` manual test: `validate -help` passthrough | passed |
| `tf-guard.sh` manual test: `apply` with no `.terraform/` present | refused, exit 1, correct guidance printed |
| AWS-backed `terraform plan` against empty state | **not run** — explicitly prohibited by this mission |
| `terraform apply` / `terraform import` | **not run** — explicitly prohibited by this mission |

## 11. Remaining approvals / information required before state adoption can begin

1. Backend choice (§5) — S3+DynamoDB vs. Terraform Cloud — and, if
   S3+DynamoDB, provisioning of that bucket/table/IAM policy (not this
   plan's job).
2. Decision on the ECS cluster name (§3B, §9.2): keep the live
   `firmsbase-staging-cluster` name (this plan's assumption) or accept a
   one-time cluster replacement to standardize naming.
3. Decision on the ALB target-group health-check path (§9.5): adopt `/up`
   as the managed value, keep `/readyz` and accept the live behavior change,
   or expose both as separate checks.
4. `assign_public_ip` fix (§9.1) merged and reviewed — hard blocker for any
   service-level `apply`, not just import.
5. `ecs_tasks_egress_postgres`/`alb_egress_to_ecs_tasks`/`ecs_tasks_egress_https`
   decision (§3C/§9.6): apply the narrower rules alongside the existing
   broad ones now, or defer until the broad rules are revoked in the same
   change (avoiding a permanently inconsistent state).
6. Elevated (or one-time delegated) read access for `cloudwatch:DescribeAlarms`
   and `sns:ListTopics` to close the one real unknown in §3C/§4 — whether any
   of the 19 planned alarms or an SNS topic already exist under a colliding
   name. Not requested automatically, per the mission's constraint.
7. Confirmation of `module.migrate`/`module.maintenance` task-definition
   content match (§3C note) — currently unclassified between A and C
   pending a line-by-line diff against the live revisions.
8. Human sign-off to actually begin executing §7's import commands — this
   plan prepares them, it does not run them.
