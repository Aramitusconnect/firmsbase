variable "name_prefix" {
  type = string
}

variable "ecr_repository_arn" {
  type = string
}

variable "log_group_arns" {
  description = "CloudWatch log group ARNs every task definition writes to — the execution role's logs:CreateLogStream/PutLogEvents grant is scoped to exactly these, not log-group:*."
  type        = list(string)
}

variable "secret_arns" {
  description = "Every Secrets Manager secret ARN referenced by any task definition's `secrets` block (APP_KEY, DB_PASSWORD, REDIS_PASSWORD, etc. — see docs/ecs/env.ecs.example). The execution role needs secretsmanager:GetSecretValue on exactly these to resolve them at task start; it does not get access to any other secret in the account."
  type        = list(string)
  default     = []
}

variable "ssm_parameter_arns" {
  description = "Every SSM Parameter Store ARN referenced by any task definition's `secrets` block."
  type        = list(string)
  default     = []
}

variable "kms_key_arn" {
  description = "KMS key used to encrypt the secrets above and/or the S3 document bucket. Null if none is set up yet."
  type        = string
  default     = null
}

variable "s3_documents_bucket_arn" {
  description = "ARN of the (prepared, see docs/ecs/storage-readiness.md) S3 document bucket. Null until it exists — application/worker/maintenance task roles get no S3 grant at all when null, rather than a wildcard placeholder."
  type        = string
  default     = null
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

variable "tags" {
  type    = map(string)
  default = {}
}
