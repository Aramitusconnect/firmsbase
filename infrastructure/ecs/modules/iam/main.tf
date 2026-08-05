# Least-privilege IAM for FirmsBase ECS tasks. See docs/ecs/iam-matrix.md for
# the human-readable permission-by-permission rationale this module
# implements. No role here is granted AdministratorAccess, broad iam:*,
# unrestricted s3:*/secretsmanager:*/kms:*, or any ECS-infrastructure-
# modifying permission — every grant below is scoped to a specific ARN list
# supplied by the caller.

# Shared by both the execution role and every task role below — this
# staging environment's live firmsbase-staging-ecs-execution-role AND
# firmsbase-staging-ecs-task-role both carry the identical
# aws:SourceAccount/aws:SourceArn confused-deputy conditions (confirmed via
# aws iam get-role on both roles), so they are modeled here as one shared
# document rather than a role-specific one. var.aws_account_id/var.aws_region
# are explicit, required module inputs — deliberately not derived from the
# caller's ambient AWS identity inside this reusable module, so the trust
# policy this module renders never silently depends on which credentials
# happen to run `terraform apply`. See docs/ecs/state-adoption-plan.md §9.17.
data "aws_iam_policy_document" "ecs_tasks_assume_role" {
  statement {
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["ecs-tasks.amazonaws.com"]
    }

    condition {
      test     = "StringEquals"
      variable = "aws:SourceAccount"
      values   = [var.aws_account_id]
    }

    condition {
      test     = "ArnLike"
      variable = "aws:SourceArn"
      values   = ["arn:aws:ecs:${var.aws_region}:${var.aws_account_id}:*"]
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
  name               = coalesce(var.task_execution_role_name, "${var.name_prefix}-task-execution")
  assume_role_policy = data.aws_iam_policy_document.ecs_tasks_assume_role.json
  description        = var.task_execution_role_description
  tags               = var.tags
}

# ECR-pull and CloudWatch Logs delivery — the standard permissions every
# ECS execution role needs — are supplied by the separately attached
# AWS-managed policy below, not modeled again here. This attachment is
# deliberately a per-resource aws_iam_role_policy_attachment (non-exclusive:
# it only asserts this one attachment exists, never removes any other
# attachment Terraform doesn't know about) — never
# aws_iam_role_policy_attachments_exclusive and never managed_policy_arns
# directly on aws_iam_role, either of which could detach an unrelated
# attachment on a live role this module doesn't fully enumerate. See
# docs/ecs/state-adoption-plan.md §9.18.
resource "aws_iam_role_policy_attachment" "task_execution_managed" {
  role       = aws_iam_role.task_execution.name
  policy_arn = var.task_execution_managed_policy_arn
}

# Secrets-only by design — see task_execution_managed_policy_arn above for
# why ECR/logs are not modeled here. This module previously bundled
# EcrAuth/EcrPull/WriteLogs statements into this same inline policy,
# duplicating what the managed-policy attachment already grants, and
# included the platform-notifications HMAC-key secret instead of the
# actual 4th live secret (the migrator DB credential) — see
# docs/ecs/state-adoption-plan.md §9.18 for the live-verified correction.
data "aws_iam_policy_document" "task_execution" {
  statement {
    sid       = "ReadTaskSecrets"
    actions   = ["secretsmanager:GetSecretValue"]
    resources = var.task_execution_secret_arns
  }

  dynamic "statement" {
    for_each = length(var.task_execution_ssm_parameter_arns) > 0 ? [1] : []
    content {
      sid       = "ReadTaskParameters"
      actions   = ["ssm:GetParameters"]
      resources = var.task_execution_ssm_parameter_arns
    }
  }

  dynamic "statement" {
    for_each = var.task_execution_kms_decrypt_enabled ? [1] : []
    content {
      sid       = "DecryptSecretsAndParameters"
      actions   = ["kms:Decrypt"]
      resources = [var.kms_key_arn]
    }
  }
}

resource "aws_iam_role_policy" "task_execution" {
  name   = var.task_execution_policy_name
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
  # Static, configuration-known role-key set — every for_each in this file
  # that needs "one instance per task role" must derive its keys from this
  # local (or a subset of it), never from aws_iam_role.task itself. Deriving
  # for_each keys from a resource's own for_each map (e.g. `for_each =
  # aws_iam_role.task`) requires that resource's full instance set to be
  # known, which is not guaranteed during `terraform import` of an unrelated
  # resource in the same configuration — see docs/ecs/state-adoption-plan.md.
  task_role_names = ["web", "worker", "critical_worker", "scheduler", "migrate", "maintenance", "ses_consumer"]

  # Roles that read/write the (future) S3 document bucket. Scheduler and
  # migrate get no S3 grant at all — neither has any documented need for it
  # today (see docs/ecs/iam-matrix.md).
  s3_document_role_names = ["web", "worker", "critical_worker", "maintenance"]
}

resource "aws_iam_role" "task" {
  for_each = toset(local.task_role_names)

  name               = "${var.name_prefix}-task-${replace(each.value, "_", "-")}"
  assume_role_policy = data.aws_iam_policy_document.ecs_tasks_assume_role.json
  tags               = var.tags
}

data "aws_iam_policy_document" "task_s3_documents" {
  count = var.s3_documents_enabled ? 1 : 0

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
    for_each = var.kms_encryption_enabled ? [1] : []
    content {
      sid       = "DocumentBucketEncryption"
      actions   = ["kms:Decrypt", "kms:GenerateDataKey"]
      resources = [var.kms_key_arn]
    }
  }
}

