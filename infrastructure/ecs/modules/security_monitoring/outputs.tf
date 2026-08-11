output "cloudtrail_arn" {
  description = "ARN of the CloudTrail trail, or null when disabled."
  value       = var.enable_cloudtrail ? aws_cloudtrail.this[0].arn : null
}

output "guardduty_detector_id" {
  description = "ID of the GuardDuty detector, or null when disabled."
  value       = var.enable_guardduty ? aws_guardduty_detector.this[0].id : null
}

output "security_hub_enabled" {
  description = "Whether Security Hub was enabled by this module."
  value       = var.enable_security_hub
}

output "access_analyzer_arn" {
  description = "ARN of the IAM Access Analyzer, or null when disabled."
  value       = var.enable_iam_access_analyzer ? aws_accessanalyzer_analyzer.this[0].arn : null
}
