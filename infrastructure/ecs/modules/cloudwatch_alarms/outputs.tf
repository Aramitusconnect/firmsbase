output "alarm_names" {
  value = concat(
    [
      aws_cloudwatch_metric_alarm.alb_5xx.alarm_name,
      aws_cloudwatch_metric_alarm.alb_target_response_time.alarm_name,
      aws_cloudwatch_metric_alarm.target_unhealthy.alarm_name,
      aws_cloudwatch_metric_alarm.rds_cpu.alarm_name,
      aws_cloudwatch_metric_alarm.rds_storage_low.alarm_name,
      aws_cloudwatch_metric_alarm.rds_connections_high.alarm_name,
      aws_cloudwatch_metric_alarm.redis_memory_high.alarm_name,
      aws_cloudwatch_metric_alarm.redis_connections_high.alarm_name,
    ],
    [for a in aws_cloudwatch_metric_alarm.ecs_service_running_count : a.alarm_name],
    [for a in aws_cloudwatch_metric_alarm.ecs_service_cpu_high : a.alarm_name],
  )
}
