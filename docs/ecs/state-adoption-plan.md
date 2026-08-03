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
No backend has ever been configured; see §5.

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
- No Terraform state, local or remote, exists anywhere in this repository.
- `infrastructure/ecs/environments/staging/versions.tf` has no `backend`
  block — a deliberate, not-yet-made decision (see §5).
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
| `import_unchanged` | 11 | Live resource exists and field-matches; ready to import once blocked prerequisites (mostly permission gaps, not config gaps) clear |
| `import_then_migrate` | 11 | Live resource exists, but Terraform needs a code fix (naming) and/or a design decision (permission shape, engine) before import is clean |
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

## 5. Backend — a candidate, not an approved decision

**No existing backend was found, approved, or referenced anywhere.**
`versions.tf` has no `backend` block, and no prior mission or document
records a backend decision having been made for this environment.

This plan does **not** present any option as already chosen. Two
candidates exist, both requiring an owner decision before either is
implemented — this plan implements neither:

1. **S3 + DynamoDB** (native Terraform, most common for this kind of
   setup): a new versioned + encrypted S3 bucket for state, a DynamoDB
   table for locking (`LockID` hash key), and an IAM policy scoped to
   exactly `s3:GetObject`/`PutObject` on the state key and
   `dynamodb:GetItem`/`PutItem`/`DeleteItem` on the lock table.
2. **Terraform Cloud / HCP Terraform workspace**: no AWS resources to
   create, but requires an organization/workspace decision and a way to
   supply AWS credentials to the run (dynamic provider credentials or a
   service-account key) — itself a decision this plan does not make.
3. **Any other repository-supported alternative** the owner prefers is
   equally in scope — this list is not exhaustive, only the two candidates
   evaluated so far.

**Required properties, regardless of which candidate is chosen** (a
checklist for whoever makes this decision, not an instruction to this
plan):

- Encrypted remote state (at rest, and in transit for any state-access
  API).
