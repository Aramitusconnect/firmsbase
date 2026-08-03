variable "aws_region" {
  type    = string
  default = "us-east-1"
}

variable "name_prefix" {
  type    = string
  default = "firmsbase-staging"
}

# --- Networking (existing VPC — see infrastructure/ecs/modules/networking) -
variable "vpc_id" {
  type = string
}

variable "public_subnet_ids" {
  type = list(string)
}

variable "private_subnet_ids" {
  type = list(string)
}

variable "alb_ingress_cidr_blocks" {
  description = "See infrastructure/ecs/modules/security_groups — restrict to a known range for staging."
  type        = list(string)
}

# --- DNS / TLS — human-supplied, this mission does not create either -------
variable "acm_certificate_arn" {
  description = "See infrastructure/ecs/modules/alb — must already be issued/validated."
  type        = string
}

# --- RDS — existing instance, not created by this mission ------------------
variable "rds_instance_id" {
  type = string
}

variable "rds_security_group_id" {
  type = string
}

variable "db_host" {
  type = string
}

variable "db_database" {
  type    = string
  default = "firmsbase_staging"
}

# --- Application image (supplied by CI/CD — see
# .github/workflows/ecs-pipeline.yml — never hand-typed for a real deploy) -
variable "app_image_digest" {
  description = "Full image reference including immutable digest: <ecr-repo-url>@sha256:<64 hex chars>. See docs/ecs/container-architecture.md."
  type        = string
}

# --- Secrets — ARNs only; values live in Secrets Manager, never in
# Terraform state or var files. See docs/ecs/iam-matrix.md and
# docs/ecs/env.ecs.example. ------------------------------------------------
variable "app_key_secret_arn" {
  type = string
}

variable "db_password_secret_arn" {
  type = string
}

variable "redis_auth_token_secret_arn" {
  type = string
}

variable "redis_auth_token" {
  description = "Same value as redis_auth_token_secret_arn resolves to, needed directly by the aws_elasticache_cluster resource itself (not just by ECS tasks at runtime). Pass via `-var` sourced from Secrets Manager at plan/apply time (e.g. a wrapper script doing `aws secretsmanager get-secret-value`), never committed to a .tfvars file. See infrastructure/ecs/modules/elasticache."
  type        = string
  sensitive   = true
}

# --- SNS topic for alarm notifications — who gets paged is an operational
# decision, not created by this mission. See docs/ecs/alarm-inventory.md. --
variable "alarm_sns_topic_arn" {
  type = string
}

variable "enable_custom_metric_alarms" {
  description = "See infrastructure/ecs/modules/cloudwatch_alarms — false until app-level CloudWatch metric emission exists."
  type        = bool
  default     = false
}

# --- SES bounce/complaint SQS consumer (ses-consumer role) — see
# docs/ecs/container-architecture.md and docs/ecs/iam-matrix.md. -----------
variable "ses_events_queue_url" {
  description = "The SES bounce/complaint SQS queue URL (SES_EVENTS_QUEUE_URL). Plain, non-secret environment — a queue URL is an identifier, not a credential."
  type        = string

  validation {
    condition     = length(var.ses_events_queue_url) > 0 && can(regex("^https://sqs\\.[a-z0-9-]+\\.amazonaws\\.com/[0-9]{12}/[a-zA-Z0-9_-]+$", var.ses_events_queue_url))
    error_message = "ses_events_queue_url must be a non-empty, structurally plausible SQS queue URL: https://sqs.<region>.amazonaws.com/<12-digit-account-id>/<queue-name>."
  }
}

variable "ses_events_queue_arn" {
  description = "ARN of the same SQS queue ses_events_queue_url points at. Passed to the iam module so the ses_consumer task role's policy references var.ses_events_queue_arn rather than a hardcoded ARN — see infrastructure/ecs/modules/iam/main.tf."
  type        = string

  validation {
    condition     = can(regex("^arn:aws:sqs:[a-z0-9-]+:[0-9]{12}:[a-zA-Z0-9_-]+$", var.ses_events_queue_arn))
    error_message = "ses_events_queue_arn must be a valid SQS ARN: arn:aws:sqs:<region>:<12-digit-account-id>:<queue-name>."
  }
}

variable "ses_events_dlq_arn" {
  description = "ARN of the SES bounce/complaint dead-letter queue — used ONLY for the DLQ-backlog CloudWatch alarm (infrastructure/ecs/modules/cloudwatch_alarms). Never passed to the iam module and never granted to any task role: SQS's own redrive policy delivers to the DLQ automatically, and ses-consumer has no need to read from, or delete from, it directly."
  type        = string

  validation {
    condition     = can(regex("^arn:aws:sqs:[a-z0-9-]+:[0-9]{12}:[a-zA-Z0-9_-]+$", var.ses_events_dlq_arn))
    error_message = "ses_events_dlq_arn must be a valid SQS ARN: arn:aws:sqs:<region>:<12-digit-account-id>:<queue-name>."
  }
}

