output "task_execution_role_arn" {
  value = aws_iam_role.task_execution.arn
}

output "task_execution_role_name" {
  value = aws_iam_role.task_execution.name
}

output "task_role_arns" {
  description = "Map of role name (web/worker/critical_worker/scheduler/migrate/maintenance) to task role ARN."
  value       = { for k, v in aws_iam_role.task : k => v.arn }
}
