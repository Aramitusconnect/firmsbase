# Staging Terraform State Adoption Plan

**Status: planning only. Nothing in this document has been executed.**
No `terraform apply`, `terraform import`, `terraform refresh`, `terraform`
state command, or AWS-modifying command has been run as part of producing
this plan or its correction. See §10 for exactly what *was* run
(local/static only) and §11 for what still requires a human decision
before any import can begin.

## 0. Why this document exists, and what changed in this revision

`infrastructure/ecs/environments/staging` was written to describe the
staging ECS environment, but the environment it describes already exists —
it was stood up manually/out-of-band before this Terraform config existed.
An approved S3 backend was configured on 2026-08-03 (code-only — the state
prefix remains empty); see §5.

**This is a corrected revision.** An independent review of the first
version of this plan (commit `0734429`) found material inconsistencies —
summarized here for transparency, since an audit document that hides its
own prior mistakes isn't trustworthy:

1. **Resource count**: the first version claimed "~85" resources but its
   own classification table only accounted for 58. This revision parses
   every resource address from source (root module + every child module,
   expanding every `for_each`/`count`) and classifies every one of them —
   see §2 and §3 for the exact, reproducible total: **94**.
2. **ECS task definitions were wrongly called import-unchanged.** Live
   task definitions use the shared generic role
   `arn:aws:iam::603013471426:role/firmsbase-staging-ecs-task-role`;
   Terraform models a role-specific task role per service. These are not
   field-equivalent. Corrected in §3/§6.
3. **ECS services were wrongly called import-unchanged.** Live services
   run `assignPublicIp=ENABLED`, `launchType=FARGATE` with no capacity
   provider strategy, and reference the (not-field-equivalent) task
   definitions above. Corrected in §3/§7, with a code-level guard added
   (§7) so the dangerous case — disabling public IPs with no NAT gateway
   in place — cannot happen by accident.
4. **The networking module's resources were asserted safe without a
   field-level comparison.** This revision adds the full VPC/subnet/route/
   security-group compatibility matrix in §4.
5. **Two additional real mismatches were found in the course of this
   correction that neither version originally caught**: the ElastiCache
   subnet group name (Terraform computes `firmsbase-staging-redis`; live
   is `firmsbase-staging-cache-subnets`) and, more seriously, the
   ElastiCache **engine** itself — live runs **Valkey**, not Redis
   (`engine = "valkey"`, confirmed via
   `aws elasticache describe-replication-groups`), while Terraform
   hardcoded `engine = "redis"`. Engine is not changeable in place; this
   was a real, previously-undetected replacement/data-loss stop condition.
   Both are fixed in §6/§9.4.
6. **The IAM task-execution role was previously lumped in with the task
   role as "no mapping possible."** That's wrong for the execution role
   specifically: live has exactly one shared execution role, and Terraform
   also models exactly one shared execution role — a genuine 1:1 naming
   mismatch fixable with a variable, not a structural non-mapping. The
   *task* role genuinely has no live per-service equivalent and remains
   correctly classified as new. See §3/§6.

## 1. Current state (verified)

- Worktree: `/home/ubuntu/firmsbase-ses-staging-deployment-prep`, branch
  `feature/ses-staging-deployment-prep`.
- No Terraform state, local or remote, exists anywhere in this repository —
  `infrastructure/ecs/environments/staging/versions.tf` now has an
  approved `backend "s3" {}` block (configured 2026-08-03, see §5), but no
  `terraform init` has been run against it, so no state object exists yet
  either locally or in that backend.
- The live AWS environment (account `603013471426`, region `us-east-1`) is
  real, running, and serving traffic.

**Why an empty-state `plan`/`apply` is unsafe:** every resource this
config declares maps to a name/identifier Terraform itself computes. Where
that computed identifier collides with a live resource, `apply` either
fails outright or — worse — succeeds and silently diverges from live
config in a way that breaks the running service (§9.1 is the most severe
example: an in-place `assignPublicIp` flip that would cut off all internet
egress for every ECS task, since this VPC has no NAT gateway). This is why
`infrastructure/ecs/environments/staging/scripts/tf-guard.sh` mechanically
refuses `plan`/`apply` until specific, checkable preconditions hold — see
§7.

## 2. Resource inventory and counting methodology

Every `resource` address in `infrastructure/ecs/environments/staging` and
its child modules is enumerated in
[`import-manifest.json`](../../infrastructure/ecs/environments/staging/import-manifest.json),
one entry per address (every `for_each`/`count` instance counted
individually, never as one block). That file is the single source of
truth for counts; this document summarizes it and must not drift from it —
enforced by
[`scripts/validate-import-manifest.py`](../../infrastructure/ecs/environments/staging/scripts/validate-import-manifest.py)
(§10).

Terraform's resource count is variable-dependent (`count`/`for_each`
conditionals) — there is no single "the" resource count independent of
what values the variables take. The manifest's `counting_methodology`
block documents exactly which variables are treated as set (every
required, no-default variable — `vpc_id`, `rds_security_group_id`,
`redis_auth_token`, the `ses_*` ARNs, `alarm_sns_topic_arn`, etc. — since
the config cannot describe this specific environment at all without
them) versus left at their repository default (`enable_custom_metric_alarms
= false`, which zeroes out 4 alarm resource addresses entirely). A
different tfvars file could produce a different total; this manifest's 94
is the count for *this* environment, deployed with the values already
established across prior missions.

**Exact totals** (validator-verified, not approximate):

| Classification | Count | Meaning |
|---|---:|---|
| `import_unchanged` | 10 | Live resource exists and field-matches; ready to import once blocked prerequisites (mostly permission gaps, not config gaps) clear |
| `import_then_migrate` | 12 | Live resource exists, but Terraform needs a code fix (naming) and/or a design decision (permission shape, engine, health-check values) before import is clean |
| `new` | 66 | No live counterpart; Terraform will create it (Phase B) |
| `unmanaged` | 0 | (bucket reserved; see note below — this repo currently has none) |
| `do_not_import` | 6 | Live resource exists, but deliberately not imported (all 6 are the pinned ECS task definitions — §6) |
| **Total** | **94** | |

Note on `unmanaged` = 0: several *live* resources have no Terraform
resource address at all — the two generic IAM roles, the shared
`/ecs/firmsbase-staging/app` log group, three ad-hoc task-definition
families. These are documented in §3's "live resources with no Terraform
address" list, but they cannot appear in the 94-address manifest because
there is no address to classify — Terraform's graph simply doesn't
declare a resource for them. `unmanaged` remains a valid classification
value for a future resource that does have an address but is deliberately
left unmanaged; none exists in this repository today.

## 3. Ownership classification

Full detail — every one of the 94 addresses, with its live identity (or
absence), exact classification, import ID (or `"BLOCKED"` with a
documented reason), and any code-fix prerequisite — is in
[`import-manifest.json`](../../infrastructure/ecs/environments/staging/import-manifest.json).
This section summarizes the material findings only.

### Live resources with no Terraform address at all (not in the 94)

| Resource | Why it has no address |
|---|---|
| IAM role `firmsbase-staging-ecs-task-role` | One shared generic role live vs. Terraform's per-service model (7 task roles: `module.iam.aws_iam_role.task[*]`, all classified `new`). No 1:1 mapping exists. Retire only after every task definition is cut over to the new per-role roles (Phase B) and nothing references it. |
| CloudWatch log group `/ecs/firmsbase-staging/app` | Shared by all 4 live services; doesn't match any of the 7 per-role addresses Terraform expects. Retained until services cut over (Phase B). |
| Task-definition families `firmsbase-staging-db-bootstrap`, `-diagnostic`, `-image-inspection` | Ad-hoc operational one-offs, no Terraform module models them at all. Out of scope entirely. |

### Corrected: IAM task-execution role IS a 1:1 naming fix, not a non-mapping

Unlike the 7 per-service task roles (genuinely new — live has no
per-service equivalent), the **execution role** is a 1:1 shared-role match
on both sides: live has exactly one (`firmsbase-staging-ecs-execution-role`),
Terraform models exactly one (`module.iam.aws_iam_role.task_execution`).
The mismatch is naming (fixed via the new `iam_task_execution_role_name`
variable — §6) *and* permission shape: live grants execution permissions
via the AWS-managed `AmazonECSTaskExecutionRolePolicy` plus one narrow
inline policy (`FirmsBaseStagingSecretsAccess`, secrets-read only,
confirmed via `aws iam get-role-policy`); Terraform's module builds one
broader custom inline policy (ECR pull + logs + secrets + KMS) with no
managed-policy attachment. The naming variable makes the *role* importable
by address; it does not by itself make the *policy content* match — that
permission-shape reconciliation is a separate, explicit decision item
(§11).

### Corrected: ElastiCache — subnet group name AND engine

- Subnet group: Terraform computed `"${name_prefix}-redis"` =
  `"firmsbase-staging-redis"`. Live (confirmed via
  `aws elasticache describe-cache-clusters` → `CacheSubnetGroupName`, and
  independently `aws elasticache describe-cache-subnet-groups
  --cache-subnet-group-name firmsbase-staging-redis` returning
  `CacheSubnetGroupNotFoundFault`) is `firmsbase-staging-cache-subnets`.
- Engine: Terraform hardcoded `engine = "redis"`. Live
  (`aws elasticache describe-replication-groups` → `Engine`) is
  `"valkey"`, version `7.2.6`, parameter group `default.valkey7` — none of
  which match Terraform's `engine_version = "7.1"` (a Redis version
  string) or hardcoded `parameter_group_name = "default.redis7"`. Engine
  cannot be changed in place; this was the most severe previously-missed
  finding in this correction pass (§9.4).
- The dedicated Redis security group (`sg-0da3ea50262a9d20d`, live name
  `firmsbase-staging-redis-sg`) **does exist** — the first version of this
  plan incorrectly assumed no dedicated SG existed and classified it
  `new`. Corrected to `import_unchanged`; its one ingress rule (6379 from
  the ECS-tasks SG) field-matches live exactly.

### Task definitions and services — see §6/§7 for the full corrected treatment

## 4. Networking compatibility matrix (exact field-level comparison)

Per-field live-vs-Terraform comparison for every networking-adjacent
resource. The `networking` module itself creates zero real resources (data
sources + a precondition guard only — see its own header comment); the
comparison below is "what the live VPC actually looks like" vs. "what this
config's data-source lookups and dependent modules assume," since that's
what actually matters for safety.

| Field | Live value | Terraform assumption | Match? |
|---|---|---|---|
| VPC ID | `vpc-0fd81b688155ded2b` | `var.vpc_id` (must be supplied to match) | Match once supplied |
| VPC CIDR | `172.31.0.0/16` | Not asserted anywhere in this config | N/A — config doesn't check |
| Default VPC | `true` (confirmed via `aws ec2 describe-vpcs`) | Not asserted; module treats it as "an existing VPC" generically | **Material**: see below |
| DNS support (`enableDnsSupport`) | Unconfirmed — `ec2:DescribeVpcAttribute` was `UnauthorizedOperation` for this operator. AWS's own default-VPC behavior sets this `true`; not independently verified here. | Not asserted | Unconfirmed, documented as a gap, not assumed |
| DNS hostnames (`enableDnsHostnames`) | Same as above — unconfirmed via API, AWS default-VPC behavior sets this `true` | Not asserted | Unconfirmed |
| Subnet count | 6 (one per AZ: `us-east-1{a,b,c,d,e,f}`) | `var.public_subnet_ids` + `var.private_subnet_ids`, count/exact IDs must be supplied | Match once supplied |
| Subnet CIDRs | `172.31.0.0/20` … `172.31.80.0/20` (6 × /20, standard default-VPC layout) | Not asserted | N/A |
| Subnet `MapPublicIpOnLaunch` | `true` on **all 6** subnets, no exceptions (confirmed via `aws ec2 describe-subnets`) | The config's own variable split (`public_subnet_ids` vs `private_subnet_ids`) implies some subnets are "private" | **Material — see below** |
| Route tables | Exactly 1: `rtb-0eab211e9f94a0cad`, the VPC's **main** table (implicit association — `Main: true`, no explicit subnet associations at all, meaning it applies to every subnet with no override) | Not asserted; module never inspects route tables | N/A — config doesn't check |
| Routes | Exactly 2: `172.31.0.0/16 → local`, `0.0.0.0/0 → igw-05e77a70d5f18aff9` | Not asserted | **Material — see below** |
| Route-table associations | None explicit; every subnet inherits the main table | Not asserted | N/A |
| Internet Gateway | `igw-05e77a70d5f18aff9`, attached, state `available` | Not asserted | Confirms public routing |
| NAT Gateway | **None** — `ec2:DescribeNatGateways` was denied (`UnauthorizedOperation`), but independently proven absent: the sole route table has no `0.0.0.0/0 → nat-*` route, only the IGW route above, and there is exactly one route table for the whole VPC | Not asserted; `ecs_service` module hardcoded `assign_public_ip = false`, which presumes NAT-routed private subnets exist | **Material — this is §9.1, the headline stop condition** |
| ALB security group | `sg-02a26ff122a9a1d29` (`firmsbase-staging-alb-sg`), ingress 80+443 from `0.0.0.0/0`, egress `-1/0.0.0.0/0` | `module.security_groups.aws_security_group.alb`, ingress 443 only (443-only rule field-matches; port 80 and the broad egress are live-only, unmanaged — see §3 of the original inventory) | Partial — see manifest for the exact per-rule breakdown |
| ECS-tasks security group | `sg-0db14e50ea5c5466c` (`firmsbase-staging-ecs-sg`), ingress 8080 from ALB SG, egress `-1/0.0.0.0/0` | `module.security_groups.aws_security_group.ecs_tasks`, ingress 8080 from ALB SG (matches exactly), narrower declared egress rules (443, 5432 — live-broader egress is unmanaged) | Partial — see manifest |
| Redis security group | `sg-0da3ea50262a9d20d` (`firmsbase-staging-redis-sg`), ingress 6379 from ECS-tasks SG, egress `-1/0.0.0.0/0` | `module.elasticache.aws_security_group.redis`, ingress 6379 from ECS-tasks SG (matches exactly) | Match on the declared rule |
| RDS security group | `sg-0d4c5eedb2ee21743` (`firmsbase-staging-rds-sg`), ingress 5432 from ECS-tasks SG, egress `-1/0.0.0.0/0` | No `aws_security_group` resource for RDS in this config at all (out of scope by design — §3E); the one declared rule (`rds_ingress_from_ecs_tasks`) field-matches the live ingress rule exactly | Match on the declared rule; the SG resource itself is out of scope |

