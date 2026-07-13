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

variable "tags" {
  type    = map(string)
  default = {}
}
