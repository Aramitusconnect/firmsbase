output "web_acl_arn" {
  description = "ARN of the Web ACL, or null when var.enabled = false."
  value       = var.enabled ? aws_wafv2_web_acl.this[0].arn : null
}
