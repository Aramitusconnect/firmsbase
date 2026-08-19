variable "name_prefix" {
  type = string
}

variable "aws_account_id" {
  description = "AWS account ID that owns the ECS tasks assuming this module's roles. Used only to scope the shared ecs-tasks.amazonaws.com assume-role trust policy's aws:SourceAccount/aws:SourceArn conditions to this account's own ECS resources — confused-deputy protection, not a permission grant. No default, deliberately: this reusable module must never derive the account from the ambient AWS identity running `terraform apply`; every caller supplies it explicitly. See docs/ecs/state-adoption-plan.md §9.17."
  type        = string

  validation {
    condition     = can(regex("^[0-9]{12}$", var.aws_account_id))
    error_message = "aws_account_id must be exactly 12 digits."
  }
}

variable "aws_region" {
  description = "AWS region the ECS tasks assuming this module's roles run in. Used only to scope the shared assume-role trust policy's aws:SourceArn condition (arn:aws:ecs:<region>:<account>:*). No default, deliberately — mirrors aws_account_id above."
  type        = string
}

variable "task_execution_role_description" {
  description = "The task-execution role's description. No default, deliberately — this module previously declared no description argument at all on aws_iam_role.task_execution, so a default here risks silently applying a description that doesn't match a given caller's live role rather than failing loudly. This staging environment's live execution role description is \"Execution role for FirmsBase staging ECS tasks\" (confirmed via aws iam get-role). Wired only to aws_iam_role.task_execution.description — no other role in this module gets a description from this variable. See docs/ecs/state-adoption-plan.md §9.17."
  type        = string

  validation {
    condition     = length(trimspace(var.task_execution_role_description)) > 0
    error_message = "task_execution_role_description must not be empty."
  }
}

variable "task_execution_role_name" {
  description = "Null (default) falls back to \"<name_prefix>-task-execution\". See docs/ecs/state-adoption-plan.md §3B — this is a naming fix only; it does not reconcile the live role's AWS-managed-policy-based permission shape with this module's custom-inline-policy shape, which remains a separate human decision."
  type        = string
  default     = null
}

variable "task_execution_policy_name" {
  description = "The name of the task-execution role's inline policy. No default, deliberately — this module previously hardcoded \"<name_prefix>-task-execution\", but aws_iam_role_policy's name is effectively immutable (renaming requires delete+recreate), so a default here that didn't match a given caller's live policy would silently set up a replacement on the next plan rather than failing loudly. This staging environment's live inline policy is actually named \"FirmsBaseStagingSecretsAccess\" (confirmed via aws iam get-role-policy) — see docs/ecs/state-adoption-plan.md §9.10/§9.11. Setting this correctly aligns the policy's identity only; it does not reconcile the policy's content/permission shape, which remains the same separate human decision referenced above for task_execution_role_name."
  type        = string
}

variable "task_execution_managed_policy_arn" {
  description = "The AWS-managed policy ARN attached to the task-execution role via a separate, non-exclusive aws_iam_role_policy_attachment (never aws_iam_role_policy_attachments_exclusive, and never managed_policy_arns directly on aws_iam_role — either could detach an unrelated attachment this module doesn't know about). No default, deliberately: this reusable module must not assume every caller uses AmazonECSTaskExecutionRolePolicy specifically. This staging environment's live role has exactly this one attached managed policy (confirmed via aws iam list-attached-role-policies): arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy. See docs/ecs/state-adoption-plan.md §9.18."
  type        = string
}

variable "task_execution_secret_arns" {
  description = "Every Secrets Manager secret ARN the task-execution role's own inline policy must grant secretsmanager:GetSecretValue on. Required, nonempty, unique — no default, deliberately: this module previously defaulted to [] and bundled ECR/logs actions into the same inline policy alongside these secrets, duplicating what AmazonECSTaskExecutionRolePolicy (see task_execution_managed_policy_arn above) already grants. This staging environment's live inline policy (FirmsBaseStagingSecretsAccess, confirmed via aws iam get-role-policy) grants secretsmanager:GetSecretValue on exactly 4 secrets — app-key, db-password (\"database-app\"), redis-auth-token, and db-migrator (\"database-migrator\") — not the platform-notifications HMAC-key secret this module previously (incorrectly) included instead of the migrator secret. See docs/ecs/state-adoption-plan.md §9.18."
  type        = list(string)

  validation {
    condition     = length(var.task_execution_secret_arns) > 0 && length(var.task_execution_secret_arns) == length(toset(var.task_execution_secret_arns))
    error_message = "task_execution_secret_arns must be a nonempty list of unique secret ARNs."
  }
}

