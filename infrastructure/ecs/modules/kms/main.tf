# One customer-managed key for this application's secrets/S3/CloudWatch-Logs
# encryption.
#
# The key policy is explicitly managed (not left to AWS's default) for
# exactly one reason: a real, evidence-proven incident (see
# docs/ecs/state-adoption-plan.md) — AWS's own default key policy (a single
# "Enable IAM User Permissions" statement granting the account root kms:*)
# does NOT trust the CloudWatch Logs service principal, so any
# aws_cloudwatch_log_group referencing this key via kms_key_id fails to
# create with "AccessDeniedException: The specified KMS key does not exist
# or is not allowed to be used" — CloudWatch Logs performs its own
# Encrypt/Decrypt/GenerateDataKey calls against the key using ITS OWN
# service-linked trust granted via the key policy, never the calling
# operator's IAM permissions, so no IAM-side permission fixes this.
#
# The account-root "Enable IAM User Permissions" statement below is
# preserved byte-for-byte from AWS's own default policy (confirmed via a
# read-only `aws kms get-key-policy` against the live key) — permission
# delegation continues to happen via the IAM policies attached to specific
# roles (see modules/iam), exactly as before. Only a second, narrowly
# scoped statement is added on top.
data "aws_iam_policy_document" "this" {
  statement {
    sid    = "Enable IAM User Permissions"
    effect = "Allow"

    principals {
      type        = "AWS"
      identifiers = ["arn:aws:iam::${var.aws_account_id}:root"]
    }

    actions   = ["kms:*"]
    resources = ["*"]
  }

  # Omitted entirely when var.cloudwatch_logs_log_group_arn_pattern is null
  # (default) — a brand-new environment, or a key never wired into any log
  # group's kms_key_id, is unaffected. Grants only the five actions AWS's
  # own CloudWatch Logs KMS-encryption documentation lists as required —
  # never kms:*, never a bare service-principal grant with no condition.
  # "Resource": "*" here matches AWS's own documented example for this
  # exact statement shape (see AWS's "Encrypt log data using CMKs"
  # documentation) — key-policy statements are already scoped to the one
  # key they're attached to; the real scoping is the ArnLike condition
  # below, restricting this grant to only the log groups whose encryption
  # context the caller (this module's caller) explicitly opted into.
  dynamic "statement" {
    for_each = var.cloudwatch_logs_log_group_arn_pattern == null ? [] : [var.cloudwatch_logs_log_group_arn_pattern]
    content {
      sid    = "AllowCloudWatchLogsEncryption"
      effect = "Allow"

      principals {
        type        = "Service"
        identifiers = ["logs.${var.aws_region}.amazonaws.com"]
      }

      actions = [
        "kms:Encrypt",
        "kms:Decrypt",
        "kms:ReEncrypt*",
        "kms:GenerateDataKey*",
        "kms:Describe*",
      ]
      resources = ["*"]

      condition {
        test     = "ArnLike"
        variable = "kms:EncryptionContext:aws:logs:arn"
        values   = [statement.value]
      }
    }
  }
}

resource "aws_kms_key" "this" {
  description             = "FirmsBase application key — Secrets Manager values, S3 document bucket (see docs/ecs/storage-readiness.md, docs/ecs/iam-matrix.md)"
  deletion_window_in_days = 30
  enable_key_rotation     = true
  policy                  = data.aws_iam_policy_document.this.json

  tags = var.tags
}

resource "aws_kms_alias" "this" {
  name          = "alias/${var.name_prefix}-app"
  target_key_id = aws_kms_key.this.key_id
}