- Versioning (so a bad apply's prior state is recoverable).
- Locking (concurrent `apply`/`plan` protection).
- Least-privilege access — scoped to exactly the state object(s)/lock
  table this environment needs, not a shared bucket with broad access.
- Backup/recovery process independent of Terraform itself (e.g. S3
  versioning + a documented restore procedure, or the backend's
  equivalent).
- State is never committed to Git, under any circumstance.

**This plan does not create the bucket, table, workspace, or IAM policy**
for either candidate. `tf-guard.sh` (§6) refuses `plan`/`apply` outright
until a `backend` block actually exists in `versions.tf` — this is a
structural precondition, not a suggestion.

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

### Phase A2 — resources proven configuration-equivalent (`import_unchanged`, 11 addresses)

Security groups (`alb`, `ecs_tasks`, `redis`) and their field-matching
rules, the ALB + target group + both listeners. Import commands are fully
prepared in the manifest; 4 of the 11 are marked `import_id: "BLOCKED"`
pending `ec2:DescribeSecurityGroupRules` access (`AccessDenied` for this
operator — the SG/rule *content* is already confirmed matching, only the
AWS-internal rule ID needed for the literal import command is unresolved).

```bash
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.security_groups.aws_security_group.alb' 'sg-02a26ff122a9a1d29'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.security_groups.aws_security_group.ecs_tasks' 'sg-0db14e50ea5c5466c'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.elasticache.aws_security_group.redis' 'sg-0da3ea50262a9d20d'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.alb.aws_lb.this' 'arn:aws:elasticloadbalancing:us-east-1:603013471426:loadbalancer/app/firmsbase-staging-alb/79a16ccaf391d71b'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.alb.aws_lb_target_group.web' 'arn:aws:elasticloadbalancing:us-east-1:603013471426:targetgroup/firmsbase-staging-tg/1830c01b9aaac37d'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.alb.aws_lb_listener.https' 'arn:aws:elasticloadbalancing:us-east-1:603013471426:listener/app/firmsbase-staging-alb/79a16ccaf391d71b/f8dc4575154478ca'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.alb.aws_lb_listener.http_redirect' 'arn:aws:elasticloadbalancing:us-east-1:603013471426:listener/app/firmsbase-staging-alb/79a16ccaf391d71b/371edb36d1b49e2c'
# --- plan checkpoint: MUST show zero destroy/replace, and MUST show zero
# unexpected create actions (every resource this checkpoint's plan proposes
# to create must be one this document already classified `new` — anything
# else is a sign an earlier step was skipped or misordered; stop and
# re-diagnose rather than apply). The 4 BLOCKED SG-rule imports (see
# import-manifest.json) run here too, once their rule IDs are resolved with
# elevated read access — not guessed. ---
```

### Phase A3 — resources requiring a temporary live-state-compatible configuration first (`import_then_migrate`, 11 addresses)

**Every command below is BLOCKED until its named code fix/decision lands**
— none of these are guesses; each is marked in the manifest with an
explicit `prerequisite` field.

```bash
# Requires: main.tf's ecr_repository_name resolves to "firmsbase-staging" (now wired via var.ecr_repository_name — see §6/§9.5 of variables.tf)
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.ecr.aws_ecr_repository.app' 'firmsbase-staging'

# Requires: var.ecs_cluster_name = "firmsbase-staging-cluster"
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.ecs_cluster.aws_ecs_cluster.this' 'firmsbase-staging-cluster'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.ecs_cluster.aws_ecs_cluster_capacity_providers.this' 'firmsbase-staging-cluster'

# Requires: var.elasticache_subnet_group_name = "firmsbase-staging-cache-subnets"
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.elasticache.aws_elasticache_subnet_group.this' 'firmsbase-staging-cache-subnets'

# Requires: var.elasticache_engine = "valkey", var.elasticache_parameter_group_name = "default.valkey7",
# engine_version aligned to the live 7.2 line, and the module's ignore_changes=[auth_token] (already added)
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.elasticache.aws_elasticache_replication_group.this' 'firmsbase-staging-redis'

# Requires: var.iam_task_execution_role_name = "firmsbase-staging-ecs-execution-role"
# PLUS a separate, explicit decision on permission-shape reconciliation (managed-policy vs custom-inline) — see §11 item 3. Do not import until that decision is made; a name-only fix imports a role whose Terraform-declared permissions don't match what's actually attached live.
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.iam.aws_iam_role.task_execution' 'firmsbase-staging-ecs-execution-role'
terraform -chdir=infrastructure/ecs/environments/staging import \
  'module.iam.aws_iam_role_policy.task_execution' 'firmsbase-staging-ecs-execution-role:FirmsBaseStagingSecretsAccess'

# Requires: assign_public_ip=true is in effect (default, §7) for all four — DO NOT apply with private_egress_ready=true until real NAT egress exists
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
  (`"redis"` default / must be `"valkey"` for this environment) and
  `var.elasticache_parameter_group_name`.
- **Subnet group name mismatch** — fixed via `var.elasticache_subnet_group_name`.
- **`auth_token`** (pre-existing, unrelated to the above): write-only,
  never returned by any read API — `lifecycle { ignore_changes =
  [auth_token] }` added to `aws_elasticache_replication_group.this` so a
  post-import plan doesn't show a permanent diff or attempt a disruptive
  in-place rotation.

### 9.5 ALB target-group health-check path

Unchanged from the original finding: live `/up` (liveness) vs. Terraform's
default `/readyz` (readiness) — a real semantic difference, not a naming
one. Non-destructive to change (in-place update), but flagged for an
explicit human decision (§11), not silently adopted either direction.

### 9.6 Security-group rule granularity

Unchanged: additive-only, no live rule is ever implicitly revoked by this
module's per-rule (`aws_security_group_rule`) pattern. The live
environment remains more permissive than Terraform's declared rules after
Phase A unless a human explicitly revokes the extra broad rules in a
follow-up — out of scope here.

### 9.7 `launch_type` vs. `capacity_provider_strategy`

Unchanged, confirmed non-destructive: an in-place field swap per the AWS
provider's documented behavior for this attribute pair.

## 10. Validation performed (local/static only)

| Check | Result |
|---|---|
| `terraform fmt -recursive -check infrastructure/ecs` | Pass |
| `terraform -chdir=infrastructure/ecs/environments/staging init -backend=false` + `terraform validate` | Pass |
| `terraform -chdir=infrastructure/ecs/environments/staging test` | 9/9 pass — naming-override fallback/override for cluster, ECR, ElastiCache subnet group + engine; `assign_public_ip` default-true, override-to-false-with-NAT-IDs, and validation-failure-without-NAT-IDs |
| `terraform -chdir=infrastructure/ecs/modules/iam test` | 2/2 pass — `task_execution_role_name` fallback/override (isolated from the root module's other data-source complications via `override_data` on the assume-role/execution/metrics policy documents, since those need real JSON a blanket `mock_provider` can't produce) |
| `python3 infrastructure/ecs/environments/staging/scripts/validate-import-manifest.py` | Pass — 94 addresses, all uniquely classified, summary counts match a fresh recount; verified to correctly FAIL on each of the 5 violation types the review required (missing classification, duplicate address, wrong totals, import-classified entry missing an import_id, new/unmanaged/do_not_import entry incorrectly carrying an import_id) |
| `tf-guard.sh` manual tests | `validate`/`fmt` passthrough confirmed; `plan`/`apply` correctly refused with no backend configured (today's real state); with a temporary mock backend block, correctly refused on wrong region, then correctly refused on empty state with correct account+region — all 4 checks independently verified to fire |
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
3. **IAM execution-role permission-shape decision** (§3, new this
   revision): keep the live AWS-managed-policy + narrow-inline-policy
   approach (would require restructuring `modules/iam`'s
   `task_execution` resource to attach `AmazonECSTaskExecutionRolePolicy`
   instead of building a custom inline policy), or accept Terraform's
   broader custom-inline-policy approach as the new standard (would widen
   the live role's permissions on first apply — a real, reviewable
   change, not a no-op). The naming variable (§6 of variables.tf) does not
   resolve this; it only makes the role importable by address.
4. ALB target-group health-check path (§9.5): adopt `/up`, keep `/readyz`
   and accept the live behavior change, or expose both as separate
   checks.
5. ElastiCache engine/version (§9.4, new this revision): confirm
   `elasticache_engine = "valkey"` and an appropriate `engine_version` for
   the live 7.2 line before any replication-group import is attempted —
   this is now a required variable override, not optional.
6. Security-group rule reconciliation timing (§9.6): apply the narrower
   declared rules alongside the existing broad ones now, or defer until
   the broad rules are revoked in the same change.
7. Elevated (or one-time delegated) read access for
   `ec2:DescribeSecurityGroupRules` (blocks 4 of the 11 `import_unchanged`
   commands — the SG *content* is already confirmed matching, only the
   literal rule ID is missing), `cloudwatch:DescribeAlarms`, and
   `sns:ListTopics` — not requested automatically, per the mission's
   constraint.
8. Confirmation of `module.migrate`/`module.maintenance` task-definition
   content — both are covered by the uniform `do_not_import` decision in
   §6 regardless, so this is informational for Phase B planning, not a
   Phase A blocker.
9. Human sign-off to actually begin executing §8's import commands — this
   plan prepares them, it does not run them.
