variable "name_prefix" {
  description = "Naming prefix for security-monitoring resources, matching this environment's other modules."
  type        = string
}

variable "aws_account_id" {
  description = "AWS account ID, used to scope the CloudTrail bucket policy to this account only."
  type        = string
}

variable "kms_key_arn" {
  description = "KMS key ARN used to encrypt the CloudTrail S3 bucket and log group. Reuses this environment's existing key (module.kms) rather than provisioning a second one."
  type        = string
}

# Mission 1B (Extreme Security Hardening), sections 39-42: each detective
# control has its own cost/organizational profile and must be evaluated
# and enabled independently, not bundled — see this mission's own final
# report for the classification of each ("EXTERNAL_CONFIGURATION_REQUIRED"
# / "OWNER_DECISION_REQUIRED"). Every flag below defaults to false; with
# no tfvars overrides this entire module creates nothing.

variable "enable_cloudtrail" {
  description = "Whether to create a CloudTrail trail (S3 bucket + KMS-encrypted, log-file-validation enabled) for this account/region. Has an ongoing S3 storage cost proportional to API call volume."
  type        = bool
  default     = false
}

variable "cloudtrail_log_retention_days" {
  description = "S3 lifecycle expiration for CloudTrail log objects, once enabled."
  type        = number
  default     = 365
}

variable "enable_guardduty" {
  description = "Whether to enable a GuardDuty detector for this account/region. Has an ongoing per-event-analyzed cost — see AWS GuardDuty pricing before enabling in a real account."
  type        = bool
  default     = false
}

variable "enable_security_hub" {
  description = "Whether to enable Security Hub (posture aggregation) for this account/region. Has an ongoing per-check/per-finding-ingestion cost."
  type        = bool
  default     = false
}

variable "enable_iam_access_analyzer" {
  description = "Whether to create an IAM Access Analyzer for this account. No direct AWS charge for account-scoped analyzers as of this writing, but confirm current pricing before enabling in a real account."
  type        = bool
  default     = false
}

variable "tags" {
  description = "Tags applied to every resource this module creates."
  type        = map(string)
  default     = {}
}
