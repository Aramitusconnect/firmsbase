variable "name_prefix" {
  type = string
}

variable "aws_account_id" {
  description = "AWS account ID that owns this key. Used only to build the key policy's account-root principal ARN (\"arn:aws:iam::<account>:root\") — the standard \"Enable IAM User Permissions\" statement every AWS-console-created key gets by default, reproduced explicitly here since this module now manages the key policy rather than leaving it to AWS's own default. No default, deliberately — this reusable module must never derive the account from the ambient AWS identity running `terraform apply`; every caller supplies it explicitly. Mirrors modules/iam's identical aws_account_id variable."
  type        = string
}

variable "aws_region" {
  description = "AWS region this key and its CloudWatch Logs log groups live in. Used only to build the CloudWatch Logs service-principal ARN (\"logs.<region>.amazonaws.com\") and the EncryptionContext ARN-restriction condition below. No default, deliberately — mirrors aws_account_id above."
  type        = string
}

variable "cloudwatch_logs_log_group_arn_pattern" {
  description = "ArnLike pattern (may include a trailing \"*\") this key's policy will scope the CloudWatch Logs service principal's cryptographic grant to, via the kms:EncryptionContext:aws:logs:arn condition key CloudWatch Logs itself supplies on every Encrypt/Decrypt/GenerateDataKey call it makes for a KMS-encrypted log group. Null (default) omits the CloudWatch Logs statement entirely — a brand-new environment or a key never used for log-group encryption is unaffected. Deliberately a caller-supplied pattern, not derived from var.name_prefix inside this module: which log groups (if any) actually use this key for encryption is a decision made by the environment wiring this key into aws_cloudwatch_log_group resources, not something this reusable module should assume. See docs/ecs/state-adoption-plan.md."
  type        = string
  default     = null
}

variable "sqs_queue_arn_pattern" {
  description = "ArnLike pattern this key's policy will scope the SQS service principal's cryptographic grant to, via the kms:EncryptionContext:aws:sqs:arn condition key SQS itself supplies on every Encrypt/Decrypt/GenerateDataKey call it makes for a KMS-encrypted (SSE-KMS) queue — the exact same shape as cloudwatch_logs_log_group_arn_pattern above, for the identical reason (a KMS-encrypted AWS-managed resource authenticates to KMS via its own service-linked trust, never the calling operator's IAM permissions). Null (default) omits the SQS statement entirely."
  type        = string
  default     = null
}

variable "sns_topic_arn_pattern" {
  description = "ArnLike pattern this key's policy will scope the SNS service principal's cryptographic grant to, via the kms:EncryptionContext:aws:sns:topicArn condition key SNS itself supplies on every Encrypt/Decrypt/GenerateDataKey call it makes for a KMS-encrypted (SSE-KMS) topic — the identical shape as sqs_queue_arn_pattern above. Null (default) omits the SNS statement entirely."
  type        = string
  default     = null
}

variable "tags" {
  type    = map(string)
  default = {}
}