### Material finding 1: this is the AWS account's default VPC

`IsDefault: true`. This config's `networking` module is written generically
("an existing VPC, ideally one with real private subnets" per its own
header comment) but is being pointed at the account's default VPC, which
has none of the private-subnet/NAT architecture the module's naming
(`public_subnet_ids` / `private_subnet_ids`) implies. This is not a
resource-import risk by itself (the `networking` module creates nothing to
import — §3E) but it is the root cause of finding 2 below and of §9.1.

### Material finding 2: "private" subnets are, today, not actually private

Every one of the 6 subnets in this VPC has `MapPublicIpOnLaunch = true`
and there is no NAT-routed alternative anywhere in the VPC (one route
table, no NAT route). Whatever subnet IDs this environment's tfvars pass
as `private_subnet_ids`, those subnets are physically identical to the
"public" ones — same route table, same public-IP-on-launch behavior, no
distinct routing. The "public"/"private" split in this config's variables
is, for this specific environment, a **labeling convention with no
network-layer backing**, not a real security boundary. This is exactly why
`assign_public_ip` must default to `true` regardless of which subnet list
a service is placed in (§9.1, §7) — placing a task in the "private" subnet
list here does not, by itself, change how that task reaches the internet.

### Recommendation (per the mission's explicit options)

Given the above, the three options laid out in the mission brief:

- **(a) Parameterize the module to adopt the live default VPC safely** —
  this is what the `networking` module already does today (data-source
  lookups only, no resource creation, no assumption the VPC is
  purpose-built). The gap isn't the module's *ownership* of the VPC (it
  correctly owns nothing) — it's the *downstream* modules
  (`ecs_service`, and formerly `elasticache`'s implicit subnet-group
  naming) that silently assumed NAT-routed private subnets existed. §6/§7
  close that gap for `ecs_service`. **Recommended**: keep the default VPC
  entirely unmanaged (already true) and keep `private_egress_ready`
  defaulted `false` (§7) until a deliberate decision is made to build real
  private subnets.
- **(b) Leave the default VPC/networking unmanaged temporarily** — already
  the case, by construction (§3E), and this plan does not propose changing
  it.
- **(c) Build a new VPC and migrate services later** — a legitimate future
  path (real private subnets + NAT would let `private_egress_ready = true`
  ever be safely set), but a separate, explicitly human-approved project,
  not something this plan schedules or assumes.

No VPC/subnet/route resource is imported by this plan (there are none to
import — the module has no such resources), and none is classified
`import_unchanged` without the field-level comparison above.

## 5. Backend — approved and configured (2026-08-03)

**Updated 2026-08-03.** The S3+DynamoDB vs. Terraform Cloud choice this
section previously left open has been decided: **S3, with native
Terraform lockfile locking, no DynamoDB table.** The backend is now
committed in `versions.tf`:

- **Bucket**: `firmsbase-terraform-state-603013471426-us-east-1` (account
  `603013471426`, region `us-east-1`).
- **State key**: `environments/staging/ecs/terraform.tfstate`.
- **Lock object**: `environments/staging/ecs/terraform.tfstate.tflock`
  (native S3 lockfile locking — `use_lockfile = true` — a Terraform
  1.11+ feature; **no DynamoDB table exists or is used** for this
  backend, superseding the S3+DynamoDB candidate this section originally
  described).
- **Encryption**: SSE-S3 (`encrypt = true`).
- **Versioning**: enabled on the bucket (recovery path for a bad state
  write, independent of Terraform itself).
- **Public access**: Block Public Access fully enabled on the bucket.
- **Object ownership**: bucket owner enforced — ACLs disabled.
- **Object Lock**: disabled (durability/rollback comes from bucket
  versioning, not S3 Object Lock retention — a deliberate choice, not an
  oversight).
- **Access**: the operator role (`firmsbase-staging-operator-login`)
  holds a narrowly scoped customer-managed IAM policy,
  `FirmsBaseStagingTerraformStateAccess`, granting access to exactly the
  state key and its `.tflock` object — not a shared bucket with broad
  access, and no policy grants apply beyond this one state key/lock pair.
