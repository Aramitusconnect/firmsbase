output "task_definition_arn" {
  value = aws_ecs_task_definition.this.arn
}

output "task_definition_family" {
  value = aws_ecs_task_definition.this.family
}

output "service_name" {
  value = var.create_service ? aws_ecs_service.this[0].name : null
}

output "assign_public_ip" {
  value = var.assign_public_ip
}

output "desired_count" {
  value = var.desired_count
}

output "use_capacity_provider_strategy" {
  value = var.use_capacity_provider_strategy
}