variable "task_execution_secrets_policy_sid" {
  description = "The Sid of the task-execution role's secrets-read statement. No default, deliberately — mirrors task_execution_policy_name's own no-default pattern above: a mismatched Sid is a genuine policy-document content difference (not cosmetic to Terraform's own diffing), so a default that didn't match a given caller's live Sid would silently plan a content change on the next apply rather than failing loudly. This staging environment's live inline policy's sole statement has Sid \"ReadFirmsBaseStagingSecrets\" (confirmed via aws iam get-role-policy) — a prior import mission wired this module's previous hardcoded \"ReadTaskSecrets\" instead, which never matched live; see docs/ecs/state-adoption-plan.md §9.19."
  type        = string
}

variable "task_execution_ssm_parameter_arns" {
  description = "Every SSM Parameter Store ARN the task-execution role's own inline policy must grant ssm:GetParameters on. Default [] omits the statement entirely (this staging environment's live inline policy has none — confirmed via aws iam get-role-policy). Renamed from the module's prior ssm_parameter_arns to make clear this is scoped only to the execution role's own inline policy, distinct from any other role's permissions in this module. See docs/ecs/state-adoption-plan.md §9.18."
  type        = list(string)
  default     = []
}

variable "task_execution_kms_decrypt_enabled" {
  description = "Whether the task-execution role's own inline policy grants kms:Decrypt on kms_key_arn. No default, deliberately (mirrors kms_encryption_enabled's own no-default rationale below): this module previously gated the execution role's KMS decrypt statement on the SAME kms_encryption_enabled flag used for the unrelated S3-document task roles' KMS grant, so enabling one silently enabled the other. This staging environment's live inline policy has no KMS statement at all (confirmed via aws iam get-role-policy) even though kms_encryption_enabled=true for the S3-document feature — a real, previously-unmodeled distinction. Set independently from kms_encryption_enabled. See docs/ecs/state-adoption-plan.md §9.18."
  type        = bool
}

variable "kms_key_arn" {
  description = "KMS key used to encrypt the S3 document bucket, and/or the task-execution role's secrets (see task_execution_kms_decrypt_enabled above for that separate gate). Value only — see kms_encryption_enabled for whether the S3-document decrypt grant is included; an unknown-until-apply ARN (e.g. before the key is created/imported) cannot be compared to null to decide a for_each instance set."
  type        = string
  default     = null
}

variable "kms_encryption_enabled" {
  description = "Whether to include the KMS decrypt grant for kms_key_arn on the S3-document task roles only (see task_execution_kms_decrypt_enabled above for the task-execution role's own, independent gate on the same key). Must be a literal true/false set explicitly by every caller — never derived from whether kms_key_arn is null, since that value can be unknown during import/plan for a not-yet-created key. No default, deliberately: a default of false would let an existing caller that already passes kms_key_arn silently lose the decrypt grant by simply omitting this variable during an upgrade, rather than failing loudly at plan/validate time."
  type        = bool
}

variable "s3_documents_bucket_arn" {
  description = "ARN of the (prepared, see docs/ecs/storage-readiness.md) S3 document bucket. Value only — see s3_documents_enabled for whether the grant is included; an unknown-until-apply ARN (e.g. before the bucket is created/imported) cannot be compared to null to decide a for_each/count instance set."
  type        = string
  default     = null
}

variable "s3_documents_enabled" {
  description = "Whether to grant the S3 document-bucket permissions for s3_documents_bucket_arn. Must be a literal true/false set explicitly by every caller — never derived from whether s3_documents_bucket_arn is null, since that value can be unknown during import/plan for a not-yet-created bucket. No default, deliberately: a default of false would let an existing caller that already passes s3_documents_bucket_arn silently lose the S3 grant by simply omitting this variable during an upgrade, rather than failing loudly at plan/validate time."
  type        = bool
}

