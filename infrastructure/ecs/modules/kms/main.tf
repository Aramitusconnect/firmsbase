# One customer-managed key for this application's secrets/S3 encryption.
# Uses AWS's default key policy (full access for the account root,
# permission delegation happens via the IAM policies attached to specific
# roles — see modules/iam) rather than a custom key policy, since no
# cross-account or unusual access pattern is needed here.

resource "aws_kms_key" "this" {
  description             = "FirmsBase application key — Secrets Manager values, S3 document bucket (see docs/ecs/storage-readiness.md, docs/ecs/iam-matrix.md)"
  deletion_window_in_days = 30
  enable_key_rotation     = true

  tags = var.tags
}

resource "aws_kms_alias" "this" {
  name          = "alias/${var.name_prefix}-app"
  target_key_id = aws_kms_key.this.key_id
}
