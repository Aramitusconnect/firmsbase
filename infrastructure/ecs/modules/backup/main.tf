# Mission 1B (Extreme Security Hardening), sections 49/50. See
# variables.tf for why this targets resources by tag rather than
# managing RDS directly. Complete no-op when var.enabled = false.

resource "aws_backup_vault" "this" {
  count = var.enabled ? 1 : 0

  name        = "${var.name_prefix}-backup-vault"
  kms_key_arn = var.kms_key_arn
  tags        = var.tags
}

resource "aws_backup_plan" "this" {
  count = var.enabled ? 1 : 0

  name = "${var.name_prefix}-backup-plan"

  rule {
    rule_name         = "daily"
    target_vault_name = aws_backup_vault.this[0].name
    schedule          = var.schedule_cron

    lifecycle {
      delete_after = var.retention_days
    }
  }

  tags = var.tags
}

# Least-privilege role for the AWS Backup service to assume when taking
# backups of tagged resources — the AWS-managed policy scoped to exactly
# the backup operations the service needs, not a broad admin grant.
data "aws_iam_policy_document" "backup_assume_role" {
  count = var.enabled ? 1 : 0

  statement {
    effect  = "Allow"
    actions = ["sts:AssumeRole"]

    principals {
      type        = "Service"
      identifiers = ["backup.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "backup" {
  count = var.enabled ? 1 : 0

  name               = "${var.name_prefix}-backup-role"
  assume_role_policy = data.aws_iam_policy_document.backup_assume_role[0].json
  tags               = var.tags
}

resource "aws_iam_role_policy_attachment" "backup" {
  count = var.enabled ? 1 : 0

  role       = aws_iam_role.backup[0].name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AWSBackupServiceRolePolicyForBackup"
}

resource "aws_backup_selection" "this" {
  count = var.enabled ? 1 : 0

  name         = "${var.name_prefix}-backup-selection"
  plan_id      = aws_backup_plan.this[0].id
  iam_role_arn = aws_iam_role.backup[0].arn

  selection_tag {
    type  = "STRINGEQUALS"
    key   = var.backup_target_tag_key
    value = var.backup_target_tag_value
  }
}