variable "ses_events_queue_arn" {
  description = "ARN of the SES bounce/complaint SQS queue. Grants ONLY the ses_consumer task role sqs:ReceiveMessage/sqs:DeleteMessage on exactly this ARN — never the DLQ, never all queues, never any other task role. Null (the default) omits the statement entirely, matching this module's existing 'no grant until the ARN exists' convention (see s3_documents_bucket_arn above)."
  type        = string
  default     = null
}

variable "ses_sending_identity_arn" {
  description = "ARN of the verified SES identity (domain or email) outbound mail is sent from — e.g. arn:aws:ses:<region>:<account-id>:identity/<domain>. Grants ONLY the web task role ses:SendRawEmail on exactly this identity, condition-scoped to ses_authorized_from_address below. Confirmed by direct code inspection that web is the only role sending mail today (Illuminate\\Mail\\Transport\\SesTransport, synchronous from the request path — no ShouldQueue notification/mailable exists, so no worker/critical-worker role sends mail). Null (the default) omits the statement entirely, matching this module's existing 'no grant until the identity exists' convention (see s3_documents_bucket_arn/ses_events_queue_arn above)."
  type        = string
  default     = null
}

variable "ses_authorized_from_address" {
  description = "The exact From address web's SES send is authorized for (matches MAIL_FROM_ADDRESS in the environment's shared_environment — see infrastructure/ecs/environments/staging/main.tf). Enforced as a ses:FromAddress StringEquals condition on the grant above, so the web task role cannot send SES mail as any other address even though it can reach the ses:SendRawEmail action. Required (no default) whenever ses_sending_identity_arn is set — see the module's own validation."
  type        = string
  default     = null

  validation {
    condition     = var.ses_authorized_from_address == null || can(regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$", var.ses_authorized_from_address))
    error_message = "ses_authorized_from_address must look like a real email address (local-part@domain)."
  }
}

variable "task_execution_permissions_boundary_arn" {
  description = "Permissions boundary attached to the task EXECUTION role. Null (the default) attaches no boundary, which is what the already-imported staging role has — supplying a value here for staging would propose a real live mutation on an existing role. Production sets this explicitly; see environments/production/main.tf."
  type        = string
  default     = null

  validation {
    condition     = var.task_execution_permissions_boundary_arn == null || can(regex("^arn:aws:iam::[0-9]{12}:policy/", var.task_execution_permissions_boundary_arn))
    error_message = "task_execution_permissions_boundary_arn must be a customer-managed IAM policy ARN (arn:aws:iam::<account-id>:policy/<name>)."
  }
}

variable "task_permissions_boundary_arns" {
  description = "Permissions boundary per application task role, keyed by the role key (web, worker, critical_worker, scheduler, migrate, maintenance, ses_consumer). Deliberately an exhaustive explicit map rather than a single ARN plus exceptions: the migrate role's boundary differs from every other role's, and a per-role map makes each pairing individually reviewable in the plan. Empty (the default) attaches no boundary to any task role, matching the imported staging roles. When non-empty the key set must be EXACTLY the module's task role set — a missing key is a hard configuration error, never a silent null, so adding a task role cannot inherit an unreviewed boundary decision."
  type        = map(string)
  default     = {}

  validation {
    condition = length(var.task_permissions_boundary_arns) == 0 || (
      length(setsubtract(
        toset(["web", "worker", "critical_worker", "scheduler", "migrate", "maintenance", "ses_consumer"]),
        toset(keys(var.task_permissions_boundary_arns)),
      )) == 0 &&
      length(setsubtract(
        toset(keys(var.task_permissions_boundary_arns)),
        toset(["web", "worker", "critical_worker", "scheduler", "migrate", "maintenance", "ses_consumer"]),
      )) == 0
    )
    error_message = "task_permissions_boundary_arns must be empty, or contain exactly these keys: web, worker, critical_worker, scheduler, migrate, maintenance, ses_consumer."
  }

  validation {
    condition = alltrue([
      for arn in values(var.task_permissions_boundary_arns) :
      can(regex("^arn:aws:iam::[0-9]{12}:policy/", arn))
    ])
    error_message = "Every task_permissions_boundary_arns value must be a customer-managed IAM policy ARN (arn:aws:iam::<account-id>:policy/<name>)."
  }
}

variable "tags" {
  type    = map(string)
  default = {}
}