variable "platform_notifications_recipient_fingerprint_hmac_key_secret_arn" {
  description = "Secrets Manager ARN of the dedicated PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY secret — a new, dedicated secret, never APP_KEY. Delivered as an ECS `secrets` entry (resolved by the task execution role at task start) to exactly the web and ses-consumer services; never worker/critical-worker/scheduler/migrate/maintenance. Marked sensitive here because Terraform has no narrower way to flag \"handle this value carefully\" — the ARN itself is an identifier, not the secret value, which never enters Terraform state, a tfvars file, or any output."
  type        = string
  sensitive   = true

  validation {
    condition     = can(regex("^arn:aws:secretsmanager:[a-z0-9-]+:[0-9]{12}:secret:.+$", var.platform_notifications_recipient_fingerprint_hmac_key_secret_arn))
    error_message = "platform_notifications_recipient_fingerprint_hmac_key_secret_arn must be a Secrets Manager ARN: arn:aws:secretsmanager:<region>:<12-digit-account-id>:secret:<name>."
  }
}

variable "ses_events_wait_time_seconds" {
  description = "SES_EVENTS_WAIT_TIME_SECONDS — SQS ReceiveMessage long-poll wait. SQS itself supports 0-20 seconds; see config('services.ses_events.wait_time_seconds')."
  type        = number
  default     = 20

  validation {
    condition     = var.ses_events_wait_time_seconds >= 0 && var.ses_events_wait_time_seconds <= 20
    error_message = "ses_events_wait_time_seconds must be within SQS's own supported range: 0 to 20 seconds."
  }
}

variable "ses_events_visibility_timeout_seconds" {
  description = "SES_EVENTS_VISIBILITY_TIMEOUT_SECONDS — how long a received-but-undeleted message stays invisible to other receivers. SQS's own ceiling is 12 hours (43200s)."
  type        = number
  default     = 60

  validation {
    condition     = var.ses_events_visibility_timeout_seconds > 0 && var.ses_events_visibility_timeout_seconds <= 43200
    error_message = "ses_events_visibility_timeout_seconds must be positive and at most 43200 (SQS's 12-hour maximum)."
  }
}

variable "ses_events_max_messages" {
  description = "SES_EVENTS_MAX_MESSAGES — ReceiveMessage's MaxNumberOfMessages. SQS itself caps this at 10 per call."
  type        = number
  default     = 10

  validation {
    condition     = var.ses_events_max_messages >= 1 && var.ses_events_max_messages <= 10
    error_message = "ses_events_max_messages must be within SQS's own supported range: 1 to 10."
  }
}

variable "ses_consumer_desired_count" {
  description = "ses-consumer ECS service desired task count. 1 by default (a single long-polling consumer is sufficient — SQS's own visibility timeout already prevents two receivers from processing the same message concurrently); 0 is a valid, deliberate way to stop the service (see docs/ecs/runbooks/rollback-runbook.md) without destroying it."
  type        = number
  default     = 1

  validation {
    condition     = var.ses_consumer_desired_count >= 0
    error_message = "ses_consumer_desired_count must be non-negative."
  }
}

variable "ses_consumer_cpu" {
  description = "ses-consumer task CPU units (Fargate). 256 (the smallest Fargate size) — an SQS long-poll loop plus a handful of DB writes per event is not CPU-intensive, matching the scheduler role's own sizing."
  type        = number
  default     = 256
}

variable "ses_consumer_memory" {
  description = "ses-consumer task memory in MiB (Fargate). 512 — matches the scheduler role's own sizing for the same reason (single lightweight PHP process, no request concurrency)."
  type        = number
  default     = 512
}

variable "ses_consumer_stop_timeout" {
  description = "ses-consumer ECS stopTimeout. The consumer checks its shutdown flag between messages (never mid-message — see docs/ecs/graceful-shutdown.md), so the realistic drain time is bounded by a single message's processing time, not a full batch; 30s (matching the scheduler role) leaves ample headroom without approaching ECS's 120s ceiling."
  type        = number
  default     = 30

  validation {
    condition     = var.ses_consumer_stop_timeout > 0 && var.ses_consumer_stop_timeout <= 120
    error_message = "ses_consumer_stop_timeout must be positive and at most 120 (ECS's own stopTimeout ceiling)."
  }
}
