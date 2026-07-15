# Least-privilege IAM for FirmsBase ECS tasks. See docs/ecs/iam-matrix.md for
# the human-readable permission-by-permission rationale this module
# implements. No role here is granted AdministratorAccess, broad iam:*,
# unrestricted s3:*/secretsmanager:*/kms:*, or any ECS-infrastructure-
# modifying permission — every grant below is scoped to a specific ARN list
# supplied by the caller.

data "aws_iam_policy_document" "ecs_tasks_assume_role" {
  statement {
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["ecs-tasks.amazonaws.com"]
    }
  }
}

# ---------------------------------------------------------------------------
# Task EXECUTION role — one, shared by every task definition. This is what
# the ECS AGENT assumes to pull the image and start the container; it is
# NOT what application code inside the container assumes (that's the task
# role, below). Scope: pull from exactly this app's ECR repo, write to
# exactly this app's log groups, read exactly the secrets this app's task
# definitions reference.
# ---------------------------------------------------------------------------
resource "aws_iam_role" "task_execution" {
  name               = "${var.name_prefix}-task-execution"
  assume_role_policy = data.aws_iam_policy_document.ecs_tasks_assume_role.json
  tags               = var.tags
}

data "aws_iam_policy_document" "task_execution" {
  statement {
    sid       = "EcrAuth"
    actions   = ["ecr:GetAuthorizationToken"]
    resources = ["*"] # GetAuthorizationToken does not support resource-level scoping — this is the one AWS-mandated exception, not a shortcut.
  }

  statement {
    sid = "EcrPull"
    actions = [
      "ecr:BatchCheckLayerAvailability",
      "ecr:GetDownloadUrlForLayer",
      "ecr:BatchGetImage",
    ]
    resources = [var.ecr_repository_arn]
  }

  statement {
    sid = "WriteLogs"
    actions = [
      "logs:CreateLogStream",
      "logs:PutLogEvents",
    ]
    resources = var.log_group_arns
  }

  dynamic "statement" {
    for_each = length(var.secret_arns) > 0 ? [1] : []
    content {
      sid       = "ReadTaskSecrets"
      actions   = ["secretsmanager:GetSecretValue"]
      resources = var.secret_arns
    }
  }

  dynamic "statement" {
    for_each = length(var.ssm_parameter_arns) > 0 ? [1] : []
    content {
      sid       = "ReadTaskParameters"
      actions   = ["ssm:GetParameters"]
      resources = var.ssm_parameter_arns
    }
  }

  dynamic "statement" {
    for_each = var.kms_key_arn == null ? [] : [1]
    content {
      sid       = "DecryptSecretsAndParameters"
      actions   = ["kms:Decrypt"]
      resources = [var.kms_key_arn]
    }
  }
}

resource "aws_iam_role_policy" "task_execution" {
  name   = "${var.name_prefix}-task-execution"
  role   = aws_iam_role.task_execution.id
  policy = data.aws_iam_policy_document.task_execution.json
}

# ---------------------------------------------------------------------------
# Application task roles — assumed by the AWS SDK INSIDE the container.
# One per ECS role, each scoped to only what that role concretely needs
# today. All are empty of S3/KMS grants when the corresponding ARN variable
# is null (i.e. before the S3 bucket/KMS key exist — see
# docs/ecs/storage-readiness.md) rather than defaulting to a wildcard.
# ---------------------------------------------------------------------------

locals {
  # Roles that read/write the (future) S3 document bucket. Scheduler and
  # migrate get no S3 grant at all — neither has any documented need for it
  # today (see docs/ecs/iam-matrix.md).
  s3_document_role_names = ["web", "worker", "critical_worker", "maintenance"]
}

resource "aws_iam_role" "task" {
  for_each = toset(["web", "worker", "critical_worker", "scheduler", "migrate", "maintenance"])

  name               = "${var.name_prefix}-task-${replace(each.value, "_", "-")}"
  assume_role_policy = data.aws_iam_policy_document.ecs_tasks_assume_role.json
  tags               = var.tags
}

data "aws_iam_policy_document" "task_s3_documents" {
  count = var.s3_documents_bucket_arn == null ? 0 : 1

  statement {
    sid = "DocumentBucketObjectAccess"
    actions = [
      "s3:GetObject",
      "s3:PutObject",
      "s3:DeleteObject",
    ]
    resources = ["${var.s3_documents_bucket_arn}/*"]
  }

  statement {
    sid       = "DocumentBucketList"
    actions   = ["s3:ListBucket"]
    resources = [var.s3_documents_bucket_arn]
  }

  dynamic "statement" {
    for_each = var.kms_key_arn == null ? [] : [1]
    content {
      sid       = "DocumentBucketEncryption"
      actions   = ["kms:Decrypt", "kms:GenerateDataKey"]
      resources = [var.kms_key_arn]
    }
  }
}

resource "aws_iam_role_policy" "task_s3_documents" {
  for_each = var.s3_documents_bucket_arn == null ? toset([]) : toset(local.s3_document_role_names)

  name   = "${var.name_prefix}-task-${replace(each.value, "_", "-")}-s3-documents"
  role   = aws_iam_role.task[each.value].id
  policy = data.aws_iam_policy_document.task_s3_documents[0].json
}

# CloudWatch custom-metric emission (optional, low-privilege) — every task
# role gets this, since app-level metrics (see docs/ecs/observability.md)
# are cheap to allow and useful regardless of role. Explicitly namespaced,
# not cloudwatch:* / cloudwatch:PutMetricData without a namespace condition.
data "aws_iam_policy_document" "task_metrics" {
  statement {
    sid       = "PutFirmsBaseMetrics"
    actions   = ["cloudwatch:PutMetricData"]
    resources = ["*"] # PutMetricData does not support resource-level scoping; scoped instead by the namespace condition below.

    condition {
      test     = "StringEquals"
      variable = "cloudwatch:namespace"
      values   = ["FirmsBase"]
    }
  }
}

resource "aws_iam_role_policy" "task_metrics" {
  for_each = aws_iam_role.task

  name   = "${var.name_prefix}-task-${each.key}-metrics"
  role   = each.value.id
  policy = data.aws_iam_policy_document.task_metrics.json
}