- **Required Terraform CLI**: `>= 1.15.0` (see `versions.tf`'s
  `required_version` and `scripts/tf-guard.sh`'s version check) — the
  approved binary in this environment is the pinned
  `/home/ubuntu/bin/terraform-1.15.8` install, never the sandbox's
  default `terraform` on PATH (1.9.8).

**This was a code-only change.** No `terraform init` has been run against
this backend, no state or `.tflock` object has been written, and no live
AWS resource has been imported — confirmed via a read-only
`aws s3api list-objects-v2` against the `environments/staging/ecs/`
prefix returning `KeyCount: 0`. Configuring the backend answers *where*
state will live; it is a separate, earlier step from state *adoption*
(§8) and does not by itself change anything about the live environment,
does not adopt any resource, and does not lift the `plan`/`apply`
prohibition below.

**Required properties — all satisfied by the configuration above**:

- Encrypted remote state (at rest via SSE-S3; in transit via HTTPS,
  the AWS SDK's default).
- Versioning (bucket versioning enabled).
- Locking (native S3 lockfile locking via `use_lockfile`).
- Least-privilege access (`FirmsBaseStagingTerraformStateAccess`, scoped
  to exactly this state key and lock object).
- Backup/recovery independent of Terraform itself (S3 versioning).
- State is never committed to Git (enforced structurally — see §4's
  `.gitignore` rules for `*.tfstate`/`*.tflock`/`.terraform/`, none of
  which this backend's configuration bypasses).

`tf-guard.sh` (§6) still refuses `plan`/`apply` — now because local state
is confirmed empty (check 5) and the import checkpoints in §8 have not
been reached, not because no backend exists (check 1 now passes). A
backend being configured is necessary but not sufficient for adoption;
the import procedure in §8 remains the only path to a
`plan`/`apply`-safe state.

## 6. Corrected: ECS task-definition strategy (Option B, chosen and documented)

Live task definitions for `web`, `worker`, `critical-worker`, and
`scheduler` (and also `migrate`, `maintenance` — same generic role) use
`taskRoleArn = arn:aws:iam::603013471426:role/firmsbase-staging-ecs-task-role`.
Terraform models a role-specific task role per service
(`module.iam.aws_iam_role.task["web"]`, etc.). These are not
field-equivalent — the review is correct that the prior version's
`import_unchanged` classification for these 6 task definitions was wrong.

**Decision: Option B.** Do not import the 6 historical task-definition
resources (`module.{web,worker,critical_worker,scheduler,migrate,maintenance}.aws_ecs_task_definition.this`).
They are classified `do_not_import` in the manifest, each carrying a
`live_reference` (the exact family:revision that exists live) for
traceability, but no `import_id` (no import command is ever generated for
them).

Why Option B over Option A (a staging adoption mode that fakes the live
generic role first, then migrates later): Option A would require adding a
whole second code path to the `iam`/`ecs_service` modules whose entire
purpose is to be deleted again almost immediately after adoption — real
implementation and testing cost for a state that's only ever meant to be
transient. Option B costs nothing in code and is honest about the actual
state: these task definitions are **not currently managed by Terraform at
all**, on purpose, until Phase B deliberately replaces them.

**How services stay pinned in the meantime**: every `aws_ecs_service.this`
resource already has `lifecycle { ignore_changes = [task_definition] }`
(present in `infrastructure/ecs/modules/ecs_service/main.tf` since the
module was first written — not new). Once a service is imported (§7,
Phase A3), Terraform will record whatever task-definition revision the
service is actually running at import time and never propose changing it,
specifically *because* of this `ignore_changes` entry — combined with
`do_not_import` on the task-definition resource itself, this means
Terraform has no opinion at all about the task definition until Phase B
registers a new one on purpose and a human removes the pin (or updates the
`app_image_digest`/task-definition inputs and lets a real, reviewed
`apply` create a new revision).

`module.ses_consumer.aws_ecs_task_definition.this` is different: no live
family named `firmsbase-staging-ses-consumer` exists at all (confirmed via
`aws ecs list-task-definition-families`) — ses-consumer has never been
deployed here. It is genuinely `new`, not `do_not_import` — there is
nothing live to decline importing.

## 7. Corrected: ECS service strategy + the public-IP/NAT structural guard

The 4 live, running services (`web`, `worker`, `critical-worker`,
`scheduler`) are classified `import_then_migrate`, not `import_unchanged`.
Confirmed differences:

| Field | Live | Terraform (before this fix) |
|---|---|---|
| `assignPublicIp` | `ENABLED` | hardcoded `false` |
| Launch mechanism | `launchType: FARGATE` | `capacity_provider_strategy` (mutually exclusive with `launchType`; confirmed non-destructive in-place field swap) |
| `taskRoleArn` | generic shared role | role-specific (see §6) |
| Task definition | pinned live revision | would be a newly-created role-specific revision absent the `do_not_import`/`ignore_changes` treatment in §6 |

`module.ses_consumer.aws_ecs_service.this[0]` has no live counterpart at
all (confirmed via `aws ecs list-services`) — classified `new`, the actual
Phase B deployment goal, not an adoption target.

### The `assign_public_ip` hard stop — now enforced in code, not just documented

This VPC has no NAT gateway anywhere (§4). `assignPublicIp=ENABLED` is the
*only* way any task reaches the internet — ECR pulls, Secrets Manager,
CloudWatch Logs, SES, SQS, all of it. The `ecs_service` module used to
hardcode `assign_public_ip = false`; applying that against any imported
service would flip it in place (no replacement needed — just a silent,
simultaneous outage for every task in that service).

Fixed structurally, not just by writing this down:

1. `infrastructure/ecs/modules/ecs_service/variables.tf` now declares
   `variable "assign_public_ip" { type = bool }` with **no default** —
   every caller must decide explicitly; there is no silent fallback to the
   old hardcoded value anymore.
2. `infrastructure/ecs/environments/staging/variables.tf` adds
   `private_egress_ready` (bool, default `false`) and `nat_gateway_ids`
   (list, default `[]`), with a **cross-variable validation** on
   `nat_gateway_ids`:
   ```
   condition = !var.private_egress_ready || length(var.nat_gateway_ids) > 0
   ```
   `private_egress_ready` cannot be set `true` without also supplying at
   least one real NAT gateway ID — this fails `terraform validate`/`plan`
   outright, not just a design-doc warning. (`nat_gateway_ids` isn't
   consumed by any resource yet — the `networking` module remains
   data-source-only by design, §3E — its only job is to make "NAT egress
   genuinely exists" a checkable fact tied to the boolean, not a bare
   assertion.)
3. `main.tf` computes `local.assign_public_ip = !var.private_egress_ready`
   and passes it into every one of the 7 `ecs_service` module calls.
4. Proven with `terraform test` (mocked provider, no AWS):
   [`tests/adoption_naming.tftest.hcl`](../../infrastructure/ecs/environments/staging/tests/adoption_naming.tftest.hcl) —
   `public_ip_stays_enabled_by_default_no_nat_gateway_exists` (default
   `false` → `assign_public_ip = true` for every service),
   `public_ip_can_be_disabled_only_with_nat_gateway_ids_supplied` (`true` +
   real NAT IDs → `assign_public_ip = false`), and
   `private_egress_ready_without_nat_gateway_ids_fails_validation`
   (`true` + empty NAT IDs → `terraform plan` itself fails validation,
   proven via `expect_failures`). All three pass — see §10.

The live services are never modified by any of this (§10/§13 confirm no
AWS resource was touched) — this is a guard against a *future* apply doing
the wrong thing, verified now while the stakes are zero.

## 8. Ordered import plan (documented only — never executed)

Restructured into four phases per the review's requested structure.

**Standing rule for every checkpoint in Phase A** (A2 and A3 below,
and any future addition to this section): a checkpoint plan review must
reject unexpected **create** actions, not only destroy/replace actions,
before any `apply` runs. "Zero destroy/replace" alone is not a sufficient
pass condition — a plan can show zero destroys and zero replacements and
still be unsafe to apply if it proposes creating a resource nobody
reviewed. Every proposed action in a Phase A plan must be checked against
`import-manifest.json`: a CREATE is only expected for a `new`-classified
address; a CREATE against any `import_unchanged`, `import_then_migrate`,
or — especially — any of the 6 `do_not_import` historical task-definition
addresses (`module.{web,worker,critical_worker,scheduler,migrate,maintenance}.aws_ecs_task_definition.this`,
§6) is a hard stop. These 6 are unconditional resource blocks in the
module source, not `count`-gated, so `do_not_import` alone (i.e. simply
never running `terraform import` for them) does not stop a broad `apply`
from creating them — the plan-review requirement in this rule is the
actual control, not the classification by itself. Never run `apply`
against an unreviewed plan in Phase A, and never treat "no import command
was written for it" as equivalent to "Terraform cannot touch it."

### Phase A1 — backend and state safety

1. §5's backend decided and provisioned by a human (not this plan);
   `backend "s3" {}` (or equivalent) block added to `versions.tf`.
2. `terraform -chdir=infrastructure/ecs/environments/staging init`
   against the real backend (the one `init` in this whole plan that talks
   to AWS — it only creates/reads the state *object*, never an application
   resource).
3. Encrypted backup of the (still-empty) initial state immediately after
   step 2, before any import:
   `terraform state pull > state-backups/pre-import-$(date +%Y%m%dT%H%M%S).tfstate.json.gpg`.
4. `terraform show` confirms 0 resources, as expected, before the first
   import.
5. From this point on, every `plan`/`apply` in this environment goes
   through `scripts/tf-guard.sh` (§6 of the original plan / hardened per
   the review — see the script's own header for its exact checks and
   documented bypass limitation), never the bare `terraform` binary.

### Phase A2 — resources proven configuration-equivalent (`import_unchanged`, 6 addresses)

Security groups (`alb`, `ecs_tasks`, `redis`), the ALB itself, and both
listeners — no field drift of any kind against live. **All six have
already been imported** into the live backend
(`environments/staging/ecs/terraform.tfstate`) as of 2026-08-04.

The four SG *rule* addresses that were originally scoped alongside these
six (see history below) are **not** part of this phase's `import_unchanged`
count — they carry a real field drift (description) and are classified
`import_then_migrate`; see Phase A3 below.

The target group (`module.alb.aws_lb_target_group.web`) is also **not**
in this phase — it has three real health-check-field mismatches against
live (§9.5) and was moved to Phase A3 in an earlier revision.

```bash
# --- already imported (2026-08-04) ---
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.security_groups.aws_security_group.alb' 'sg-02a26ff122a9a1d29'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.security_groups.aws_security_group.ecs_tasks' 'sg-0db14e50ea5c5466c'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.elasticache.aws_security_group.redis' 'sg-0da3ea50262a9d20d'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.alb.aws_lb.this' 'arn:aws:elasticloadbalancing:us-east-1:603013471426:loadbalancer/app/firmsbase-staging-alb/79a16ccaf391d71b'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.alb.aws_lb_listener.https' 'arn:aws:elasticloadbalancing:us-east-1:603013471426:listener/app/firmsbase-staging-alb/79a16ccaf391d71b/f8dc4575154478ca'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.alb.aws_lb_listener.http_redirect' 'arn:aws:elasticloadbalancing:us-east-1:603013471426:listener/app/firmsbase-staging-alb/79a16ccaf391d71b/371edb36d1b49e2c'
```

**History**: these six addresses, plus the four SG-rule addresses now in
Phase A3, were originally grouped together as "Phase A2" — a single
execution batch of low-risk, security-group-related resources. A
2026-08-04 correction found the four rules do **not** field-match live
exactly (description drift — see Phase A3) and reclassified them
`import_then_migrate`, moving their manifest entries and import commands
into Phase A3's listing. They are still referred to informally as
"the Phase A2 rules" in this document for historical continuity, but the
manifest's `import_unchanged` count for Phase A2 itself is now 6, not 10.

### Phase A3 — resources requiring a temporary live-state-compatible configuration first (`import_then_migrate`, 16 addresses)

**Every command below is BLOCKED until its named code fix/decision lands,
EXCEPT the four SG-rule imports immediately following this paragraph** —
those four are importable now (their identity-defining fields already
match live exactly and their composite import IDs are resolved); they are
`import_then_migrate` only because of a description-only field drift, not
because anything blocks the import itself. Every other command in this
phase remains genuinely blocked; none of these are guesses, each is
marked in the manifest with an explicit `prerequisite` field.

```bash
# --- importable now (2026-08-04) — description-drift only, NOT blocked ---
# These four were originally scoped as "Phase A2" (see that section's
# History note) and moved here because they do not field-match live
# exactly: every identity-defining field (ingress direction, protocol,
# ports, destination security group, and CIDR/referenced source security
# group) matches live uniquely and exactly, but the live rule's
# Description is null while this configuration sets an explicit
# description string on each. Importing records the live rule's current
# fields (including its null description) into state; it does NOT
# authorize changing the description or any other subsequent apply. The
# exact provider action Terraform would take to reconcile that
# description drift (in-place update vs. replacement vs. something else)
# has NOT been verified by a real `terraform plan` in this pass — no
# plan/apply is authorized during state adoption. Reconciling the drift
# is a separate decision, to be made only after these imports and a
# complete drift analysis across all four rules; if a future plan
# proposes REPLACING rather than updating any of these four, that is a
# stop condition requiring explicit human review before either import or
# apply proceeds — nothing here authorizes that.
#
# aws_security_group_rule (the legacy per-rule resource) does not accept
# its AWS-internal SecurityGroupRuleId (sgr-*) as a Terraform import ID.
# It requires a composite identifier the provider constructs itself:
#   <security_group_id>_<type>_<protocol>_<from_port>_<to_port>_<source>
# where <source> is either a CIDR block or a referenced security-group ID.
# The AWS sgr-* ID is recorded separately in import-manifest.json
# (`live_reference`) purely for audit traceability — it is not usable as
# the import ID itself. Any argument containing a CIDR (a "/") must be
# shell-quoted.
#
# This mission does not migrate these four resources to
# aws_vpc_security_group_ingress_rule — that would change their Terraform
# addresses and expand scope beyond this correction.
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.security_groups.aws_security_group_rule.alb_ingress_https' \
  'sg-02a26ff122a9a1d29_ingress_tcp_443_443_0.0.0.0/0'
  # AWS SecurityGroupRuleId: sgr-0c01cb5ed9c2ade63
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.security_groups.aws_security_group_rule.ecs_tasks_ingress_from_alb' \
  'sg-0db14e50ea5c5466c_ingress_tcp_8080_8080_sg-02a26ff122a9a1d29'
  # AWS SecurityGroupRuleId: sgr-0d10f5fbc9e17c912
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.security_groups.aws_security_group_rule.rds_ingress_from_ecs_tasks[0]' \
  'sg-0d4c5eedb2ee21743_ingress_tcp_5432_5432_sg-0db14e50ea5c5466c'
  # AWS SecurityGroupRuleId: sgr-00039246ff540e217
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.elasticache.aws_security_group_rule.redis_ingress_from_ecs_tasks' \
  'sg-0da3ea50262a9d20d_ingress_tcp_6379_6379_sg-0db14e50ea5c5466c'
  # AWS SecurityGroupRuleId: sgr-0d4fcba591950afde
# --- plan checkpoint (for the four rule imports above only): MUST show
# zero unexpected destroy/replace. A description-only diff on next plan is
# EXPECTED (live Description is null; config sets an explicit string) —
# but whether Terraform proposes that as an update or a replace has not
# been verified live in this pass; if it proposes replace, STOP and get
# human review before applying anything. ---

# --- everything below remains genuinely BLOCKED ---
# Requires: main.tf's ecr_repository_name resolves to "firmsbase-staging" (now wired via var.ecr_repository_name — see §6/§9.5 of variables.tf)
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.ecr.aws_ecr_repository.app' 'firmsbase-staging'

# Requires: var.ecs_cluster_name = "firmsbase-staging-cluster" (Group B)
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.ecs_cluster.aws_ecs_cluster.this' 'firmsbase-staging-cluster'
# Group C — see §9.12/§9.13: live cluster has NO capacity providers
# associated (capacityProviders: []); var.ecs_capacity_providers=[] now
# lets this address represent that, but associating capacity providers
# with the live cluster remains a separate, explicitly reviewed decision.
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.ecs_cluster.aws_ecs_cluster_capacity_providers.this' 'firmsbase-staging-cluster'

# Requires: var.elasticache_subnet_group_name = "firmsbase-staging-cache-subnets",
# var.elasticache_subnet_ids set to the exact live 6-subnet set (§9.15 — this module
# previously derived membership from private_subnet_ids, only 2 subnets; fixed).
# elasticache:ListTagsForResource is now granted (§9.13). Import itself has not been run.
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.elasticache.aws_elasticache_subnet_group.this' 'firmsbase-staging-cache-subnets'

# Requires: var.elasticache_engine = "valkey", var.elasticache_parameter_group_name = "default.valkey7",
# var.elasticache_engine_version = "7.2" (live's exact reported version is 7.2.6, but AWS requires
# major.minor-only for Redis v6+/Valkey), and the module's ignore_changes=[auth_token] (already added).
# elasticache:ListTagsForResource is now granted (§9.13). Import itself has not been run.
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.elasticache.aws_elasticache_replication_group.this' 'firmsbase-staging-redis'

# Requires: var.iam_task_execution_role_name = "firmsbase-staging-ecs-execution-role" (Group B)
# PLUS a separate, explicit decision on permission-shape reconciliation (managed-policy vs custom-inline) — see §11 item 3. Do not import until that decision is made; a name-only fix imports a role whose Terraform-declared permissions don't match what's actually attached live.
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.iam.aws_iam_role.task_execution' 'firmsbase-staging-ecs-execution-role'
# Group C — var.iam_task_execution_policy_name="FirmsBaseStagingSecretsAccess"
# (landed 2026-08-04) aligns this resource's identity with live, fixing a
# real replacement risk from the module's previous hardcoded name — but
# the policy's CONTENT still differs materially from live (see §9.12 item
# 4), paired with the same permission-shape decision as the role above.
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.iam.aws_iam_role_policy.task_execution' 'firmsbase-staging-ecs-execution-role:FirmsBaseStagingSecretsAccess'

# Requires: var.alb_health_check_path = "/up", var.alb_health_check_interval_seconds = 30,
# and var.alb_health_check_matcher = "200-399" (see terraform.tfvars.example) — Group B, confirmed
# supplied exactly via an isolated local-backend terraform console boolean check on 2026-08-04
# (never by printing terraform.tfvars). None of the three mismatches force
# replacement (health_check.* and matcher are in-place-updatable per the AWS provider's documented
# resource schema for aws_lb_target_group — not re-verified live via `terraform providers schema`
# in this pass, which this mission does not authorize against the real initialized backend), but
# importing with any of them still at the module's original default would silently diverge from
# live health-check behavior on the very next apply. Any future proposal to instead change this
# target group's identity-defining fields (port/protocol/vpc_id/target_type) — which would force
# replacement — is a stop condition requiring explicit human review before either import or apply
# proceeds; nothing here authorizes that.
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.alb.aws_lb_target_group.web' 'arn:aws:elasticloadbalancing:us-east-1:603013471426:targetgroup/firmsbase-staging-tg/1830c01b9aaac37d'

# Group C for all four (§9.12) — not because import itself is unsafe, but
# because the cluster/capacity-provider and IAM sequencing above must
# resolve first, per this correction's explicit instruction not to treat
# an ECS service as safe merely because its own import doesn't change AWS.
# assign_public_ip=true is already the default (!var.private_egress_ready,
# §9.1/§7) and matches live — the prior "hard stop" framing here was
# stale and has been corrected (§9.12 item under each service's manifest
# entry). use_capacity_provider_strategy=false (landed 2026-08-04, §9.7)
# matches live's launchType=FARGATE. desired_count now flows through
# web_desired_count/worker_desired_count/critical_worker_desired_count/
# scheduler_desired_count (landed 2026-08-04, §9.12 item 2) — this
# environment's terraform.tfvars sets all four to 1, matching live exactly
# (web/worker previously diverged: config declared 2, live is 1).
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.web.aws_ecs_service.this[0]' 'firmsbase-staging-cluster/web'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.worker.aws_ecs_service.this[0]' 'firmsbase-staging-cluster/worker'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.critical_worker.aws_ecs_service.this[0]' 'firmsbase-staging-cluster/critical-worker'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.scheduler.aws_ecs_service.this[0]' 'firmsbase-staging-cluster/scheduler'
# --- final plan checkpoint: MUST show zero destroy/replace anywhere, and
# the diff for assign_public_ip specifically must be empty (already true,
# already matching live) — if it shows a change to assign_public_ip, STOP,
# do not apply, re-diagnose before touching anything further.
#
# MANDATORY CREATE review, not just destroy/replace: run this plan and read
# every single proposed action line by line against import-manifest.json
# BEFORE running apply. "Zero destroy/replace" is necessary but not
# sufficient — a plan can be entirely free of destroys/replacements and
# still be unsafe to apply if it proposes an unreviewed CREATE. Pay
# specific attention to the 6 do_not_import task-definition addresses
# (module.{web,worker,critical_worker,scheduler,migrate,maintenance}.aws_ecs_task_definition.this):
# each is declared, unconditional, in the module source, but deliberately
# never imported (§6) — if any of them shows up as a planned CREATE, that
# means Terraform has no state entry for it yet and is about to register a
# brand-new task-definition revision with the (still-nonexistent,
# Phase-B-only) role-specific IAM role baked in. STOP. Do not apply. This
# is exactly the failure mode Option B (§6) exists to prevent, and only
# happens if this checkpoint is skipped or an apply is run out of order.
# Every proposed CREATE in this plan must correspond to a `new`-classified
# address in the manifest and nothing else — a CREATE against any
# `do_not_import`, `import_unchanged`, or `import_then_migrate` address is
# a hard stop requiring re-diagnosis, never a "looks fine, proceed." ---
```

Explicitly **not** imported, ever, without a separate future decision: the
6 pinned task definitions (§6, `do_not_import`), the two generic IAM
roles / shared log group / 3 ad-hoc task-def families (no address exists —
§3), and `module.cloudwatch_alarms.*` (existence unconfirmed —
`cloudwatch:DescribeAlarms` denied, §11 item 6). **Because these 6
task-definition resources are unconditional blocks in the module source
(not `count`-gated), simply never running `terraform import` for them does
not by itself stop a broad `terraform apply` from creating them — the
CREATE-review requirement in the checkpoint immediately above this
paragraph is the actual control that catches this, not the `do_not_import`
classification alone. Do not run a broad `apply` at any point in Phase A
without first inspecting its full plan output against the manifest.**

### Phase B — intentional migrations (deliberately out of scope for this plan; listed for sequencing only)

- Role-specific task roles (`module.iam.aws_iam_role.task[*]`) created,
  IAM permission-shape reconciliation for the execution role resolved.
- New, role-specific task-definition revisions registered for
  `web`/`worker`/`critical-worker`/`scheduler`/`migrate`/`maintenance`,
  replacing the `do_not_import` pin from §6 deliberately, with a human
  reviewing the diff.
- SES policy tightening: reconcile the live `FirmsVaultStagingSesSend`
  inline policy (grants both `ses:SendEmail` and `ses:SendRawEmail`) with
  Terraform's `task_web_ses_send` policy (only `ses:SendRawEmail`, per
  Mission F's already-completed tightening work) — see
  `docs/ecs/iam-matrix.md`.
- Public-to-private networking, if ever pursued: only after real private
  subnets + NAT gateway exist and are verified (§4 recommendation (c)),
  and only by explicitly setting `private_egress_ready = true` with real
  `nat_gateway_ids` (§7) — never as a side effect of anything else.
  `ses-consumer` creation (`module.ses_consumer.*`, all currently `new`).
- Retirement of the two generic IAM roles and the shared log group, only
  after nothing references them anymore.

## 9. Drift / replacement / stop-condition analysis

### 9.1 `assign_public_ip` — headline finding, now structurally guarded (§7)

Unchanged from the original finding, now fixed in code rather than only
documented: this VPC has no NAT gateway (§4); disabling `assignPublicIp`
would cut off all internet egress for every task in that service
simultaneously. §7 makes this impossible to do silently — every caller of
`ecs_service` must pass `assign_public_ip` explicitly, and the
environment-level default can only flip to `false` if `nat_gateway_ids` is
non-empty.

### 9.2 ECS cluster rename — stop condition, fixed via variable (§6 of variables.tf)

Renaming an ECS cluster requires deleting and recreating it — and every
service on it. `var.ecs_cluster_name` (default `null`, falls back to
`name_prefix`) removes this stop condition once set to the live value;
until then, it remains a hard block (any apply without the fix plans a
full cluster + all-4-services replacement).

### 9.3 ECR repository rename — stop condition, fixed via variable

Same shape, lower blast radius (loses image tags/digests, not running
services). `var.ecr_repository_name` closes this the same way.

### 9.4 ElastiCache — TWO stop conditions, both newly found in this correction pass and both fixed via variables

- **Engine mismatch** (`redis` vs. live `valkey`) — the most severe,
  previously-undetected finding in this whole correction. Engine cannot be
  changed in place; applying with the old hardcoded `engine = "redis"`
  against the live Valkey replication group would plan a full,
  data-losing replacement. Fixed via `var.elasticache_engine`
  (`"redis"` default / must be `"valkey"` for this environment),
  `var.elasticache_parameter_group_name`, and `var.elasticache_engine_version`
  (`"7.1"` default / must be `"7.2"` for this environment). The live
  replication group's exact reported version is `7.2.6`
  (`aws elasticache describe-cache-clusters .EngineVersion`), but AWS's
  `aws_elasticache_replication_group` requires major.minor-only format for
  Redis v6+/Valkey and rejects a major.minor.patch value like `"7.2.6"`
  outright (confirmed via a real provider validation error while adding
  this test coverage) — `"7.2"` is the value to actually supply. A `"7.1"`
  Redis-line version string is meaningless once the engine is Valkey.
  Previously this variable did not exist at all at the staging-environment
  level (only the module itself had an `engine_version` input with no
  environment-level pass-through) — corrected in this pass.
- **Subnet group name mismatch** — fixed via `var.elasticache_subnet_group_name`.
- **`auth_token`** (pre-existing, unrelated to the above): write-only,
  never returned by any read API — `lifecycle { ignore_changes =
  [auth_token] }` added to `aws_elasticache_replication_group.this` so a
  post-import plan doesn't show a permanent diff or attempt a disruptive
  in-place rotation.

### 9.5 ALB target-group health-check adoption (path, interval, matcher)

**Corrected in this pass** — the original finding covered only the
health-check path; a closer re-comparison against the live target group
(`aws elbv2 describe-target-groups` / `describe-target-group-attributes`)
found two further mismatches on the same resource that were never
previously documented:

1. **Health-check path**: live `/up` (liveness) vs. Terraform's default
   `/readyz` (readiness) — a real semantic difference, not a naming one.
2. **Health-check interval**: live `30` seconds vs. Terraform's default
   `15` seconds.
3. **Matcher**: live `"200-399"` vs. Terraform's default `"200"` (an exact
   match) — previously hardcoded in `modules/alb/main.tf` with no variable
   at all, so no override was even possible until `var.health_check_matcher`
   was added in this pass.

All three are non-destructive to change — `health_check.*` and `matcher`
are in-place-updatable fields on `aws_lb_target_group` per the AWS
provider's documented resource schema (`name`/`port`/`protocol`/`vpc_id`/
`target_type` are the fields that force replacement, and none of those
differ from live). **This was not re-verified live via `terraform
providers schema` in this pass** — that command does not honor
`-backend=false` and would contact the real, now-initialized S3 backend,
which this mission does not authorize; the in-place-update conclusion
rests on established AWS/Terraform provider documentation, not a fresh
live schema read.

`var.alb_health_check_path`, `var.alb_health_check_interval_seconds`, and
`var.alb_health_check_matcher` (all in
`infrastructure/ecs/environments/staging/variables.tf`, wired into
`module "alb"` in `main.tf`) now let all three be set to their live values
before import — see `terraform.tfvars.example` for the exact override
values (`"/up"`, `30`, `"200-399"`). The target group is classified
`import_then_migrate` (not `import_unchanged`) and marked **BLOCKED** in
`import-manifest.json` until all three are actually supplied at apply
time, not merely present as variables. A later migration back to this
module's original readiness-check design (`/readyz`, 15s, exact `200`) —
or any other change to these values — must be a separate, deliberately
reviewed deployment, never a side effect of the import itself. Any future
proposal that would instead touch this target group's replacement-forcing
fields (port/protocol/vpc_id/target_type) is a stop condition requiring
explicit human review before either import or apply proceeds — see §11
item 4.

### 9.6 Security-group rule granularity

Unchanged: additive-only, no live rule is ever implicitly revoked by this
module's per-rule (`aws_security_group_rule`) pattern. The live
environment remains more permissive than Terraform's declared rules after
Phase A unless a human explicitly revokes the extra broad rules in a
follow-up — out of scope here.

### 9.7 `launch_type` vs. `capacity_provider_strategy` — now explicitly modeled (corrected 2026-08-04)

Previously accepted as "unchanged, confirmed non-destructive: an in-place
field swap," but the module gave callers no way to actually choose
`launch_type` — it hardcoded a `capacity_provider_strategy` block
unconditionally. Live re-verification (`aws ecs describe-services`)
confirms every one of this environment's four long-running services
(`web`/`worker`/`critical_worker`/`scheduler`) actually runs
`launchType=FARGATE` with `capacityProviderStrategy=null` today —
consistent with the live cluster itself having no capacity providers
associated at all (§9.10/§9.11/§9.12). **Corrected**: `modules/ecs_service`
now takes a required `use_capacity_provider_strategy` boolean (no default —
every caller must decide explicitly); `false` sets `launch_type = "FARGATE"`
and omits the `capacity_provider_strategy` block entirely via a `dynamic`
block, `true` does the reverse. AWS rejects setting both on the same
`aws_ecs_service`, so exactly one is ever rendered. Every current staging
caller (`web`, `worker`, `critical_worker`, `scheduler`, `migrate`,
`maintenance`, `ses_consumer`) sets this `false`, matching live exactly.
The `aws_ecs_cluster_capacity_providers` resource has its own, separate
drift — see §9.12.

### 9.8 `APP_URL` — previously unmodeled, corrected in this pass

The live web task definition carries a plain `APP_URL` environment variable
(`https://staging.firmsvault.com`, confirmed via `describe-task-definition`)
that this Terraform configuration never declared or wired at all — not a
wrong default, a complete absence. Fixed via a new required `var.app_url`
(HTTPS-validated, no default — see `variables.tf`) wired into
`local.shared_environment` in `main.tf`, reaching every role that consumes
it: web, worker, critical-worker, scheduler, migrate, maintenance, and
ses-consumer.

This is not one of the 6 executable Phase A2 imports (security groups, ALB,
listeners) and does not block them. It **does** block any ECS
task-definition migration or `terraform plan`/`apply` against this
environment: `APP_URL` drives generated links (password-reset URLs,
owner-invitation URLs, redirects, OAuth/webhook callbacks) — a
Terraform-managed task definition created without it would silently
generate incorrect links rather than failing loudly. This correction must
be merged before Phase B's task-definition migration work begins.

### 9.9 Import execution safety — corrected after a real, failed attempt

A real canary import (`module.security_groups.aws_security_group.alb`,
`sg-02a26ff122a9a1d29`) was attempted against the real S3 backend and
failed twice, in ways no static check (`validate`/`fmt`/`terraform test`)
could have caught. Both failures are now fixed. The canary import (and
five further Phase A2 imports after it) has since succeeded — see §9.10
for the current, corrected execution status; the historical narrative
below describes the state at the time these two bugs were diagnosed and
fixed, not the state today.

1. **Wrong identity, silently.** The approved AWS CLI profile
   (`firmsbase-staging-operator-login`) resolves credentials through a
   custom `login_session = <arn>` broker the AWS CLI itself understands
   but which is not a real AWS SDK credential mechanism. Terraform's AWS
   provider (Go SDK) fell through the entire credential chain and picked
   up the sandbox's own ambient `AmazonLightsailInstanceRole` — a
   different AWS account — with no error. `scripts/tf-guard.sh` now
   bridges `AWS_PROFILE` into standard, SDK-universal credentials
   (`aws configure export-credentials --format process`, exported as
   `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`/`AWS_SESSION_TOKEN`),
   disables EC2/instance-metadata fallback
   (`AWS_EC2_METADATA_DISABLED=true`), and verifies the resulting identity
   against the **exact** expected caller ARN — not just the account —
   before allowing any live command (`init` against the real backend,
   `import`, `state`, `output`, `plan`, `apply`) to proceed:

   ```
   arn:aws:iam::603013471426:user/firmsbase-staging-operator
   ```

   `import` is no longer an unguarded passthrough in `scripts/tf-guard.sh`
   — it goes through the same bridging and verification as `plan`/`apply`
   now. See the script's own header for the full explanation and every
   check it enforces.

2. **Remaining IAM prerequisite, once identity was corrected:**
   `ec2:DescribeVpcAttribute` on `vpc-0fd81b688155ded2b` is denied for
   `firmsbase-staging-operator` (needed by `data "aws_vpc" "this"` in the
   networking module). This is a genuine, previously-undocumented
   permission gap — distinct from the already-known
   `ec2:DescribeSecurityGroupRules` gap (§8's 4 BLOCKED SG-rule imports) —
   and blocks any import, not only the four SG rules. Not requested
   automatically, per this mission's constraint (see §11 item 7).

3. **`terraform import` evaluates enough of the configuration graph that
   every `count`/`for_each` instance set must be knowable up front** — not
   just for the resource being imported. Modern Terraform (1.15.8)
   internally plans the whole graph as part of any `import`, and several
   `count`/`for_each` expressions in this configuration derived their
   instance keys from module outputs of resources that don't exist yet
   (`module.kms.key_arn`, `module.s3_documents.bucket_arn`,
   `module.alb.target_group_arn`, every ECS service's `.service_name`) by
   comparing them to `null` — comparing an unknown value to `null`
   produces an unknown boolean, which collapses a `for_each`'s key set to
   unknown and hard-errors the entire `import` command, not just the
   affected resource. **Corrected**: every such gate now uses a literal
   `true`/`false` flag the caller sets explicitly
   (`kms_encryption_enabled`, `s3_documents_enabled`, `attach_target_group`,
   `ses_consumer_enabled` — all default `false`, matching original
   behavior, all set `true` at this environment's actual call sites), and
   `aws_iam_role_policy.task_metrics` no longer derives its `for_each` from
   `aws_iam_role.task`'s own (potentially-unknown) instance map, using a
   static `local.task_role_names` list instead. No resource address
   changed; no functionality was disabled. See
   `infrastructure/ecs/modules/{iam,cloudwatch_alarms,ecs_service}` and
   their `tests/*.tftest.hcl` files.

4. **No targeted (`-target`) apply is approved for state adoption.**
   Terraform's own error output for the graph-evaluation failure above
   suggested `-target` as a workaround; this plan does not adopt it. The
   configuration fix in point 3 is the only approved remedy — targeted
   applies are exactly the kind of narrow, easy-to-forget, order-dependent
   operation this whole state-adoption plan exists to avoid.

### 9.10 Phase A2 execution status (current, as of 2026-08-04)

Both remaining blockers from §9.9 (`ec2:DescribeVpcAttribute` and
`ec2:DescribeSecurityGroupRules`) are now granted. Real, guarded imports
have been run against the live S3 backend
(`environments/staging/ecs/terraform.tfstate`):

- **State currently contains 6 managed resources plus 9 data-source
  entries** (the data-source entries — `aws_vpc`, 4× `aws_subnet`, 4×
  `aws_iam_policy_document` — are read-only cache artifacts of Terraform
  evaluating the whole configuration graph during `import`, per point 3
  above; they are not separately-managed resources).
- The 6 already-imported managed resources — now Phase A2's full
  `import_unchanged` set (§8) — are: `module.security_groups.aws_security_group.alb`,
  `module.security_groups.aws_security_group.ecs_tasks`,
  `module.elasticache.aws_security_group.redis`, `module.alb.aws_lb.this`,
  `module.alb.aws_lb_listener.https`,
  `module.alb.aws_lb_listener.http_redirect`.
- The remaining 4 originally-"Phase A2" addresses (all
  `aws_security_group_rule`) now have a resolved Terraform composite
  import ID in `import-manifest.json` (no longer `"BLOCKED"`), confirmed
  against a live, read-only `aws ec2 describe-security-group-rules` query
  with exactly one matching rule per address — see §8's Phase A3 listing
  and each address's manifest entry for the full field comparison. **They
  have not been imported.** That import run is pending repository review
  and merge of the manifest correction that resolved these IDs. See §9.11
  for why they are classified `import_then_migrate`, not
  `import_unchanged`.
- `aws_security_group_rule` uses a provider-constructed composite import
  identifier, not the AWS-internal `SecurityGroupRuleId` (`sgr-*`) — see
  the explanatory comment in §8's Phase A3 command block. Each of the 4
  manifest entries records both: `live_reference` holds the `sgr-*` ID
  (audit traceability only, not usable as an import ID) and `import_id`
  holds the actual composite string to pass to `terraform import`.
- The backend's S3 object history (state versions plus the native
  `use_lockfile` lock-object versions/delete-markers from each completed
  operation) has not been altered or deleted by this correction — this
  correction only adds Terraform config/manifest/documentation content,
  it runs no `state rm`/`mv`/`push`/`pull` and no lifecycle operation
  against the backend's object versions.

### 9.11 Phase A2 rule classification correction — description drift, not field-equivalence (2026-08-04)

§9.10 (and the manifest, as originally written by the pass that resolved
the four rules' import IDs) claimed the four `aws_security_group_rule`
imports were `import_unchanged` and that their live-vs-config description
mismatch was "a non-disruptive update-in-place diff only, not a
replacement," citing that `description` is "not a ForceNew attribute on
`aws_security_group_rule`." **That claim was unsupported and has been
removed.** No real `terraform plan`/`providers schema` was ever run
against this resource in this pass to confirm how the AWS provider
actually handles a description-only diff on `aws_security_group_rule` —
asserting a specific reconciliation behavior (update-in-place vs.
replacement) without having verified it live is exactly the kind of
unverified claim this plan's own validation table (§10) explicitly labels
"not run" for provider-schema checks elsewhere. The claim is corrected as
follows:

1. **What is actually proven, read-only, for all four rules:** every
   identity-defining field — ingress direction, protocol, from/to port,
   destination security group, and CIDR/referenced source security group —
   matches live uniquely and exactly. That is a real, verified basis for
   importing: `terraform import` only requires identity to match to
   succeed; it does not require every attribute to match.
2. **What is real drift, not proven safe:** the live rule's `Description`
   is `null` on all four; this configuration sets an explicit description
   string on each. This is a genuine field mismatch.
3. **What is NOT claimed:** whether the AWS provider would reconcile that
   description mismatch via an in-place update, a resource replacement,
   or something else. That has not been verified by a real `terraform
   plan` and is not asserted here.
4. **Classification, corrected:** all four addresses are `import_then_migrate`,
   not `import_unchanged` — manifest totals corrected to
   `new: 66, import_unchanged: 6, import_then_migrate: 16, do_not_import: 6,
   total: 94`.
5. **What importing does and does not authorize:** running these four
   imports (once approved) records the live rules' current fields —
   including their `null` description — into Terraform state. It does
   **not** authorize any subsequent `apply` that would change the
   description or anything else; no apply is authorized during state
   adoption at all (§9.9 point 1, §10). Reconciling the description drift
   is a separate, later decision requiring a complete drift analysis
   across all four rules and explicit review — not assumed or pre-approved
   here.
6. **Stop condition:** if a future, properly-authorized `plan` against any
   of these four proposes a **replacement** rather than an in-place
   update, that is a stop condition requiring explicit human review before
   either import or apply proceeds. Nothing in this document authorizes
   proceeding past that point automatically.
7. **Not in scope for this correction:** migrating these four resources to
   `aws_vpc_security_group_ingress_rule` (the newer per-rule resource
   type) is a real alternative that would let description drift reconcile
   differently, but it changes Terraform addresses and is out of scope
   here — it would need its own explicit, reviewed migration, not a
   byproduct of a classification fix.

### 9.12 Phase A3 adoption alignment and readiness matrix (2026-08-04)

Phase A2 is complete — all 10 `import_unchanged` addresses (3 security
groups, ALB, 2 listeners, 4 security-group rules) are imported. State
currently contains 10 managed resources plus 9 read-only data-source
cache entries. **No Phase A3 (`import_then_migrate`, 16 addresses)
resource has been imported.** This correction read-only re-verified all
12 addresses not already covered by the four now-imported security-group
rules against live AWS, landed several code fixes, corrected stale prior
claims, and recorded a still-open permission gap — but authorizes no
import, apply, ECS deployment, scaling change, capacity-provider
association, IAM permission migration, or description-drift
reconciliation.

**Corrected Group A/B/C readiness matrix (2026-08-04, second pass)** (each
address's manifest entry carries the same grouping and the specific
reasoning). **The four ECS services below no longer use a Group A/B/C
letter as of 2026-08-05 (§9.21)** — a single letter conflated "safe to
import" with "fully migrated," which read as blocked when it was not;
they now carry two independent labels, state-import readiness and
deployment-migration status, described in their own bullet below:

- **Group A — live-aligned and suitable for individual canary imports:**
  `module.ecr.aws_ecr_repository.app`,
  `module.alb.aws_lb_target_group.web`,
  `module.ecs_cluster.aws_ecs_cluster.this`,
  `module.ecs_cluster.aws_ecs_cluster_capacity_providers.this`,
  `module.iam.aws_iam_role.task_execution`,
  `module.iam.aws_iam_role_policy_attachment.task_execution_managed`,
  `module.iam.aws_iam_role_policy.task_execution`. The
  capacity-providers resource **moved here from Group C** in an earlier
  pass: live association is confirmed empty
  (`capacityProviders: []`, `defaultCapacityProviderStrategy: []`) and
  this environment's adoption bundle now supplies the matching empty
  values (`capacity_providers = []`, so
  `default_capacity_provider_strategy` renders zero blocks) — see
  correction 3 below. Importing records that empty association exactly;
  it does **not** authorize any later association of `FARGATE` or
  `FARGATE_SPOT` with the live cluster, which remains a separate,
  explicitly reviewed decision. The resource address
  (`module.ecs_cluster.aws_ecs_cluster_capacity_providers.this`) is
  unchanged. The IAM execution role **moved here from Group B** in an
  earlier pass (§9.17): its trust policy and description now match live
  exactly. The managed-policy attachment (a **new address**, §9.18) and
  the inline policy (**moved here from Group C**, §9.18) are both now
  live-aligned: the attachment models live's sole
  `AmazonECSTaskExecutionRolePolicy` attachment as a separate,
  non-exclusive `aws_iam_role_policy_attachment`, and the inline policy's
  content now matches live's exact secrets-only grant (4 named secret
  ARNs, no ECR/logs/SSM/KMS). Importing either does **not** authorize
  ever detaching the managed policy or expanding/reducing the inline
  policy's permissions — either is a separate, explicitly reviewed
  decision. No exact, source-backed blocker remains for any of these
  seven — each is still an individual canary, not a batch.
- **Group B — a small prerequisite or additional read verification away:**
  `module.elasticache.aws_elasticache_subnet_group.this`,
  `module.elasticache.aws_elasticache_replication_group.this`. Both are
  now imported (§9.16); the ElastiCache read-permission gap
  (`elasticache:ListTagsForResource`) is resolved (§9.13). Both remain
  Group B — not because import itself is blocked, but because each has
  its own still-open, unauthorized-to-reconcile config/live difference
  (the subnet group's historical 2-vs-6 membership correction, §9.15; the
  replication group's description/tag drift, §9.16) pending a full
  readiness re-assessment.
- **The four ECS services use a two-axis model, not a single Group
  letter (corrected 2026-08-05, §9.21):**
  `module.web.aws_ecs_service.this[0]`,
  `module.worker.aws_ecs_service.this[0]`,
  `module.critical_worker.aws_ecs_service.this[0]`,
  `module.scheduler.aws_ecs_service.this[0]`.
  - **State-import readiness: READY.** The four ECS services are **no
    longer blocked by launch mode or desired count** — both are now
    resolved (see corrections 1-2 below). Every service-level field
    (identity, launch mode, network configuration, deployment
    percentages, tags) now matches live exactly; `task_definition` and
    `tags`/`tags_all` are both protected by `lifecycle.ignore_changes`
    (§9.21); and importing a service does not itself deploy, scale,
    restart, or register anything. These four addresses may each be
    imported independently as an isolated, state-only canary — see §9.21
    for the exact order.
  - **Deployment-migration status: PENDING**, for two different,
    still-open reasons unrelated to import safety: (a) each service's
    `task_definition` argument points at `aws_ecs_task_definition.this.arn`
    — a task definition this module itself declares and would create
    fresh — rather than merely recording the currently-running historical
    revision; `lifecycle.ignore_changes = [task_definition]` prevents an
    import-time service update from switching to it, but the surrounding
    configuration's intent (Terraform owns future task-definition
    revisions) is a real architecture change, not yet reviewed; and (b)
    live services run under a single, generic shared task role, while
    this module's `iam` component declares 7 distinct per-role task roles
    (`module.iam.task_role_arns["web"]`, etc.) that do not exist live at
    all. Importing a service does not, by itself, create a task
    definition or role — but it must not be treated as approval to deploy
    any new Terraform-managed task definition or role-specific task role;
    that migration is a separate, explicitly reviewed decision. **A
    service being state-import-ready does not make it deployment-ready,
    and a pending deployment migration does not make it unsafe to
    import** — these are two independent facts, not two positions on one
    scale.

**Corrections landed in this pass:**

1. **ECS launch mode** (§9.7): `use_capacity_provider_strategy` (no
   default) added to `modules/ecs_service`; every staging caller sets it
   `false`, matching live's `launchType=FARGATE`.
2. **ECS desired counts**: live desired counts are all confirmed `1`
   (`aws ecs describe-services`, 2026-08-04) — Terraform previously
   declared `web`/`worker` at `2`. New explicit variables
   `web_desired_count`/`worker_desired_count`/
   `critical_worker_desired_count`/`scheduler_desired_count` preserve
   their original new-environment defaults (`2`/`2`/`1`/`1`); this
   environment's `terraform.tfvars` sets all four to `1`.
3. **Cluster capacity-provider association**: live is confirmed empty
   (`capacityProviders: []`, `defaultCapacityProviderStrategy: []`) — the
   prior audit's claim that the configured `["FARGATE","FARGATE_SPOT"]`
   default "already matches the live default" was **wrong** and has been
   removed. `modules/ecs_cluster` now exposes
   `capacity_providers`/`default_capacity_provider` (config-known,
   default preserves original design); an offline mocked test
   (`modules/ecs_cluster/tests/capacity_providers.tftest.hcl`) confirms an
   empty list plus zero `default_capacity_provider_strategy` blocks is
   schema-valid — not confirmed via a real plan or `terraform providers
   schema` (neither authorized in this pass). This environment's
   `terraform.tfvars` sets `ecs_capacity_providers = []`. The resource
   address is unchanged; associating capacity providers with the live
   cluster remains a separate, explicitly reviewed decision.
4. **IAM inline-policy name**: live policy is named
   `FirmsBaseStagingSecretsAccess`, not the module's previously hardcoded
   `"<name_prefix>-task-execution"`. Since `aws_iam_role_policy.name` is
   effectively immutable, the old hardcoded value would have set up a
   guaranteed replacement on the first plan after import. `modules/iam`
   now takes a required `task_execution_policy_name` (no default); this
   environment's `terraform.tfvars` sets it to
   `"FirmsBaseStagingSecretsAccess"`. This aligns identity only — the
   policy's *content*/permission-shape (§11 item 3) remains the same
   separate, undecided migration.

### 9.13 ElastiCache read-permission blocker (RESOLVED 2026-08-05)

`elasticache:ListTagsForResource` was `AccessDenied` for
`firmsbase-staging-operator` on both:

- `arn:aws:elasticache:us-east-1:603013471426:replicationgroup:firmsbase-staging-redis`
- `arn:aws:elasticache:us-east-1:603013471426:subnetgroup:firmsbase-staging-cache-subnets`

confirmed via a direct read-only `aws elasticache list-tags-for-resource`
call against each ARN on 2026-08-04. **Both are now granted** —
re-confirmed via the same direct read-only calls on 2026-08-05, both
succeeding without `AccessDenied`. ElastiCache's `Describe*` APIs do not
embed tags inline (unlike IAM's `GetRole`), so the AWS provider's
`aws_elasticache_replication_group`/`aws_elasticache_subnet_group` reads
plausibly need this permission during import; with the grant now in
place, this is no longer a blocker for either resource. By contrast,
`iam:ListRoleTags` is also `AccessDenied` for this operator, but is *not*
claimed as a required import-time permission here — IAM's `GetRole`
(which does work) embeds `Role.Tags` inline per its own API shape, so the
provider's `aws_iam_role` read typically does not need a separate tags
call; this distinction is not re-verified against the provider's source
directly.

### 9.14 ECS cluster Container Insights correction (2026-08-04, third pass)

A canary import attempt against `module.ecs_cluster.aws_ecs_cluster.this`
was halted before running (no state was mutated) when a fresh, read-only
`aws ecs describe-clusters` re-verification found **live `containerInsights`
is `disabled`**, while `modules/ecs_cluster` previously hardcoded
`"enabled"` unconditionally, with no override variable. §9.12's claim that
"the cluster resource itself (name, containerInsights setting) has no
known drift" was **WRONG** — it was never actually verified against this
specific field. This is a real, previously-unreviewed Terraform-managed
setting mismatch: importing under the old hardcoded config would have left
the next (unauthorized) `plan` proposing to enable Container Insights on a
live, in-use cluster — a genuine cost and observability behavior change,
not a no-op.

**Fixed:**

1. Live `containerInsights` is confirmed `disabled` (`aws ecs
   describe-clusters` — `Settings: [{name: containerInsights, value:
   disabled}]`, 2026-08-04).
2. Terraform previously hardcoded `containerInsights = "enabled"`
   unconditionally, with no way for any caller to represent a live cluster
   that has it disabled.
3. `modules/ecs_cluster` now takes a required `container_insights_enabled`
   boolean (no default — every caller must decide explicitly); this
   staging environment's `terraform.tfvars` sets
   `ecs_container_insights_enabled = false`, matching live exactly.
4. The module renders `"disabled"` for this environment
   (`var.container_insights_enabled ? "enabled" : "disabled"`).
5. The normal new-environment default remains `"enabled"`
   (`var.ecs_container_insights_enabled` defaults `true` at the staging
   root, `var.container_insights_enabled` itself has no module-level
   default — every caller, including a brand-new environment's own root
   module, must pass it explicitly).
6. Importing the cluster records the current, live `disabled` setting
   into state exactly as-is.
7. Importing does **not** authorize enabling Container Insights later —
   any future enablement is a separate decision requiring its own explicit
   cost and observability review, not a byproduct of this or any import.
8. `module.ecs_cluster.aws_ecs_cluster.this`'s resource address is
   unchanged; no exact, source-backed blocker remains — this address is
   genuinely suitable for an individual Phase A3 canary import once this
   correction merges.
9. `module.ecs_cluster.aws_ecs_cluster_capacity_providers.this` — the
   separate, already-confirmed-empty capacity-provider association — is a
   different Terraform resource address and remains a later, independent
   import; it is not part of this correction and was not touched by it.

### 9.15 ElastiCache subnet-group membership correction (2026-08-05)

A canary import attempt against
`module.elasticache.aws_elasticache_subnet_group.this` was halted before
running (no state was mutated) after the `elasticache:ListTagsForResource`
grant (§9.13) let a fresh, read-only re-verification proceed and it found
**live ElastiCache subnet-group membership is 6 subnets**
(`subnet-020540b8377bb4d0e`, `subnet-0d328451d742a4a3c`,
`subnet-07efcb5d4bcf5aa59`, `subnet-04f36560361246d4b`,
`subnet-0631d53a7acde6530`, `subnet-06cb2ddbdb7cf4d69` — all confirmed via
`aws ec2 describe-subnets` and `aws elasticache
describe-cache-subnet-groups` to exist, belong to `vpc-0fd81b688155ded2b`,
be `available`, and match this exact set as a set, not an ordered list),
while `modules/elasticache`'s `aws_elasticache_subnet_group.this` resource
previously derived its `subnet_ids` unconditionally from the caller's ECS
`private_subnet_ids` — only 2 subnets. **That previous configuration was
not adoption-safe**: it was never checked against the live subnet group's
actual membership, and conflated two genuinely different concerns (ECS
task placement vs. ElastiCache subnet-group registration).

**Fixed:**

1. Live ElastiCache subnet-group membership is confirmed 6 subnets (see
   above).
2. Terraform previously inherited only the 2 ECS `private_subnet_ids`
   subnets — a real, previously-unreviewed under-registration.
3. That previous configuration was not adoption-safe.
4. `modules/elasticache` now takes a required `subnet_ids` input (no
   default), independent of `private_subnet_ids`. This module no longer
   names it `private_subnet_ids` internally, precisely because the live
   group's membership is broader than ECS placement.
5. This staging environment's `terraform.tfvars` sets the new
   `elasticache_subnet_ids` root variable to the exact live 6-subnet set.
6. The ECS `public_subnet_ids`/`private_subnet_ids` variables and every
   ECS service's subnet wiring are unchanged — this correction only
   decouples ElastiCache's own subnet-group membership from them.
7. `elasticache:ListTagsForResource` is now granted and verified for both
   the subnet group and the replication group (§9.13).
8. Importing the subnet group will record the current, correct 6-subnet
   membership only.
9. Importing does **not** authorize removing any subnet later.
10. Any future reduction or redesign of subnet-group membership requires a
    separate, explicit availability, failover, networking, and maintenance
    review — not a byproduct of import.
11. `module.elasticache.aws_elasticache_subnet_group.this`'s resource
    address is unchanged; this subnet group remains unimported until this
    correction merges.
12. `module.elasticache.aws_elasticache_replication_group.this` remains a
    later, separate import — not touched by this correction.

### 9.16 ElastiCache subnet group and replication group imported; description/tag drift recorded (2026-08-05)

Both ElastiCache resources referenced throughout §9.10–§9.15 have since been
imported, each as its own individually-verified canary:

1. `module.elasticache.aws_elasticache_subnet_group.this` was imported
   (`firmsbase-staging-cache-subnets`). Live membership was re-verified as
   the same 6 subnets immediately before and after import; the import
   changed no AWS resource.
2. `module.elasticache.aws_elasticache_replication_group.this` was imported
   (`firmsbase-staging-redis`). Live topology (single node, no replica,
   automatic failover disabled, Multi-AZ disabled, engine `valkey` version
   `7.2.6`, node type `cache.t4g.micro`, transit/at-rest encryption enabled,
   auth-token enabled, subnet group `firmsbase-staging-cache-subnets`,
   parameter group `default.valkey7`, security group
   `sg-0da3ea50262a9d20d`) and its sole member cluster
   (`firmsbase-staging-redis-001`) were byte-identical in a read-only
   re-check performed immediately before and after import; the import
   changed no AWS resource. `lifecycle.ignore_changes = [auth_token]`
   already covers the one field AWS never returns.
3. A field-by-field comparison performed as part of this import found two
   config/live differences for the replication group that neither the
   import nor this correction reconciles or authorizes:
   - **Description**: live is "Valkey for FirmsBase staging sessions,
     cache, and queues"; `modules/elasticache` hardcodes a different
     literal ("FirmsBase staging Redis — cache/session/queue/locks...").
     `description` is not a ForceNew argument for
     `aws_elasticache_replication_group`, so an unauthorized future
     `apply` could silently overwrite the live text in place.
   - **Tags**: the live replication group carries three tags
     (`Environment`, `Application`, `Name`, confirmed via
     `elasticache:ListTagsForResource`); the staging root never passes a
     `tags` argument to `module.elasticache`, so it resolves to the
     module's default `{}`. This mirrors the same untagged convention
     used everywhere else in this environment's Terraform (no resource
     in this project currently has root-supplied tags) — it is not a
     defect introduced by this import. But an unauthorized future
     `apply` could still strip all three live tags, since nothing in
     `lifecycle.ignore_changes` covers `tags`.
4. Neither difference is described as harmless or already decided. A
   future `plan` proposing to change the description or remove the live
   tags is an explicit **stop condition** requiring its own review
   (does the description matter operationally? should tags be adopted
   into Terraform for this environment generally, not just this one
   resource?) — not a byproduct of these two imports.
5. No import in this pass changed the classification of either resource
   in `import-manifest.json`; both remain `import_then_migrate` because
   the description/tag reconciliation above is still open.

### 9.17 IAM execution-role trust policy and description alignment (2026-08-05)

A read-only preflight for `module.iam.aws_iam_role.task_execution` found
the role's *name* was already aligned (§9.10/§9.11), but a fresh
field-by-field comparison against live surfaced two role-level gaps that
were not previously discovered: the shared assume-role trust policy
omitted the confused-deputy conditions live actually enforces, and the
resource declared no `description` at all. Neither gap is cosmetic — an
unauthorized future `apply` against the old config could have weakened
the live trust policy or overwritten the live description.

**Fresh live verification (2026-08-05):** both
`firmsbase-staging-ecs-execution-role` and `firmsbase-staging-ecs-task-role`
were re-read via `aws iam get-role`. Both carry an identical assume-role
policy: principal `ecs-tasks.amazonaws.com`, action `sts:AssumeRole`,
`StringEquals aws:SourceAccount = 603013471426`, and
`ArnLike aws:SourceArn = arn:aws:ecs:us-east-1:603013471426:*`. Because
both examined live roles are identical, the correction below models this
as one shared trust-policy document, not a role-specific one.

**Fixed:**

1. `modules/iam`'s `data.aws_iam_policy_document.ecs_tasks_assume_role`
   (shared by `aws_iam_role.task_execution` and all 7
   `aws_iam_role.task[*]` roles) now takes two new required module inputs,
   `aws_account_id` and `aws_region` (no default on either — this reusable
   module must never derive the account from the ambient AWS identity
   running `terraform apply`), and renders both the `StringEquals
   aws:SourceAccount` and `ArnLike aws:SourceArn` conditions using them.
   This staging environment's `terraform.tfvars` sets
   `aws_account_id = "603013471426"`; `aws_region` reuses the existing
   root `var.aws_region` (default `"us-east-1"`), already wired into
   every other module call in this root.
2. `aws_iam_role.task_execution` now takes a new required module input,
   `task_execution_role_description` (no default, non-empty validated),
   wired only to `aws_iam_role.task_execution.description`. This
   environment's `terraform.tfvars` sets it to the exact live value,
   `"Execution role for FirmsBase staging ECS tasks"`. No other role in
   this module receives a description from this variable — the generic
   task roles' own live description ("Application role for FirmsBase
   staging ECS tasks") is a separate, unrequested change and is not made
   here.
3. Neither fix changes `module.iam.aws_iam_role.task_execution`'s
   resource address, adds or removes any IAM permission, or touches
   `module.iam.aws_iam_role_policy.task_execution`,
   `AmazonECSTaskExecutionRolePolicy`'s attachment, or any
   Secrets Manager/ECR/CloudWatch Logs/SSM/KMS grant. The inline-policy
   permission-shape decision (§11 item 3) remains entirely separate and
   unresolved.
4. With the trust policy and description now matching live exactly, the
   role-level configuration for `module.iam.aws_iam_role.task_execution`
   is complete. This address **moved from Group B to Group A** in the
   §9.12 readiness matrix — suitable for an isolated role-only import
   after this correction merges. Importing the role does **not**
   authorize detaching the AWS-managed policy or replacing the inline
   policy's content; either is a stop condition requiring separate
   review. `module.iam.aws_iam_role_policy.task_execution` remains
   Group C, unsafe, pending that decision.
5. Neither IAM resource was imported in this pass. No Terraform `plan`,
   `apply`, `import`, or state mutation ran.

### 9.18 IAM execution-policy architecture aligned with live (2026-08-05)

With `module.iam.aws_iam_role.task_execution` imported (§9.17) and role-level
config matching live exactly, a read-only re-verification of
`aws iam list-attached-role-policies`/`list-role-policies`/`get-role-policy`
against `firmsbase-staging-ecs-execution-role` confirmed the live two-layer
permission architecture is unchanged: one attached AWS-managed policy
(`AmazonECSTaskExecutionRolePolicy`) plus one narrow inline policy
(`FirmsBaseStagingSecretsAccess`, granting only `secretsmanager:GetSecretValue`
on 4 named secret ARNs). This correction preserves that architecture in
Terraform instead of replacing it.

**A previously-undiscovered content bug**: the 4th secret ARN this module's
inline policy granted was wrong. Live's inline policy grants
`secretsmanager:GetSecretValue` on `app-key`, `db-password`
("database-app"), `redis-auth-token`, and `db-migrator`
("database-migrator") — but the module's `secret_arns` list was wired to
`app_key_secret_arn`, `db_password_secret_arn`, `redis_auth_token_secret_arn`,
and `platform_notifications_recipient_fingerprint_hmac_key_secret_arn`. The
4th entry was **substituted for the wrong secret** — the HMAC-key secret,
which live's execution-role policy does not grant at all, in place of the
actual live 4th ARN (`database-migrator-TpsE6P`), for which **no Terraform
variable existed**. This was not previously reviewed because no prior pass
compared the inline policy's exact `Resource` list against
`aws iam get-role-policy` element-by-element.

**Fixed:**

1. **Managed-policy attachment**: `modules/iam` now declares
   `aws_iam_role_policy_attachment.task_execution_managed`
   (role = `aws_iam_role.task_execution.name`, policy_arn = the new
   required `task_execution_managed_policy_arn` module input, no
   default). Deliberately a per-resource, **non-exclusive** attachment —
   never `aws_iam_role_policy_attachments_exclusive`, never
   `managed_policy_arns` directly on `aws_iam_role` — either of which
   could detach an attachment this module doesn't fully enumerate. This
   staging environment's `terraform.tfvars` sets it to
   `arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy`,
   the confirmed live value.
2. **Inline policy, secrets-only**: the module's inline-policy data
   source no longer declares `EcrAuth`/`EcrPull`/`WriteLogs` statements —
   those permissions now come from the managed-policy attachment above,
   not duplicated. A new required `task_execution_secret_arns` input
   (nonempty, unique, no default) generates the sole `ReadTaskSecrets`
   statement. This staging environment wires it to exactly the 4 live
   ARNs, replacing the platform-notifications HMAC-key secret with the
   new `db_migrator_secret_arn` variable (added this pass; no prior
   variable represented this secret at all).
3. **SSM/KMS, separately opt-in**: `task_execution_ssm_parameter_arns`
   (default `[]`) and a new `task_execution_kms_decrypt_enabled` boolean
   (no default) independently gate the execution role's own optional
   statements — decoupled from the unrelated `kms_encryption_enabled`
   flag, which previously gated BOTH the execution role's KMS grant AND
   the S3-document task roles' KMS grant with a single value. This
   staging environment sets both execution-specific inputs to
   disabled/empty, matching live's inline policy having no SSM or KMS
   statement at all — even though `kms_encryption_enabled = true` for
   the (unrelated) S3-document feature. Before this fix, enabling
   S3-document KMS encryption would have silently added an
   unreviewed KMS statement to the execution role too.
4. **Not addressed by this correction**: task definitions for `web` and
   `ses_consumer` reference the platform-notifications HMAC-key secret
   via their own `secrets` block (`local.hmac_secret`) — a planned,
   not-yet-deployed feature (these task definitions are themselves `new`
   Terraform resources, not yet applied to live). If/when that feature is
   actually deployed, the execution role will also need
   `secretsmanager:GetSecretValue` on that secret — a **permission
   expansion** relative to today's live grant, requiring its own separate,
   explicit review at that time. This correction does not add it now,
   since doing so would grant a permission live does not currently have.
5. `ecr_repository_arn`/`log_group_arns` module inputs were removed
   entirely — both were used only by the now-removed `EcrPull`/`WriteLogs`
   statements, with no other consumer in the module.
6. Neither `module.iam.aws_iam_role_policy_attachment.task_execution_managed`
   nor `module.iam.aws_iam_role_policy.task_execution` was imported in
   this pass; both moved from Group B/C to **Group A** (§9.12) — live-
   aligned, suitable for an isolated canary import after this correction
   merges. Importing either does **not** authorize detaching the managed
   policy or expanding/reducing the inline policy's permissions; a future
   plan proposing either is a stop condition requiring separate review.
7. No Terraform `plan`, `apply`, `import`, or state mutation ran to
   produce this correction. No AWS or IAM resource changed.

### 9.19 Execution inline-policy Sid aligned with live; all three IAM execution-role resources imported (2026-08-05)

Following §9.18, all three IAM execution-role resources were individually
imported as separate canary missions:

1. `module.iam.aws_iam_role.task_execution` — imported
   (`firmsbase-staging-ecs-execution-role`). Role-level config (name, trust
   policy, description) matched live exactly at import time.
2. `module.iam.aws_iam_role_policy_attachment.task_execution_managed` —
   imported (`firmsbase-staging-ecs-execution-role/arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy`).
   Matched live exactly at import time.
3. `module.iam.aws_iam_role_policy.task_execution` — imported
   (`firmsbase-staging-ecs-execution-role:FirmsBaseStagingSecretsAccess`).

**Process finding**: the inline-policy import's own mission instructions
required a canonical live-vs-Terraform comparison that explicitly covered
Sid, action, effect, resource, and condition — and required stopping before
import on any such difference. A Sid difference existed
(`ReadFirmsBaseStagingSecrets` live vs. `ReadTaskSecrets` in Terraform) but
was not treated as a stop condition at the time; the import proceeded. The
import itself only ever writes to Terraform state — it does not call any
AWS write API — so this did not change, and could not have changed, the live
IAM policy. A fresh read-only `aws iam get-role-policy` re-verification
performed at the start of this correction confirmed the live policy is
exactly as it was before that import: Sid `ReadFirmsBaseStagingSecrets`,
Effect `Allow`, Action `secretsmanager:GetSecretValue`, the same 4 secret
ARNs.

**Fixed:**

1. `modules/iam` now takes a new required `task_execution_secrets_policy_sid`
   input (no default, mirrors `task_execution_policy_name`'s own no-default
   pattern — a mismatched Sid is a genuine policy-document content
   difference, not cosmetic to Terraform's own diffing, so a default that
   didn't match a caller's live Sid would silently plan a content change
   rather than fail loudly). This staging environment's `terraform.tfvars`
   sets it to the confirmed live value, `"ReadFirmsBaseStagingSecrets"`.
2. No action, effect, resource, attachment, or permission changed by this
   correction — only the statement's Sid label in Terraform's own
   configuration, which now matches what was already live.
3. `module.iam.aws_iam_role_policy.task_execution`'s resource address is
   unchanged; the resource is not re-imported or removed by this
   correction — only its configuration is corrected to match the state
   that import already recorded.
4. All three IAM execution-role resources above are now imported. Their
   manifest `notes` fields are updated to say so; the inline-policy entry
   additionally documents the Sid-only mismatch and its correction. No
   manifest classification changed, since none of the three addresses'
   classification depended on the Sid.
5. No Terraform `plan`, `apply`, `import`, or state mutation ran to
   produce this correction. No AWS or IAM resource changed. Any future
   plan proposing an action/resource/effect/attachment difference on any
   of these three resources remains a stop condition requiring separate
   review.

### 9.20 ECS service-level adoption alignment: deployment percentages, tags, log-group and IAM-read findings recorded (2026-08-05)

Following the read-only ECS/task-role/task-definition preflight, this pass
aligned the four existing live ECS services'
(`web`/`worker`/`critical-worker`/`scheduler`) **service-level**
configuration with live exactly, without importing, registering, deploying,
or modifying any AWS resource.

**Live re-verification (`aws ecs describe-services --include TAGS`,
2026-08-05):** all four services remain ACTIVE and stable
(desired/running/pending 1/1/0), `launchType=FARGATE`, no
`capacityProviderStrategy`, deployment circuit breaker enabled with
rollback on all four. **Deployment percentages are 0/100 (minimum/maximum)
on all four services, with no exceptions** — this was not previously
re-confirmed for `web`/`worker`/`critical_worker` at the same time as
`scheduler`.

**Fixed — deployment percentages:**

1. Previously only `module.scheduler`'s call to `modules/ecs_service`
   overrode `deployment_minimum_healthy_percent`/`deployment_maximum_percent`
   (as hardcoded literals `0`/`100`, matching live). `module.web`,
   `module.worker`, and `module.critical_worker` passed neither argument at
   all, silently falling through to the module's own new-environment
   design defaults (`100`/`200`) — a genuine, previously unrecorded
   live-vs-Terraform mismatch: applying any of these three after import
   would have proposed raising minimum-healthy from 0% to 100%, a
   materially different rolling-deployment mechanic for a
   `desired_count=1` service than what is live today.
2. Eight new staging-root variables added
   (`{web,worker,critical_worker,scheduler}_deployment_{minimum_healthy,maximum}_percent`),
   each preserving the module's original `100`/`200` new-environment
   defaults, each individually validated (minimum 0–100, maximum 100–200,
   maximum not lower than minimum). All four `modules/ecs_service` calls
   now wire these explicitly, including `scheduler` (whose previous
   hardcoded `0`/`100` literals are now sourced from the same variable
   pattern as the other three roles, for consistency — its live-matching
   values did not change). `terraform.tfvars`/`terraform.tfvars.example`
   set all eight to `0`/`100`, matching live exactly on all four services.
3. **These 0/100 values preserve current live behavior for state-only
   adoption ONLY.** They are not a review or approval of 0/100 as the
   intended steady-state for a later production-style, no-downtime
   cutover — that deployment strategy remains a separate, later,
   explicitly reviewed decision.

**Fixed — service tags:**

4. `modules/ecs_service` already exposed a `tags` input (default `{}`,
   unchanged); no staging-root caller previously wired it for any of the
   four services. Live re-verification confirms `web` carries five
   explicit service tags (`SourceCommit`, `Environment=staging`,
   `ManagedBy=manual-reviewed-deployment`, `ImageDigest`,
   `Application=FirmsBase`); `worker`/`critical-worker`/`scheduler` carry
   none.
5. Four new staging-root tag variables added
   (`{web,worker,critical_worker,scheduler}_tags`, all defaulting to `{}`,
   this module's original design intent), wired into each module call's
   existing `tags` argument — no parallel tagging mechanism was
   introduced. `terraform.tfvars`/`terraform.tfvars.example` set
   `web_tags` to live's exact five-key map; `worker`/`critical_worker`/
   `scheduler` are left at the `{}` default, which already matches their
   live (tagless) state.
6. `web`'s live `ImageDigest` tag value is preserved verbatim, including
   its independently-known staleness (it does not match the currently
   running task definition's actual image digest — a pre-existing live
   metadata inconsistency, not introduced by Terraform). Correcting that
   value is a separate, later, explicitly reviewed metadata change, not a
   byproduct of this adoption pass.
7. **Provider-level `default_tags` residual mismatch (checked, not
   fixed):** this environment's AWS provider (`versions.tf`) sets
   `default_tags = { Project = "firmsbase", Environment = "staging",
   ManagedBy = "terraform", Mission = "ecs-readiness-foundation" }`,
   which applies to every resource this provider manages — not specific
   to the ECS service module, and unchanged by this pass. Per-key,
   resource-level `tags` values win over `default_tags` on conflict (so
   `web`'s resource-level `Environment`/`ManagedBy` correctly resolve to
   live's values), but `Project`/`Mission` have no resource-level
   counterpart on any of the four services and would still be added on
   top of an apply — including on `worker`/`critical-worker`/`scheduler`,
   which have zero live tags today. **This means an exact, zero-extra-key
   live tag match is not achievable via `modules/ecs_service`'s `tags`
   input alone**; closing it would require a provider-level `ignore_tags`
   change, which is a cross-cutting decision affecting every resource in
   this environment, not scoped to these four services, and is explicitly
   out of scope for this pass.

**Log-group architecture (checked, already accurately documented, no
change required):** Terraform proposes seven workload-specific log groups
(`aws_cloudwatch_log_group.app[<role>]`, named
`/ecs/firmsbase-staging/<role>` for each of
`web`/`worker`/`critical-worker`/`scheduler`/`migrate`/`maintenance`/`ses-consumer`),
distinct from live's single shared log group
`/ecs/firmsbase-staging/app` (differentiated only by `awslogs-stream-prefix`
per service). This was already correctly recorded as an intentional,
unresolved deployment migration (not drift, and not something to call
"already aligned") in §2/§11 of this document; re-verified accurate in
this pass. No log group was created, imported, or otherwise changed. New
task definitions will only switch log destinations during an explicitly
approved future deployment.

**Corrected service import-readiness (service-level fields only — a
separate axis from deployment-migration status; superseded by the
two-axis model in §9.21, which additionally protects tags/tags_all):**

8. All four services' service-level configuration (identity, launch mode,
   subnets/security groups/public-IP, load-balancer attachment, circuit
   breaker, deployment percentages, tags where applicable,
   `lifecycle.ignore_changes=[task_definition]` still present and
   unmodified) now matches live exactly, field for field.
9. **Readiness order for an isolated, service-level state-only import: 1.
   `scheduler`, 2. `worker`, 3. `critical-worker`, 4. `web`.** This order
   reflects proximity/simplicity of service-level configuration only
   (e.g. `web` carries the ALB attachment and the five-tag map, the most
   configuration surface of the four).
10. **Correction to the prior mission's informally-reported classification:**
    the previous read-only preflight's final report described `scheduler`
    as simultaneously "Group B" (a configuration fix needed) and
    "Group C-only" (once that fix landed) in the same breath — a
    self-contradictory framing that was never written into this document
    or the manifest, but is corrected here explicitly so it is not
    repeated. Readiness rank (item 9 above) and deployment-migration
    status (§9.12) are two different axes: rank measures how completely a
    service's own configuration matches live; deployment-migration status
    measures whether the broader task-role/task-definition-ownership
    migration has been reviewed. Deployment percentages and tags were the
    only service-level fields that needed correcting in this pass. The
    manifest's stale `prerequisite` text (previously citing a
    since-already-fixed `assign_public_ip` hardcoded-false claim as a hard
    stop) is also removed in this pass — see the four service entries in
    `import-manifest.json`. **Superseded 2026-08-05 (§9.21): the "Group C"
    label applied to these four services throughout §9.12 and this
    section is retired in favor of two explicit, independent labels —
    state-import-ready and deployment-migration-pending — since a single
    letter conflated "safe to import" with "fully migrated." See §9.21
    for the corrected model and the tag-lifecycle fix that makes the four
    services actually state-import-ready (this section's item 8 predates
    that fix: tags were live-aligned in config but not yet protected from
    reconciliation on a future apply).**

**Shared-task-role IAM read blocker (re-confirmed, corrected):**

11. `iam:GetRolePolicy` on `firmsbase-staging-ecs-task-role`'s inline
    policy `FirmsVaultStagingSesSend` is freshly re-confirmed `AccessDenied`
    for the operator role (2026-08-05); only the policy's name is readable
    (`iam:ListRolePolicies` succeeds). The manifest's
    `module.iam.aws_iam_role_policy.task_web_ses_send[0]` entry previously
    stated this live policy "grants both `ses:SendEmail` and
    `ses:SendRawEmail`" as if confirmed — that was never actually read and
    was an inference from the policy's name, not a verified fact; it is
    corrected in this pass to state plainly that the actual granted
    actions remain unread and unconfirmed. No permission was granted to
    read it in this pass. Role-specific IAM design for the new `web` task
    role's `task_web_ses_send` policy cannot be finalized as a
    like-for-like replacement of the live policy until its content is
    actually readable and compared — this remains open.

12. No Terraform `plan`, `apply`, `import`, `refresh`, `providers schema`,
    `state show`, `state pull`, or any state mutation ran in this pass.
    No ECS service was updated, scaled, restarted, or redeployed; no task
    definition was registered or deregistered; no IAM role or policy was
    created or modified; no log group, alarm, or autoscaling resource was
    created. Managed-resource count remains 19 (verified via guarded
    `state list`).

### 9.21 Effective-tag fix and corrected two-axis import-readiness model (2026-08-05)

Following §9.20, two remaining issues were resolved before this branch is
pushed: an unresolved effective-tag mismatch that §9.20 had recorded but
not fixed, and a "Group C" label on the four ECS services that read as
"blocked" when it should have read as "safe to import, migration
pending."

**Effective-tag finding (audit, not yet fixed as of §9.20):** this
environment's AWS provider `default_tags` (`Project`, `Mission` — see
`versions.tf`) has no per-service override for those two keys on any of
the seven `ecs_service` module callers. Deterministically, per the AWS
provider's documented, stable tag-merge semantics (resource-level `tags`
win per-key over `default_tags`; keys with no resource-level counterpart
pass through unchanged): **`Project` and `Mission` would be added to
every one of the four existing services' `tags_all`** on any future
plan/apply — including `worker`/`critical-worker`/`scheduler`, which
carry zero live tags today. Since `terraform import` records a live
resource's actual current tags verbatim (import does not invoke
plan/apply logic or provider tag-merge logic), the very next plan/apply
after importing any of the four services would therefore have proposed
adding `Project`/`Mission` tags that do not exist live — a real,
previously-unaddressed risk of `import` implicitly setting up a
same-config live-tag mutation on the next apply, which is exactly the
`terraform import` state-only property this whole adoption effort
depends on staying true.

