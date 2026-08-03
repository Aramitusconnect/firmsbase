# See docs/ecs/alarm-inventory.md for the full rationale/threshold
# reasoning behind every alarm in this file — kept in sync deliberately;
# update both together.

resource "aws_cloudwatch_metric_alarm" "alb_5xx" {
  alarm_name          = "${var.name_prefix}-alb-5xx"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  period              = 60
  namespace           = "AWS/ApplicationELB"
  metric_name         = "HTTPCode_Target_5XX_Count"
  statistic           = "Sum"
  threshold           = 10
  treat_missing_data  = "notBreaching"
  alarm_description   = "More than 10 upstream 5xx responses/minute for 3 consecutive minutes."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  dimensions = {
    LoadBalancer = var.alb_arn_suffix
  }

  tags = var.tags
}

resource "aws_cloudwatch_metric_alarm" "alb_target_response_time" {
  alarm_name          = "${var.name_prefix}-alb-latency-p90"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  period              = 60
  extended_statistic  = "p90"
  namespace           = "AWS/ApplicationELB"
  metric_name         = "TargetResponseTime"
  threshold           = 2 # seconds
  treat_missing_data  = "notBreaching"
  alarm_description   = "p90 target response time above 2s for 3 consecutive minutes."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  dimensions = {
    LoadBalancer = var.alb_arn_suffix
  }

  tags = var.tags
}

resource "aws_cloudwatch_metric_alarm" "target_unhealthy" {
  alarm_name          = "${var.name_prefix}-target-unhealthy"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 2
  period              = 60
  namespace           = "AWS/ApplicationELB"
  metric_name         = "UnHealthyHostCount"
  statistic           = "Maximum"
  threshold           = 0
  treat_missing_data  = "notBreaching"
  alarm_description   = "At least one web task is failing the /readyz target-group health check."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  dimensions = {
    LoadBalancer = var.alb_arn_suffix
    TargetGroup  = var.target_group_arn_suffix
  }

  tags = var.tags
}

resource "aws_cloudwatch_metric_alarm" "ecs_service_running_count" {
  for_each = merge(
    {
      web             = var.web_service_name
      general_worker  = var.general_worker_service_name
      critical_worker = var.critical_worker_service_name
    },
    var.ses_consumer_service_name == null ? {} : { ses_consumer = var.ses_consumer_service_name }
  )

  alarm_name          = "${var.name_prefix}-${each.key}-running-count-low"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = 3
  period              = 60
  namespace           = "ECS/ContainerInsights"
  metric_name         = "RunningTaskCount"
  statistic           = "Average"
  threshold           = 1
  treat_missing_data  = "breaching" # no data for RunningTaskCount is itself a signal something is wrong
  alarm_description   = "Fewer than 1 running task for ${each.key} for 3 consecutive minutes — possible crash loop or deployment failure."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  dimensions = {
    ClusterName = var.ecs_cluster_name
    ServiceName = each.value
  }

  tags = var.tags
}

resource "aws_cloudwatch_metric_alarm" "ecs_service_cpu_high" {
  for_each = merge(
    {
      web             = var.web_service_name
      general_worker  = var.general_worker_service_name
      critical_worker = var.critical_worker_service_name
    },
    var.ses_consumer_service_name == null ? {} : { ses_consumer = var.ses_consumer_service_name }
  )

  alarm_name          = "${var.name_prefix}-${each.key}-cpu-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 5
  period              = 60
  namespace           = "AWS/ECS"
  metric_name         = "CPUUtilization"
  statistic           = "Average"
  threshold           = 85
  treat_missing_data  = "notBreaching"
  alarm_description   = "Sustained high CPU for ${each.key} — approaching task CPU limit."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  dimensions = {
    ClusterName = var.ecs_cluster_name
    ServiceName = each.value
  }

  tags = var.tags
}

resource "aws_cloudwatch_metric_alarm" "rds_cpu" {
  alarm_name          = "${var.name_prefix}-rds-cpu-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 5
  period              = 60
  namespace           = "AWS/RDS"
  metric_name         = "CPUUtilization"
  statistic           = "Average"
  threshold           = 80
  treat_missing_data  = "notBreaching"
  alarm_description   = "RDS CPU sustained above 80% for 5 minutes."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  dimensions = {
    DBInstanceIdentifier = var.rds_instance_id
  }

  tags = var.tags
}

resource "aws_cloudwatch_metric_alarm" "rds_storage_low" {
  alarm_name          = "${var.name_prefix}-rds-storage-low"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = 1
  period              = 300
  namespace           = "AWS/RDS"
  metric_name         = "FreeStorageSpace"
  statistic           = "Minimum"
  threshold           = 5368709120 # 5 GiB in bytes
  treat_missing_data  = "notBreaching"
  alarm_description   = "RDS free storage below 5 GiB."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  dimensions = {
    DBInstanceIdentifier = var.rds_instance_id
  }

  tags = var.tags
}

