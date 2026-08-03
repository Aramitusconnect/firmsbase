# IAM Matrix

**Date:** 2026-07-13
**Branch:** `feature/ecs-readiness-foundation`

This document is the human-readable mirror of `infrastructure/ecs/modules/iam/main.tf` — every permission listed here is exactly what that Terraform module grants, no more. If the two ever disagree, the Terraform is what actually runs; treat a mismatch as a bug in this document, not the other way around.

**No role in this design has**: `AdministratorAccess`, broad `iam:*`, unrestricted `s3:*`, unrestricted `secretsmanager:*`, unrestricted `kms:*`, EC2 instance-profile credentials, or any permission to modify ECS infrastructure (create/update/delete task definitions, services, clusters). Every grant below is scoped to a specific ARN or a specific condition.

## Two kinds of role, one for each half of "who's asking"

- **Task execution role** — assumed by the **ECS agent**, not application code. One per environment, shared by every task definition. Does the things needed to *start* a container: pull the image, write logs, resolve secrets into environment variables before the app process starts.
- **Task role** — assumed by the AWS SDK **inside the running container**, when application/PHP code calls AWS APIs. One per ECS role (web/worker/critical-worker/scheduler/migrate/maintenance/ses-consumer), scoped to only what that specific role's code path concretely does today.

## Task execution role (`<prefix>-task-execution`)

| Permission | Resource scope | Why |
|---|---|---|
| `ecr:GetAuthorizationToken` | `*` (AWS does not support resource-level scoping for this action — the one unavoidable wildcard in this entire design) | Required to authenticate to ECR at all before any pull. |
| `ecr:BatchCheckLayerAvailability`, `ecr:GetDownloadUrlForLayer`, `ecr:BatchGetImage` | The single `firmsbase-app` ECR repository ARN only | Pull the application image — no other repository. |
| `logs:CreateLogStream`, `logs:PutLogEvents` | Exactly the 7 per-role CloudWatch log groups this application creates (`/ecs/<prefix>/{web,worker,critical-worker,scheduler,migrate,maintenance,ses-consumer}`) | Write container stdout/stderr — no other log group in the account. |
| `secretsmanager:GetSecretValue` | Exactly the secret ARNs referenced by this environment's task definitions (`APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY` — see [env.ecs.example](env.ecs.example)) | Resolve `secrets` blocks at task start. No other secret in the account, including secrets belonging to other applications/environments in the same account. This is one shared execution role for every task definition (see "Two kinds of role" above) — the least-privilege boundary here is per-*secret*-ARN, not per-role, since the ECS agent (not application code) is what assumes this role. |
| `kms:Decrypt` | The one application KMS key | Decrypt the above secrets (Secrets Manager encrypts with this key). |

## Task roles (one per ECS role)

All seven are created; only `web`, `worker`, `critical_worker`, and `maintenance` receive the S3 grant (below) — `scheduler`, `migrate`, and `ses_consumer` get none, because none has any documented need for object storage access today.