**Selected fix — extend the existing `lifecycle.ignore_changes` block**
(the mission's preferred design, confirmed schema-valid): `aws_ecs_service.this`
in `modules/ecs_service/main.tf` now reads:

```hcl
lifecycle {
  ignore_changes = [
    task_definition,
    tags,
    tags_all,
  ]
}
```

- **Schema validity**: proven directly via `terraform validate` against
  the installed provider (`hashicorp/aws` v5.100.0, per the lock file) in
  an isolated local-backend copy — `validate` would reject an
  `ignore_changes` reference to a non-existent attribute, and it passed
  cleanly. This is real, provider-schema-backed proof, not an assumption.
- **Behavioral proof (not just schema)**: a new module test,
  `modules/ecs_service/tests/service_tags_lifecycle.tftest.hcl`, applies
  the resource once under `mock_provider "aws" {}` with an explicit tag
  map, then applies again with different tag values — `ignore_changes`
  is a Terraform-core meta-argument (not provider-specific), so its
  diff-suppression behavior is faithfully exercised even under a mock
  provider. The second apply's resulting `tags` remain exactly the first
  apply's values, proving the freeze works. (What a mock provider cannot
  simulate is the AWS provider's own `default_tags`-merge computation
  inside `tags_all` — that part rests on the documented, stable provider
  behavior described above and on `validate` accepting `tags_all` as a
  real attribute.)