resource "aws_cloudwatch_metric_alarm" "rds_connections_high" {
  alarm_name          = "${var.name_prefix}-rds-connections-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  period              = 60
  namespace           = "AWS/RDS"
  metric_name         = "DatabaseConnections"
  statistic           = "Average"
  threshold           = 80 # tune against the instance class's max_connections — see docs/ecs/infrastructure-architecture.md
  treat_missing_data  = "notBreaching"
  alarm_description   = "RDS connection count approaching saturation."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  dimensions = {
    DBInstanceIdentifier = var.rds_instance_id
  }

  tags = var.tags
}

resource "aws_cloudwatch_metric_alarm" "redis_memory_high" {
  alarm_name          = "${var.name_prefix}-redis-memory-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  period              = 60
  namespace           = "AWS/ElastiCache"
  metric_name         = "DatabaseMemoryUsagePercentage"
  statistic           = "Average"
  threshold           = 80
  treat_missing_data  = "notBreaching"
  alarm_description   = "Redis memory usage above 80%."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  dimensions = {
    CacheClusterId = var.redis_cluster_id
  }

  tags = var.tags
}

resource "aws_cloudwatch_metric_alarm" "redis_connections_high" {
  alarm_name          = "${var.name_prefix}-redis-connections-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  period              = 60
  namespace           = "AWS/ElastiCache"
  metric_name         = "CurrConnections"
  statistic           = "Average"
  threshold           = 500 # tune against node type — see docs/ecs/infrastructure-architecture.md
  treat_missing_data  = "notBreaching"
  alarm_description   = "Redis connection count unusually high."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  dimensions = {
    CacheClusterId = var.redis_cluster_id
  }

  tags = var.tags
}

# ---------------------------------------------------------------------------
# Custom-metric alarms (FirmsBase namespace) — see enable_custom_metric_alarms
# doc comment in variables.tf. Off by default; the metric names below are
# the target contract for the app-level emission code this depends on, not
# yet implemented.
# ---------------------------------------------------------------------------

resource "aws_cloudwatch_metric_alarm" "queue_depth_high" {
  count = var.enable_custom_metric_alarms ? 1 : 0

  alarm_name          = "${var.name_prefix}-queue-depth-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 5
  period              = 60
  namespace           = "FirmsBase"
  metric_name         = "QueuePendingJobs"
  statistic           = "Average"
  threshold           = 500 # matches QueueHealthService::isHealthy()'s default maxPendingCount — app/Services/QueueHealthService.php
  treat_missing_data  = "notBreaching"
  alarm_description   = "Pending queue job count above 500 for 5 minutes."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  tags = var.tags
}

resource "aws_cloudwatch_metric_alarm" "oldest_queued_job_age_high" {
  count = var.enable_custom_metric_alarms ? 1 : 0

  alarm_name          = "${var.name_prefix}-oldest-queued-job-age-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  period              = 60
  namespace           = "FirmsBase"
  metric_name         = "OldestPendingJobAgeSeconds"
  statistic           = "Maximum"
  threshold           = 900 # matches QueueHealthService::isHealthy()'s default maxOldestPendingAgeSeconds
  treat_missing_data  = "notBreaching"
  alarm_description   = "Oldest pending queue job older than 15 minutes."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  tags = var.tags
}

resource "aws_cloudwatch_metric_alarm" "failed_jobs_high" {
  count = var.enable_custom_metric_alarms ? 1 : 0

  alarm_name          = "${var.name_prefix}-failed-jobs-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  period              = 60
  namespace           = "FirmsBase"
  metric_name         = "FailedJobsCount"
  statistic           = "Maximum"
  threshold           = 50 # matches QueueHealthService::isHealthy()'s default maxFailedCount
  treat_missing_data  = "notBreaching"
  alarm_description   = "Failed job count above 50."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  tags = var.tags
}

resource "aws_cloudwatch_metric_alarm" "scheduler_heartbeat_missing" {
  count = var.enable_custom_metric_alarms ? 1 : 0

  alarm_name          = "${var.name_prefix}-scheduler-heartbeat-missing"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = 1
  period              = 300
  namespace           = "FirmsBase"
  metric_name         = "SchedulerHeartbeatAgeSeconds"
  statistic           = "Maximum"
  threshold           = 300 # matches SchedulerHealthService::isHealthy()'s default maxAgeSeconds — app/Services/SchedulerHealthService.php
  treat_missing_data  = "breaching"
  alarm_description   = "No scheduler heartbeat recorded recently — scheduler task may be down."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  tags = var.tags
}

