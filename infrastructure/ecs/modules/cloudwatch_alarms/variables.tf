variable "name_prefix" {
  type = string
}

variable "sns_topic_arn" {
  description = "Where alarms notify. This module does not create the topic/subscription (who gets paged is an operational/on-call decision — see docs/ecs/alarm-inventory.md) — pass an existing topic ARN."
  type        = string
}

variable "alb_arn_suffix" {
  type = string
}

variable "target_group_arn_suffix" {
  type = string
}

variable "ecs_cluster_name" {
  type = string
}

variable "web_service_name" {
  type = string
}

variable "general_worker_service_name" {
  type = string
}

variable "critical_worker_service_name" {
  type = string
}

variable "ses_consumer_service_name" {
  description = "ECS service name for the ses-consumer role. Value only — see ses_consumer_enabled for whether ses-consumer is included in the running-count/CPU alarms and error-log alarm below; an unknown-until-apply service name (e.g. before the ses-consumer ECS service is created/imported) cannot be compared to null to decide a for_each/count instance set."
  type        = string
  default     = null
}

variable "ses_consumer_enabled" {
  description = "Whether to include ses-consumer in the per-service alarms (running-count, CPU) and the log-based consumer-errors alarm. Must be a literal true/false set explicitly by every caller — never derived from whether ses_consumer_service_name/ses_consumer_log_group_name is null, since those values can be unknown during import/plan for a not-yet-created service. No default, deliberately: a default of false would let an existing caller that already passes ses_consumer_service_name/ses_consumer_log_group_name silently lose those alarms by simply omitting this variable during an upgrade, rather than failing loudly at plan/validate time."
  type        = bool
}

variable "ses_events_queue_name" {
  description = "SQS queue NAME (not URL/ARN — AWS/SQS CloudWatch metrics dimension on QueueName) of the SES bounce/complaint queue. Null omits the backlog/oldest-message-age alarms below."
  type        = string
  default     = null
}

variable "ses_events_dlq_name" {
  description = "SQS queue NAME of the SES bounce/complaint dead-letter queue. Used ONLY to alarm on messages arriving there — never to grant any IAM permission (the consumer has none on the DLQ, and this module grants no permissions at all). Null omits the DLQ alarm below."
  type        = string
  default     = null
}

variable "ses_consumer_log_group_name" {
  description = "CloudWatch log group the ses-consumer service writes to. Value only — see ses_consumer_enabled for whether the log-based 'consumer errors' metric filter/alarm below is included."
  type        = string
  default     = null
}

variable "rds_instance_id" {
  description = "Existing RDS instance identifier (see docs/ecs/infrastructure-architecture.md — this mission does not create the RDS instance itself)."
  type        = string
}

variable "redis_cluster_id" {
  type = string
}

variable "enable_custom_metric_alarms" {
  description = <<-EOT
    Alarms on the FirmsBase custom CloudWatch namespace (queue depth,
    oldest-pending-job age, failed jobs, webhook failures, payment
    failures, backup failures, security events — see
    docs/ecs/observability.md and docs/ecs/alarm-inventory.md). These
    metrics are NOT emitted by any application code today — QueueHealthService
    and HealthCheckService (app/Services/) compute the underlying numbers
    but only persist them to Postgres, they never call
    CloudWatch PutMetricData. Defaults to false so `terraform plan` doesn't
    show alarms in permanent INSUFFICIENT_DATA/ALARM state for a metric
    that will never arrive until that emission code is written — a
    "requires code change" item tracked in
    docs/ecs/staging-readiness-report.md, out of this mission's boundary to
    add speculatively (it would mean writing new production monitoring
    code, not infra).
  EOT
  type        = bool
  default     = false
}

variable "tags" {
  type    = map(string)
  default = {}
}