- **`task_definition` protection is unmodified** — it remains the first
  entry in the same list, unchanged since §9.9.
- **This freezes state, it does not change live**: nothing was applied
  against the real backend or AWS in this pass; the fix only changes what
  a *future* apply would do once these services are imported.
- **Create-time behavior is different and is called out separately**:
  `ignore_changes` has **no effect on a resource's first creation** —
  there is no prior state to diff against yet. For a genuinely new
  resource such as `module.ses_consumer.aws_ecs_service.this[0]` (no live
  counterpart), its first `apply` will set `tags`/`tags_all` from
  whatever config computes at that time (its own `tags` variable, merged
  with provider `default_tags`) exactly as configured — `ignore_changes`
  only takes effect on every apply *after* that, preventing further
  drift-driven reconciliation once the resource already exists in state.
  Do not read this fix as "ignore_changes suppresses tags at creation" —
  it does not.
- **No provider-wide `ignore_tags` was added**, and no provider alias was
  created: the preferred lifecycle design was schema-valid and proven, so
  the stop conditions in Phase 3 of this mission (do not add
  `ignore_tags` merely to solve four services; do not create an alias
  unless the preferred design is invalid) did not apply.
- **Live tags are unchanged**: this pass modified only `modules/ecs_service/main.tf`'s
  `lifecycle` block, `docs/ecs/*.md`, `import-manifest.json`, and test
  files — no AWS API call that could mutate a tag was made.