| Permission | Resource scope | Granted to | Why |
|---|---|---|---|
| `s3:GetObject`, `s3:PutObject`, `s3:DeleteObject` | `<documents-bucket-arn>/*` | web, worker, critical_worker, maintenance | Read/write the (prepared, not-yet-used — see [storage-readiness.md](storage-readiness.md)) document bucket, once real document I/O exists. |
| `s3:ListBucket` | `<documents-bucket-arn>` | same four | List objects (needed for `Storage::disk('s3')` operations like `exists()`/`files()`). |
| `kms:Decrypt`, `kms:GenerateDataKey` | The one application KMS key | same four | Read/write SSE-KMS-encrypted objects in the document bucket. |
| `cloudwatch:PutMetricData` | `*`, condition-scoped to `cloudwatch:namespace = "FirmsBase"` | **all seven roles** | Emit custom application metrics (queue depth, scheduler heartbeat age, etc. — see [observability.md](observability.md)). `PutMetricData` does not support resource-level ARN scoping at all (an AWS API limitation, not a design shortcut); the namespace condition is the strongest available restriction — it cannot write to any other application's CloudWatch namespace. |
| `sqs:ReceiveMessage`, `sqs:DeleteMessage` | Exactly the SES bounce/complaint queue ARN (`var.ses_events_queue_arn`) | **ses_consumer only** | The only two SQS calls `App\Console\Commands\ConsumeSesEventsCommand` makes anywhere in its code (confirmed by direct inspection). No `sqs:GetQueueAttributes`, `sqs:ChangeMessageVisibility`, `sqs:SendMessage`, `sqs:PurgeQueue`, `sqs:SetQueueAttributes`, `sqs:GetQueueUrl`, or `sqs:*` wildcard — none is called anywhere in this consumer. No permission on the dead-letter queue (SQS's own redrive policy delivers there automatically; this consumer never reads or deletes from it) and no permission on any other queue. No other task role receives any SQS permission at all. |
| `ses:SendRawEmail` | Exactly the verified sending identity (`var.ses_sending_identity_arn`), condition-scoped to `ses:FromAddress = var.ses_authorized_from_address` | **web only** | The only SES call `Illuminate\Mail\Transport\SesTransport` makes (`$this->ses->sendRawEmail(...)` — confirmed by direct inspection of `vendor/laravel/framework/src/Illuminate/Mail/Transport/SesTransport.php`; `ses:SendEmail` is never called and is not granted). All mail in this application is sent **synchronously from the web request path** — no `ShouldQueue` notification or mailable exists anywhere in `app/Notifications`/`app/Mail` — so `worker`/`critical-worker` never send mail and receive no SES grant. Not granted to `ses_consumer` either: it only reads correlation rows and writes suppression/receipt rows, never constructs or sends an email (see above). No `ses:*` wildcard, no grant on all identities. |

### SES-sending permission history (resolved drift)

A prior revision of this branch's own audit found that **no role, including `web`, had this permission represented in Terraform at all** — yet staging's verified runtime showed `web` successfully sending mail via the ECS task role. Investigation confirmed: Terraform has never managed any `ses:*` permission in this repository's history (checked via `git log` against `infrastructure/ecs/modules/iam/` — the permission does not appear in the original foundation commit or any commit since), meaning the working SES send depended entirely on an out-of-band/manual AWS IAM policy attached directly to the `web` task role outside Terraform. Since IAM inline/managed policies are independently named resources, a `terraform apply` against the *previous* Terraform would not itself have deleted that manual policy — but the role's continued ability to send mail depended entirely on undocumented tribal knowledge, invisible to code review, and would be silently lost if the `web` task role were ever destroyed and recreated (a `name_prefix` change, a role replacement, disaster recovery into a fresh account, etc.), since the manual policy exists only on the specific IAM role object, not anything Terraform tracks or could recreate. This is now resolved: the grant above is fully Terraform-managed (`data.aws_iam_policy_document.task_web_ses_send` / `aws_iam_role_policy.task_web_ses_send` in `infrastructure/ecs/modules/iam/main.tf`), so it survives a role rebuild automatically. **Still requires a later, read-only AWS inspection** (out of this mission's scope, which does not query or modify AWS) to confirm the *exact* manual policy currently attached to the live staging `web` role matches this Terraform grant exactly (same identity ARN, same From-address condition, no broader scope) before a real `terraform apply` — if the manual policy is broader (e.g. no From-address condition, or `Resource: "*"`), applying this narrower Terraform-managed policy alongside it would not itself break anything (both would independently authorize sending, and the manual one should then be removed by hand as a follow-up), but if the manual policy uses a *different* identity ARN than assumed here, that must be corrected in `terraform.tfvars` before applying.

### Why `critical_worker` doesn't get a different/stronger permission set than `worker`

The critical (trust-queue) worker's isolation is about **capacity and alarming** (dedicated ECS service, never scaled to zero, tighter alarm thresholds — see [queue-and-redis-architecture.md](queue-and-redis-architecture.md)), not a different AWS permission surface. Both worker roles read/write the same document bucket under the same KMS key; there is no trust-specific AWS resource today (no trust-specific S3 prefix, no trust-specific secret) to scope more tightly than "the same document bucket every worker uses." If one is introduced later (e.g., a trust-document-specific S3 prefix with its own bucket policy), `critical_worker`'s task role is where that tighter scope would attach — the Terraform module already gives it a distinct role specifically so this is a one-module change, not a refactor.

### Why `scheduler` and `migrate` have no S3/document grant

Neither role's container command (`schedule:work` / `migrate --force`) touches document storage in any code path that exists today (see [ec2-dependency-audit.md](ec2-dependency-audit.md)). Granting S3 access "just in case" would violate least privilege for no concrete benefit — if a future scheduled command or migration genuinely needs it, that's a one-line addition to `locals.s3_document_role_names` in `infrastructure/ecs/modules/iam/main.tf`, made deliberately when the need is real.

## ECS Exec (`enable_execute_command`)

Off by default in `modules/ecs_service` (`var.enable_execute_command = false`). Turning it on grants operators shell access into a running task via `aws ecs execute-command` — a real access-expansion decision that should be made deliberately per environment (and typically requires its own IAM permission on the *operator's* principal, `ecs:ExecuteCommand`, which this document does not grant to any application role — only to whichever human/CI principal an org decides should have it, outside this Terraform). Not turned on for staging by this branch.

## What explicitly does NOT exist in this design

- No role can create, update, or delete an ECS task definition, service, or cluster (`ecs:RegisterTaskDefinition`, `ecs:UpdateService`, etc. are not granted to any task role — only to whatever CI/CD or operator principal runs `terraform apply`/the deploy pipeline, which is outside this module's scope, see [ci-cd pipeline](../../.github/workflows/ecs-pipeline.yml)).
- No role has `iam:PassRole`, `iam:CreateRole`, or any IAM-modifying permission.
- No role has EC2 instance-profile credentials — Fargate has no EC2 instances to have a profile on, and no role assumes an EC2-oriented policy.
- No role has `secretsmanager:PutSecretValue`, `secretsmanager:DeleteSecret`, or `secretsmanager:*` — only `GetSecretValue` on specific ARNs, and only the execution role has even that.
- No role has cross-account access of any kind.
