output "alb_arn" {
  value = aws_lb.this.arn
}

output "alb_arn_suffix" {
  description = "Short form (e.g. app/<name>/<id>) required by CloudWatch AWS/ApplicationELB metric dimensions — see infrastructure/ecs/modules/cloudwatch_alarms."
  value       = aws_lb.this.arn_suffix
}

output "alb_dns_name" {
  value = aws_lb.this.dns_name
}

output "alb_zone_id" {
  value = aws_lb.this.zone_id
}

output "target_group_arn" {
  value = aws_lb_target_group.web.arn
}

output "target_group_arn_suffix" {
  description = "Short form required by CloudWatch AWS/ApplicationELB metric dimensions."
  value       = aws_lb_target_group.web.arn_suffix
}