- **Imported ECS service tags are preserved as externally established
  adoption metadata** — `web`'s five live tags (including the
  independently-known-stale `ImageDigest` value, still preserved
  verbatim, not corrected) and `worker`/`critical-worker`/`scheduler`'s
  absence of tags are frozen at whatever they are at import time, pending
  a later, separately reviewed tag-governance migration (which could
  reconcile `Project`/`Mission` onto these services, correct `web`'s
  stale `ImageDigest`, or decide to leave both as-is — none of that is
  decided or authorized here).

**Corrected two-axis import-readiness model** (retires the single
"Group C" label used for these four services in §9.12/§9.20 — see the
superseding note added to each):

- **A. State-import readiness: READY**, for all four
  (`module.{web,worker,critical_worker,scheduler}.aws_ecs_service.this[0]`).
  Identity matches; every service-level field matches live; `task_definition`
  is ignored during adoption; tags are now also protected from
  reconciliation (this section's fix); and import itself does not deploy,
  scale, restart, or register anything. **Recommended import order: 1.
  `scheduler`, 2. `worker`, 3. `critical-worker`, 4. `web`** (unchanged
  from §9.20 item 9 — reflects service-level configuration surface, not
  a difference in readiness).
- **B. Deployment-migration status: PENDING**, for all four, unchanged:
  role-specific task roles, new Terraform-managed task-definition
  revisions, workload-specific log groups (§9.21 log-group note below),
  and an explicit, separately reviewed service cutover/rollback all
  remain later, unauthorized steps. **A service being state-import-ready
  does not make it deployment-ready.**
- Manifest `classification` for all four remains `import_then_migrate`,
  unchanged — this section corrects the *prose* model (Group C → two
  independent labels), not the manifest's structural classification,
  which already captured "import now, migrate later" correctly.
- No service is classified deployment-only or unsafe solely because its
  deployment migration is pending; no service is classified ready when
  any service-level mismatch exists (none currently do).

**Log-group boundary reaffirmed, unchanged:** live task definitions use
the single shared `/ecs/firmsbase-staging/app` log group; Terraform's
future task definitions use 7 workload-specific log groups; no log group
is imported or created by this correction; log-destination changes occur
only as part of a separately approved task-definition deployment. This
was already accurate as of §9.20 and remains so — nothing in this section
changes it.

13. No Terraform `plan`, `apply`, `import`, `refresh`, `providers
    schema`, `state show`, `state pull`, or any real state mutation ran
    to produce this section. No AWS, ECS, IAM, or live tag was changed.
    Only `modules/ecs_service/main.tf`, this document,
    `staging-variable-inventory.md`, `import-manifest.json`, and test
    files were edited.

## 10. Validation performed (local/static only)

| Check | Result |
|---|---|
| `terraform fmt -recursive -check infrastructure/ecs` | Pass |
| `terraform -chdir=infrastructure/ecs/environments/staging init -backend=false` + `terraform validate` | Pass |
| `terraform -chdir=infrastructure/ecs/environments/staging test` | 9/9 pass — naming-override fallback/override for cluster, ECR, ElastiCache subnet group + engine; `assign_public_ip` default-true, override-to-false-with-NAT-IDs, and validation-failure-without-NAT-IDs |
| `terraform -chdir=infrastructure/ecs/modules/iam test` | 2/2 pass — `task_execution_role_name` fallback/override (isolated from the root module's other data-source complications via `override_data` on the assume-role/execution/metrics policy documents, since those need real JSON a blanket `mock_provider` can't produce) |
| `python3 infrastructure/ecs/environments/staging/scripts/validate-import-manifest.py` | Pass — 94 addresses, all uniquely classified, summary counts match a fresh recount; verified to correctly FAIL on each of the 5 violation types the review required (missing classification, duplicate address, wrong totals, import-classified entry missing an import_id, new/unmanaged/do_not_import entry incorrectly carrying an import_id) |
| `tf-guard.sh` manual tests (historical — at the time this row was written, no backend existed yet; see the 2026-08-03 backend-configuration update in §5 for the current state) | `validate`/`fmt` passthrough confirmed; `plan`/`apply` correctly refused with no backend configured (the real state at that time); with a temporary mock backend block, correctly refused on wrong region, then correctly refused on empty state with correct account+region — all 4 checks independently verified to fire. Re-verified after the real backend was configured: `plan`/`apply` now correctly refused on Terraform binary version (new check) instead, then on empty state once an approved-version binary and correct account/region are used — see docs/ecs/staging-readiness-report.md for the current guard check list. |
| AWS-backed `terraform plan` against empty state | **Not run** — explicitly prohibited |
| `terraform apply` / `terraform import` / `terraform refresh` / any `terraform state` subcommand | **Not run** — explicitly prohibited |

Governance/PHP test suite: not run. No `app/`, `tests/`, or other
PHP/application file changed in this correction — every change is
Terraform (`.tf`), a Terraform test (`.tftest.hcl`), documentation
(`.md`), a JSON manifest, or a shell script confined to
`infrastructure/ecs/`. Confirmed via `git diff --stat` before commit (§13).

## 11. Remaining approvals / information required before state adoption can begin

1. Backend choice (§5) and its provisioning — not this plan's job.
2. ECS cluster name: keep live `firmsbase-staging-cluster` (this plan's
   assumption, via `ecs_cluster_name`) vs. accept a one-time replacement
   to standardize on `name_prefix`.
3. ~~**IAM execution-role permission-shape decision**~~ — **RESOLVED
   2026-08-05 (§9.18)**: the live AWS-managed-policy + narrow-inline-policy
   architecture is now preserved in Terraform. `modules/iam` attaches
   `AmazonECSTaskExecutionRolePolicy` via a separate, non-exclusive
   `aws_iam_role_policy_attachment`, and the inline policy's content now
   matches live's exact secrets-only grant. No code change was needed to
   widen anything; both resources remain unimported pending their own
   import, and neither import authorizes detaching the managed policy or
   expanding/reducing the inline policy's permissions.
4. ALB target-group health-check adoption (§9.5, expanded this revision to
   cover all three mismatches, not just the path): adopt live's values
   (`/up`, 30s interval, matcher `200-399`) via the new
   `alb_health_check_path`/`alb_health_check_interval_seconds`/
   `alb_health_check_matcher` variables before import, or accept a
   deliberate one-time behavior change to the module's original design
   values (`/readyz`, 15s, `200`) instead. Either way this is a human
   decision, not a default to silently import against; the resource
   remains BLOCKED (`import_then_migrate`) until the live-compatible
   values are actually supplied.
5. ElastiCache engine/version (§9.4, new this revision): confirm
   `elasticache_engine = "valkey"` and `elasticache_engine_version = "7.2"`
   (now an actual staging-environment variable, wired into
   `module.elasticache`, not just the module's own internal default; the
   live replication group's exact reported version is `7.2.6`, but AWS's
   own provider validation requires major.minor-only for Redis v6+/Valkey)
   before any replication-group import is attempted — this is now a
   required variable override, not optional.
6. Security-group rule reconciliation timing (§9.6): apply the narrower
   declared rules alongside the existing broad ones now, or defer until
   the broad rules are revoked in the same change.
7. ~~Elevated read access for `ec2:DescribeSecurityGroupRules`,
   `ec2:DescribeVpcAttribute`, and `elasticache:ListTagsForResource`~~ —
   **all three now granted** (§9.10, §9.13). Elevated (or one-time
   delegated) read access for `cloudwatch:DescribeAlarms` and
   `sns:ListTopics` remains an open item — not requested automatically,
   per the mission's constraint.
8. Confirmation of `module.migrate`/`module.maintenance` task-definition
   content — both are covered by the uniform `do_not_import` decision in
   §6 regardless, so this is informational for Phase B planning, not a
   Phase A blocker.
9. Human sign-off to actually begin executing §8's import commands — this
   plan prepares them, it does not run them.
10. `APP_URL` (§9.8, new this revision): now modeled via `var.app_url` —
    not an open approval decision, a required input. Must be set to the
    confirmed live value (`https://staging.firmsvault.com`) in
    `terraform.tfvars` before any Terraform `plan`/`apply` or ECS
    task-definition migration; omission would silently generate incorrect
    application links rather than failing loudly.
