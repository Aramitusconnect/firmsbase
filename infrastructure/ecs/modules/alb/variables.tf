variable "name_prefix" {
  type = string
}

variable "vpc_id" {
  type = string
}

variable "public_subnet_ids" {
  type = list(string)
}

variable "security_group_id" {
  type = string
}

variable "container_port" {
  type    = number
  default = 8080
}

variable "acm_certificate_arn" {
  description = <<-EOT
    ARN of an ACM certificate for the staging hostname, already issued and
    validated. This module does NOT request or validate a certificate — DNS
    validation would require a real Route 53 change, which this mission is
    explicitly forbidden from making (mission boundary: "Do not change
    production DNS," and staging DNS/cert issuance is itself a human
    decision requiring a real hostname — see
    docs/ecs/staging-readiness-report.md "required DNS/certificate inputs").
  EOT
  type        = string
}

variable "readiness_health_check_path" {
  description = "See app/Http/Controllers/ReadinessController.php and docs/ecs/container-architecture.md."
  type        = string
  default     = "/readyz"
}

variable "deregistration_delay_seconds" {
  type    = number
  default = 30
}

variable "health_check_interval_seconds" {
  type    = number
  default = 15
}

variable "health_check_timeout_seconds" {
  type    = number
  default = 5
}

variable "healthy_threshold_count" {
  type    = number
  default = 2
}

variable "unhealthy_threshold_count" {
  type    = number
  default = 3
}

variable "enable_deletion_protection" {
  description = "Should stay false for staging (mission does not provision production infra), left as a variable so a future production environment can override it."
  type        = bool
  default     = false
}

variable "access_logs_bucket" {
  description = "Optional S3 bucket for ALB access logs. Null disables access logging (still recommended — see docs/ecs/observability.md — but not created automatically here since it implies an additional bucket/lifecycle decision)."
  type        = string
  default     = null
}

variable "tags" {
  type    = map(string)
  default = {}
}
