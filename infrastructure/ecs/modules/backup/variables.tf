variable "name_prefix" {
  description = "Naming prefix for backup resources, matching this environment's other modules."
  type        = string
}

variable "kms_key_arn" {
  description = "KMS key ARN used to encrypt the backup vault. Reuses this environment's existing key (module.kms)."
  type        = string
}

# Mission 1B (Extreme Security Hardening), sections 49/50: audited
# infrastructure found NO aws_backup_plan anywhere in this repository,
# and the RDS instance itself has no Terraform representation at all
# (it predates this Terraform config — see docs/ecs/state-adoption-plan.md
# and rds_instance_id/rds_security_group_id being plain input variables,
# not resources). Rather than importing/managing the live RDS instance
# itself (a materially riskier, separate undertaking this mission
# explicitly does not attempt — see the final report), this module
# targets it by RESOURCE TAG, which AWS Backup supports without the
# resource being Terraform-managed. Complete no-op when
# var.enabled = false (the default).
variable "enabled" {
  description = "Whether to create the backup vault/plan/selection at all. Defaults to false (complete no-op)."
  type        = bool
  default     = false
}

variable "backup_target_tag_key" {
  description = "The tag key AWS Backup uses to select resources (e.g. RDS) for this plan. The tagged resource(s) must be tagged with this key/value pair out-of-band — this module never tags anything itself, since it doesn't manage the RDS resource."
  type        = string
  default     = "backup-plan"
}

variable "backup_target_tag_value" {
  description = "The tag value paired with backup_target_tag_key."
  type        = string
  default     = "firmsvault-daily"
}

variable "schedule_cron" {
  description = "AWS Backup cron expression for the backup schedule."
  type        = string
  default     = "cron(0 8 * * ? *)" # 08:00 UTC daily
}

variable "retention_days" {
  description = "Days to retain each recovery point before AWS Backup deletes it."
  type        = number
  default     = 35
}

variable "tags" {
  description = "Tags applied to the backup vault/plan themselves (not the tag AWS Backup uses to SELECT target resources — see backup_target_tag_key)."
  type        = map(string)
  default     = {}
}
