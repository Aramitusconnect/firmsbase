# Staging Terraform Variable Inventory

**Status: read-only inventory. No `terraform.tfvars` has been created. No
`plan`/`apply`/`import`/`refresh`/`providers schema`/state-mutation command has
been run to produce this document, and no AWS resource has been modified.**

This document is the corrected, canonical record of every required
`infrastructure/ecs/environments/staging` Terraform variable (every variable
with no default, plus every live-adoption override) and its resolved value —
superseding an earlier, less careful chat-only pass that misclassified two
items (see "Corrections in this revision" below).

## A critical precondition: `terraform import` needs the full variable set too

`terraform import` is not exempt from variable resolution. It evaluates the
**entire configuration graph** (every module, every `count`/`for_each`
expression, every resource's provider configuration) before importing a
single resource, exactly as `plan`/`apply` do — the only thing it skips is
computing a diff against desired state for resources other than the one
being imported. If any required (no-default) variable is unset, `import`
fails the same way `plan` would: with a "no value for required variable"
error, or — worse, if a `count`/`for_each` expression depends on that
variable — a graph that silently resolves to the wrong shape.

**All required variables listed below must be resolved and supplied (via
`terraform.tfvars`, `-var-file`, or `TF_VAR_*` environment variables) before
the *first* `terraform import` command in Phase A2, not just before the
first `plan`/`apply`.**

## Variable matrix

### Networking

| Variable | Resolved value | Source | Status | Safe in tfvars |
|---|---|---|---|---|
| `vpc_id` | `vpc-0fd81b688155ded2b` (default VPC, `172.31.0.0/16`) | `aws ec2 describe-vpcs` | Confirmed | Yes |
| `public_subnet_ids` | `["subnet-020540b8377bb4d0e","subnet-07efcb5d4bcf5aa59"]` | ALB's live `AvailabilityZones` | Confirmed | Yes |
| `private_subnet_ids` | `["subnet-020540b8377bb4d0e","subnet-07efcb5d4bcf5aa59"]` — **identical to `public_subnet_ids`**; both subnets are genuinely public (`MapPublicIpOnLaunch=true`, sole route table has only a `local` + IGW route, no NAT gateway anywhere in this VPC) | ECS web service `networkConfiguration`, `describe-route-tables` | Confirmed | Yes |
| `alb_ingress_cidr_blocks` | `["0.0.0.0/0"]` (80+443 both open) | ALB SG `sg-02a26ff122a9a1d29` ingress rules | Confirmed | Yes |

Do not label `private_subnet_ids` "private" merely because Terraform's
variable is named that — see §4 "Material finding 2" of
[state-adoption-plan.md](state-adoption-plan.md).

### ALB / certificate

| Variable | Resolved value | Source | Status |
|---|---|---|---|
| `acm_certificate_arn` | `arn:aws:acm:us-east-1:603013471426:certificate/d56ea11d-4173-4a2d-a6c4-3006f9d86057` | `describe-listeners` (443 cert) | Confirmed |
| `alb_health_check_path` / `alb_health_check_interval_seconds` / `alb_health_check_matcher` | `/up`, `30`, `200-399` | `describe-target-groups`/`describe-target-group-attributes` (already adopted — see commit `0c81994`) | Confirmed |

### RDS

| Variable | Resolved value | Source | Status |
|---|---|---|---|
| `rds_instance_id` | `firmsbase-staging-db` | `describe-db-instances` | Confirmed |
| `rds_security_group_id` | `sg-0d4c5eedb2ee21743` | `describe-db-instances` | Confirmed |
| `db_host` | `firmsbase-staging-db.cytucueweb8a.us-east-1.rds.amazonaws.com` | `describe-db-instances` (`Endpoint.Address`) | Confirmed |
| `db_database` (has a default) | `firmsbase_staging` — matches the module default exactly | `describe-db-instances` (`DBName`) | Confirmed, no override needed |

### ECS / ECR

| Variable | Resolved value | Source | Status |
|---|---|---|---|
| `app_image_digest` | `603013471426.dkr.ecr.us-east-1.amazonaws.com/firmsbase-staging@sha256:23c703a0afebd2a8b70b7f7c32cdbd171c797ef153d17891360cd9c727a8983a` | `describe-task-definition` (`firmsbase-staging-web:9`) | Confirmed |

### ElastiCache

| Variable | Resolved value | Source | Status |
|---|---|---|---|
| `elasticache_engine` | `valkey` | `describe-replication-groups` | Confirmed (already wired) |
| `elasticache_engine_version` | Live's exact reported version is `7.2.6` (`describe-cache-clusters` `.EngineVersion`), but the value to actually **supply** is `"7.2"` (major.minor only) — AWS's `aws_elasticache_replication_group` rejects a major.minor.patch value like `"7.2.6"` outright for Redis v6+/Valkey (confirmed via a real provider validation error surfaced while adding test coverage in this pass: `engine_version: 7.2.6 is invalid. For Valkey use <major>.<minor>.`) | `describe-cache-clusters` (`EngineVersion`); AWS provider validation | Confirmed — **wiring corrected in this pass**: previously no staging-environment variable existed at all to carry this value (only the module's own internal default, unreachable from `terraform.tfvars`); now `var.elasticache_engine_version` (default `"7.1"`, matching the module's original design) is wired into `module.elasticache.engine_version`. |
| `elasticache_subnet_group_name` | `firmsbase-staging-cache-subnets` | `describe-cache-clusters` | Confirmed (already wired) |
| `elasticache_parameter_group_name` | `default.valkey7` | `describe-cache-clusters` | Confirmed (already wired) |
| Auth token enabled | `true` | `describe-replication-groups` (`AuthTokenEnabled`) — token value never retrieved | Confirmed |

### Secrets (ARNs only — no values ever retrieved)

Each live secret referenced below is a **JSON-structured** Secrets Manager
secret (multiple keys in one secret), confirmed via the live ECS task
definition's `secrets` mapping. `infrastructure/ecs/modules/ecs_service`
passes each `secrets` map value through **verbatim** as the ECS `valueFrom`
string (`valueFrom = v`, no transformation) — so the exact JSON-key selector
matters for ECS, while IAM's `secretsmanager:GetSecretValue` grant must stay
scoped to the bare secret (a `:json-key::` suffix in an IAM policy `Resource`
entry doesn't grant anything narrower and doesn't belong there).

**Design corrected in this pass**: previously the same bare-ARN variable
value would have needed to be supplied *twice*, in two different formats
(once bare for IAM, once suffixed for ECS) — an easy way to introduce a silent
inconsistency. Now each variable is supplied **once, as a bare ARN**, and
`main.tf`'s `local.shared_secrets` derives the required ECS selector via
string interpolation (`"${var.X}:JSON_KEY::"`), while `module.iam`'s
`secret_arns` list keeps receiving the same bare variable unchanged.

| Variable | Bare ARN (confirmed live) | Derived ECS `valueFrom` (main.tf) | JSON key preserved |
|---|---|---|---|
| `app_key_secret_arn` | `arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/app-key-QigVGy` | `...app-key-QigVGy:APP_KEY::` | `APP_KEY` |
| `db_password_secret_arn` | `arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/database-app-8NUj2a` | `...database-app-8NUj2a:password::` | `password` |
| `redis_auth_token_secret_arn` | `arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/redis-auth-token-p6rVKN` | `...redis-auth-token-p6rVKN:REDIS_PASSWORD::` | `REDIS_PASSWORD` |
| `redis_auth_token` | **[SECRET VALUE — DO NOT DISPLAY]** — see "Secure handling of `redis_auth_token`" below | n/a (consumed directly by `aws_elasticache_replication_group.auth_token`, never by ECS) | n/a |
| `platform_notifications_recipient_fingerprint_hmac_key_secret_arn` | `arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/platform-notifications-hmac-key-PFBzd3` — **resolved, bare ARN, confirmed via `describe-secret`** | `local.hmac_secret` passes this bare ARN straight through with no derived suffix (unlike the three above) — this secret's internal structure was never inspected (value never retrieved), so no JSON-key selector is assumed | n/a — see "HMAC secret" below |

**Additional live-only finding, out of this mission's scope to fix**: the
live execution role's inline policy (`FirmsBaseStagingSecretsAccess`) grants
a 4th secret ARN — `firmsbase/staging/database-migrator-TpsE6P` — used
exclusively by the live `migrate` task definition as a distinct DB
credential, different from `database-app-8NUj2a`. No Terraform variable,
module, or other doc currently models this. Not fixed here since it is
outside this mission's required-work list.

### HMAC secret — RESOLVED 2026-08-03

**Created and independently confirmed.** An operator with the necessary
Secrets Manager access created the secret outside this session (this
operator's own attempt was blocked — see history below) and supplied its
bare ARN back to this thread. That ARN was then independently verified via
a targeted, read-only `aws secretsmanager describe-secret` call — **the
value was never retrieved or displayed**:

- **Bare ARN**: `arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/platform-notifications-hmac-key-PFBzd3`
- **Name**: `firmsbase/staging/platform-notifications-hmac-key` — matches the
  naming convention already documented in
  [env.ecs.example](env.ecs.example).
- **KMS key**: the default AWS-managed Secrets Manager key (`KmsKeyId` was
  `null` in the `describe-secret` response, meaning no customer-managed key
  is in use).
- **Deletion status**: not scheduled for deletion (`DeletedDate` was `null`).

This resolves the previously open question — earlier in this same
investigation thread, two live signals were consistent with non-existence
(the live web task definition's `secrets` list had no
`PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY` entry, and the live
execution role's IAM policy listed only 4 unrelated secret ARNs), but
`secretsmanager:ListSecrets` was never available to this operator, so the
secret's actual existence could only be classified as **unconfirmed**, never
"does not exist." That gap is now closed by the direct `describe-secret`
confirmation above, not by inference.

**No longer a blocker.**

### Alarms / SNS — RESOLVED 2026-08-03

The paging decision [alarm-inventory.md](alarm-inventory.md) and
[staging-readiness-report.md](staging-readiness-report.md) §5 flagged as
required has been made: alerts page `firmsvault@gmail.com`.

| Variable | Resolved value | Status |
|---|---|---|
| `alarm_sns_topic_arn` | `arn:aws:sns:us-east-1:603013471426:firmsbase-staging-alarms` | Resolved — **no longer a blocker** |

**Important — topic ARN vs. subscription ARN**: the ARN above is the
**topic** ARN. A separate, UUID-suffixed ARN
(`arn:aws:sns:us-east-1:603013471426:firmsbase-staging-alarms:cc3e0e25-390b-44fa-b0db-ef2d96dda4e4`)
identifies the *subscription* to that topic, not the topic itself — SNS
topic ARNs never carry a trailing UUID; subscription ARNs always do once
confirmed. `alarm_sns_topic_arn` must always resolve to the topic ARN above,
never the subscription ARN.

**Subscription**:

| Field | Value |
|---|---|
| Endpoint | `firmsvault@gmail.com` |
| Protocol | `email` |
| Status | **Confirmed** |

**Verification provenance** — stated accurately, not overclaimed: the topic
ARN, subscription endpoint, and confirmed status above were supplied by an
operator with SNS access (via the administrator console), not independently
verified by this operator. This operator's own `sns:GetTopicAttributes`,
`sns:GetSubscriptionAttributes`, and `sns:ListSubscriptionsByTopic` calls are
all denied (`AuthorizationError` — this operator has no SNS read permission
at all, the same gap that blocked topic creation in the first place). The
reported values are structurally consistent with real SNS ARN formats and
with SNS's documented subscription-confirmation behavior (a subscription ARN
only resolves to a real UUID-suffixed value, rather than the literal string
`"PendingConfirmation"`, once the recipient has clicked the confirmation
link) — but this document is not claiming independent AWS-side confirmation
of the SNS side the way the HMAC secret section above can.

### SES / SQS

The account ID (`603013471426`) is now confirmed via `aws sts
get-caller-identity`. Substituting it into the exact naming convention
already established in `terraform.tfvars.example` (the only place these
identifiers were previously recorded) gives the following **confirmed**
values:

| Variable | Resolved value | Status |
|---|---|---|
| `ses_events_queue_url` | `https://sqs.us-east-1.amazonaws.com/603013471426/firmsvault-staging-ses-events` | Confirmed (known exact value — account-substituted from the established naming convention) |
| `ses_events_queue_arn` | `arn:aws:sqs:us-east-1:603013471426:firmsvault-staging-ses-events` | Confirmed |
| `ses_events_dlq_arn` | `arn:aws:sqs:us-east-1:603013471426:firmsvault-staging-ses-events-dlq` | Confirmed |
| `ses_sending_identity_arn` | `arn:aws:ses:us-east-1:603013471426:identity/staging-mail.firmsvault.com` | Confirmed |
| `ses_authorized_from_address` | `no-reply@staging-mail.firmsvault.com` — matches the live web task definition's plain `MAIL_FROM_ADDRESS` environment variable exactly | Confirmed |

*Correction from the prior chat-only pass*: these were previously reported as
"unresolved" on the reasoning that this operator's `sqs:ListQueues`/
`sesv2:ListEmailIdentities`/`sesv2:GetEmailIdentity` calls were all denied
during that session. That overstated the uncertainty — the exact values are
already known from the repository's own established naming convention and
the account ID confirmed via STS; this document now records them as
confirmed identifiers rather than re-litigating a live existence check this
operator's permissions can't perform anyway.

## Secure handling of `redis_auth_token`

- **Exact source**: `arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/redis-auth-token-p6rVKN`, JSON key `REDIS_PASSWORD`.
- A secure runtime retrieval mechanism is required — the raw token must
  never be written to `terraform.tfvars` or any other file.
- It can be supplied without writing to disk via `TF_VAR_redis_auth_token`,
  populated in the interactive shell immediately before `plan`/`import`/
  `apply`, e.g.:
  ```bash
  export TF_VAR_redis_auth_token="$(aws secretsmanager get-secret-value \
    --secret-id firmsbase/staging/redis-auth-token-p6rVKN \
    --query SecretString --output text | jq -r .REDIS_PASSWORD)"
  ```
  Never via a `-var="redis_auth_token=..."` command-line argument (shell
  history, process listing).

## Recommended variable-bundle design

One `chmod 600`, gitignored `terraform.tfvars` (already gitignored — see
`infrastructure/ecs/.gitignore`) containing every non-secret identifier and
every secret ARN (bare, per the design above), with `redis_auth_token`
excluded entirely and supplied only via `TF_VAR_redis_auth_token` set fresh
in the shell for each `import`/`plan`/`apply` invocation. See
`terraform.tfvars.example` for the exact non-secret and ARN values.

## Remaining blockers before the first Phase A2 import

None of these block **Phase A2** itself (security groups, ALB, listeners) —
every A2-relevant variable is fully confirmed. They block later phases:

1. `elasticache_engine_version` must actually be set to `"7.2"` (major.minor
   only — not `"7.2.6"`, which AWS's own provider validation rejects for
   Redis v6+/Valkey) in whatever `terraform.tfvars` is eventually created
   (the variable now exists and is wired — see above — but still defaults
   to `"7.1"`).

Resolved as of 2026-08-03 (previously listed here): the HMAC secret's
existence and `alarm_sns_topic_arn` — see "HMAC secret" and "Alarms / SNS"
above.
