# Prepared per docs/ecs/storage-readiness.md target state. NOT used by any
# application code yet (see docs/ecs/ec2-dependency-audit.md §6) — this
# module exists so the eventual real document-storage feature has a bucket
# meeting the required security bar already defined, not so this branch can
# claim document storage is "done." Private, versioned, encrypted,
# public-access-blocked; access exclusively via signed URLs or the
# application (never a bucket policy granting public reads).

resource "aws_s3_bucket" "documents" {
  bucket = var.bucket_name

  tags = var.tags
}

resource "aws_s3_bucket_public_access_block" "documents" {
  bucket = aws_s3_bucket.documents.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_versioning" "documents" {
  bucket = aws_s3_bucket.documents.id

  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "documents" {
  bucket = aws_s3_bucket.documents.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm     = "aws:kms"
      kms_master_key_id = var.kms_key_arn
    }
    bucket_key_enabled = true
  }
}

resource "aws_s3_bucket_ownership_controls" "documents" {
  bucket = aws_s3_bucket.documents.id

  rule {
    object_ownership = "BucketOwnerEnforced" # disables ACLs entirely — access is IAM/bucket-policy only
  }
}

# Lifecycle policy (e.g. transition-to-Glacier / expiration for exports and
# backups) is deliberately NOT set here — retention windows are a
# product/compliance decision for the owning team (see
# app/Services/RetentionPolicyService.php and docs/ecs/storage-readiness.md),
# not something this infra branch should decide unilaterally.
