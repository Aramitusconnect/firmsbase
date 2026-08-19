# Mission 1B (Extreme Security Hardening), sections 39-42. Every
# resource in this file is gated behind its own boolean flag (see
# variables.tf), each defaulting to false — this module is a complete
# no-op until an owner explicitly opts each control in via tfvars,
# after reviewing that control's specific cost/organizational impact.

# --- CloudTrail -------------------------------------------------------

resource "aws_s3_bucket" "cloudtrail" {
  count = var.enable_cloudtrail ? 1 : 0

  bucket = "${var.name_prefix}-cloudtrail-logs"
}

resource "aws_s3_bucket_public_access_block" "cloudtrail" {
  count = var.enable_cloudtrail ? 1 : 0

  bucket                  = aws_s3_bucket.cloudtrail[0].id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_server_side_encryption_configuration" "cloudtrail" {
  count = var.enable_cloudtrail ? 1 : 0

  bucket = aws_s3_bucket.cloudtrail[0].id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm     = "aws:kms"
      kms_master_key_id = var.kms_key_arn
    }
    bucket_key_enabled = true
  }
}

resource "aws_s3_bucket_versioning" "cloudtrail" {
  count = var.enable_cloudtrail ? 1 : 0

  bucket = aws_s3_bucket.cloudtrail[0].id

  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "cloudtrail" {
  count = var.enable_cloudtrail ? 1 : 0

  bucket = aws_s3_bucket.cloudtrail[0].id

  rule {
    id     = "expire-old-cloudtrail-logs"
    status = "Enabled"

    filter {}

    expiration {
      days = var.cloudtrail_log_retention_days
    }
  }
}

# CloudTrail's own service requirement — see
# https://docs.aws.amazon.com/awscloudtrail/latest/userguide/create-s3-bucket-policy-for-cloudtrail.html.
# Scoped to this account's own trail only (aws:SourceArn condition), not
# a broad "any CloudTrail" grant.
data "aws_iam_policy_document" "cloudtrail_bucket" {
  count = var.enable_cloudtrail ? 1 : 0

  statement {
    sid    = "AWSCloudTrailAclCheck"
    effect = "Allow"

    principals {
      type        = "Service"
      identifiers = ["cloudtrail.amazonaws.com"]
    }

    actions   = ["s3:GetBucketAcl"]
    resources = [aws_s3_bucket.cloudtrail[0].arn]

    condition {
      test     = "StringEquals"
      variable = "aws:SourceArn"
      values   = ["arn:aws:cloudtrail:*:${var.aws_account_id}:trail/${var.name_prefix}-trail"]
    }
  }

  statement {
    sid    = "AWSCloudTrailWrite"
    effect = "Allow"

    principals {
      type        = "Service"
      identifiers = ["cloudtrail.amazonaws.com"]
    }

    actions   = ["s3:PutObject"]
    resources = ["${aws_s3_bucket.cloudtrail[0].arn}/AWSLogs/${var.aws_account_id}/*"]

    condition {
      test     = "StringEquals"
      variable = "s3:x-amz-acl"
      values   = ["bucket-owner-full-control"]
    }

    condition {
      test     = "StringEquals"
      variable = "aws:SourceArn"
      values   = ["arn:aws:cloudtrail:*:${var.aws_account_id}:trail/${var.name_prefix}-trail"]
    }
  }
}

resource "aws_s3_bucket_policy" "cloudtrail" {
  count = var.enable_cloudtrail ? 1 : 0

  bucket = aws_s3_bucket.cloudtrail[0].id
  policy = data.aws_iam_policy_document.cloudtrail_bucket[0].json
}

resource "aws_cloudtrail" "this" {
  count = var.enable_cloudtrail ? 1 : 0

  name                          = "${var.name_prefix}-trail"
  s3_bucket_name                = aws_s3_bucket.cloudtrail[0].id
  include_global_service_events = true
  is_multi_region_trail         = true
  enable_log_file_validation    = true
  kms_key_id                    = var.kms_key_arn

  tags = var.tags

  depends_on = [aws_s3_bucket_policy.cloudtrail]
}

# --- GuardDuty ----------------------------------------------------------

resource "aws_guardduty_detector" "this" {
  count = var.enable_guardduty ? 1 : 0

  enable = true

  tags = var.tags
}

# --- Security Hub ---------------------------------------------------------

resource "aws_securityhub_account" "this" {
  count = var.enable_security_hub ? 1 : 0
}

# --- IAM Access Analyzer ----------------------------------------------

resource "aws_accessanalyzer_analyzer" "this" {
  count = var.enable_iam_access_analyzer ? 1 : 0

  analyzer_name = "${var.name_prefix}-access-analyzer"
  type          = "ACCOUNT"

  tags = var.tags
}
