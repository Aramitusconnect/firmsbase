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