# ---------------------------------------------------------------------------
# SES bounce/complaint queue observability — unlike the FirmsBase-namespace
# alarms above, these use AWS/SQS metrics AWS emits automatically the
# moment the queue exists (see docs/ecs/observability.md "Metrics
# (AWS-native, available today without any code change)"), so they are NOT
# gated behind enable_custom_metric_alarms — only behind the queue/DLQ
# name actually being supplied.
# ---------------------------------------------------------------------------

resource "aws_cloudwatch_metric_alarm" "ses_events_queue_backlog_high" {
  count = var.ses_events_queue_name == null ? 0 : 1

  alarm_name          = "${var.name_prefix}-ses-events-queue-backlog-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  period              = 300
  namespace           = "AWS/SQS"
  metric_name         = "ApproximateNumberOfMessagesVisible"
  statistic           = "Average"
  threshold           = 100
  treat_missing_data  = "notBreaching"
  alarm_description   = "SES bounce/complaint queue backlog above 100 visible messages for 15 minutes — the ses-consumer service may be down or falling behind."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  dimensions = {
    QueueName = var.ses_events_queue_name
  }

  tags = var.tags
}

resource "aws_cloudwatch_metric_alarm" "ses_events_oldest_message_age_high" {
  count = var.ses_events_queue_name == null ? 0 : 1

  alarm_name          = "${var.name_prefix}-ses-events-oldest-message-age-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  period              = 300
  namespace           = "AWS/SQS"
  metric_name         = "ApproximateAgeOfOldestMessage"
  statistic           = "Maximum"
  threshold           = 1800 # 30 minutes
  treat_missing_data  = "notBreaching"
  alarm_description   = "Oldest visible message in the SES bounce/complaint queue older than 30 minutes — events are not being consumed in a timely manner."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  dimensions = {
    QueueName = var.ses_events_queue_name
  }

  tags = var.tags
}

resource "aws_cloudwatch_metric_alarm" "ses_events_dlq_messages_present" {
  count = var.ses_events_dlq_name == null ? 0 : 1

  alarm_name          = "${var.name_prefix}-ses-events-dlq-messages-present"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 1
  period              = 300
  namespace           = "AWS/SQS"
  metric_name         = "ApproximateNumberOfMessagesVisible"
  statistic           = "Maximum"
  threshold           = 0
  treat_missing_data  = "notBreaching"
  alarm_description   = "At least one message has landed in the SES bounce/complaint dead-letter queue — SQS's own redrive policy moved it there after repeated failed processing attempts. This module grants ses-consumer no permission on the DLQ; investigate and reprocess manually."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  dimensions = {
    QueueName = var.ses_events_dlq_name
  }

  tags = var.tags
}

# Log-based "consumer errors" detection — a CloudWatch Logs metric filter
# over the ses-consumer service's own dedicated log group, matching the
# exact Log::error()/Log::warning() event names
# App\Services\SesEventConsumerService and
# App\Console\Commands\ConsumeSesEventsCommand already emit today (no new
# application code required — see docs/ecs/observability.md). Deliberately
# a simple substring/term filter, not a JSON field match: LOG_STDERR_FORMATTER
# is unset in this environment, so log lines are plain Monolog text, and a
# literal event-name match works identically either way.
resource "aws_cloudwatch_log_metric_filter" "ses_consumer_errors" {
  count = var.ses_consumer_log_group_name == null ? 0 : 1

  name           = "${var.name_prefix}-ses-consumer-errors"
  log_group_name = var.ses_consumer_log_group_name
  pattern        = "?ses_event_processing_exception ?ses_event_malformed_json ?ses_event_malformed_sns_wrapped_message ?ses_event_invalid_structure ?ses_event_recipient_mismatch ?ses_event_firm_not_found ?ses_event_platform_recipient_mismatch"

  metric_transformation {
    name          = "SesConsumerErrorCount"
    namespace     = "FirmsBase/SesConsumer"
    value         = "1"
    default_value = "0"
  }
}

resource "aws_cloudwatch_metric_alarm" "ses_consumer_errors_high" {
  count = var.ses_consumer_log_group_name == null ? 0 : 1

  alarm_name          = "${var.name_prefix}-ses-consumer-errors-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 1
  period              = 300
  namespace           = "FirmsBase/SesConsumer"
  metric_name         = "SesConsumerErrorCount"
  statistic           = "Sum"
  threshold           = 10
  treat_missing_data  = "notBreaching"
  alarm_description   = "More than 10 SES event processing errors (malformed payload, unresolved correlation, recipient mismatch, etc.) logged in 5 minutes."
  alarm_actions       = [var.sns_topic_arn]
  ok_actions          = [var.sns_topic_arn]

  tags = var.tags

  depends_on = [aws_cloudwatch_log_metric_filter.ses_consumer_errors]
}