resource "aws_iam_role_policy" "task_s3_documents" {
  for_each = var.s3_documents_enabled ? toset(local.s3_document_role_names) : toset([])

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
  # Deliberately NOT `for_each = aws_iam_role.task` — see local.task_role_names
  # above for why a resource's own for_each map is not a safe for_each source
  # here. aws_iam_role.task[each.key] below is a VALUE reference (its id),
  # which is fine to be unknown-until-apply; only the KEY SET must be static.
  for_each = toset(local.task_role_names)

  name   = "${var.name_prefix}-task-${each.key}-metrics"
  role   = aws_iam_role.task[each.key].id
  policy = data.aws_iam_policy_document.task_metrics.json
}

# ---------------------------------------------------------------------------
# ses_consumer task role — SQS receive/delete on the SES bounce/complaint
# queue ONLY. Deliberately narrower than every other grant pattern in this
# file: exactly two actions (sqs:ReceiveMessage, sqs:DeleteMessage — the
# only two SQS calls App\Console\Commands\ConsumeSesEventsCommand actually
# makes, confirmed by direct code inspection), on exactly one resource (the
# primary queue ARN, never the DLQ — SQS's own redrive policy moves a
# message there automatically; this consumer has no business reading or
# deleting from the DLQ directly), granted to no role other than
# ses_consumer. No sqs:GetQueueAttributes, sqs:ChangeMessageVisibility,
# sqs:SendMessage, sqs:PurgeQueue, sqs:SetQueueAttributes, sqs:GetQueueUrl,
# or sqs:* wildcard — none of these are called anywhere in
# ConsumeSesEventsCommand or SesEventConsumerService.
# ---------------------------------------------------------------------------
data "aws_iam_policy_document" "task_ses_consumer_sqs" {
  count = var.ses_events_queue_arn == null ? 0 : 1

  statement {
    sid       = "ReceiveAndDeleteSesEventQueueMessages"
    actions   = ["sqs:ReceiveMessage", "sqs:DeleteMessage"]
    resources = [var.ses_events_queue_arn]
  }
}

resource "aws_iam_role_policy" "task_ses_consumer_sqs" {
  count = var.ses_events_queue_arn == null ? 0 : 1

  name   = "${var.name_prefix}-task-ses-consumer-sqs"
  role   = aws_iam_role.task["ses_consumer"].id
  policy = data.aws_iam_policy_document.task_ses_consumer_sqs[0].json
}

# ---------------------------------------------------------------------------
# web task role — SES outbound mail sending. Confirmed by direct code
# inspection: Illuminate\Mail\Transport\SesTransport calls only
# ses:SendRawEmail (never ses:SendEmail — see
# vendor/laravel/framework/src/Illuminate/Mail/Transport/SesTransport.php),
# synchronously from the request path (no ShouldQueue notification/mailable
# exists anywhere in app/Notifications or app/Mail, so no worker/
# critical-worker role ever sends mail today). Scoped to exactly the one
# verified sending identity (never all identities, never ses:*), with a
# ses:FromAddress condition so the grant cannot be used to send as any
# address other than the one this environment's MAIL_FROM_ADDRESS actually
# uses. Deliberately NOT granted to ses_consumer (inbound bounce/complaint
# handling never sends mail — see docs/ecs/iam-matrix.md) or any other
# role. This was previously manual/out-of-band AWS configuration —
# representing it here in Terraform means a rebuilt web task role no
# longer silently loses the ability to send mail; see docs/ecs/
# iam-matrix.md for the full history of this gap and its resolution.
# ---------------------------------------------------------------------------
data "aws_iam_policy_document" "task_web_ses_send" {
  count = (var.ses_sending_identity_arn == null || var.ses_authorized_from_address == null) ? 0 : 1

  statement {
    sid       = "SendVerifiedIdentityMailAsAuthorizedFromAddressOnly"
    actions   = ["ses:SendRawEmail"]
    resources = [var.ses_sending_identity_arn]

    condition {
      test     = "StringEquals"
      variable = "ses:FromAddress"
      values   = [var.ses_authorized_from_address]
    }
  }
}

resource "aws_iam_role_policy" "task_web_ses_send" {
  count = (var.ses_sending_identity_arn == null || var.ses_authorized_from_address == null) ? 0 : 1

  name   = "${var.name_prefix}-task-web-ses-send"
  role   = aws_iam_role.task["web"].id
  policy = data.aws_iam_policy_document.task_web_ses_send[0].json
}
