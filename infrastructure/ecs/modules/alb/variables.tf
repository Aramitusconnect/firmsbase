variable "name_prefix" {
  type = string
}

variable "alb_name" {
  description = "Override for the ALB's exact name. Null (default) preserves this module's original name_prefix-generated pattern (\"<6-char-prefix>-<random>\") — the AWS-recommended way to avoid name collisions when Terraform creates a brand-new load balancer. name (like name_prefix) is ForceNew on aws_lb — an already-imported live ALB with a fixed, pre-existing name MUST have this set to the exact live value, or the very next apply plans a disruptive replacement of a load balancer actively serving traffic. See target_group_name below (also ForceNew, same rationale)."
  type        = string
  default     = null
}

variable "target_group_name" {
  description = "Override for the web target group's exact name. Null (default) preserves this module's original name_prefix-generated pattern. name (like name_prefix) is ForceNew on aws_lb_target_group — an already-imported live target group with a fixed, pre-existing name MUST have this set to the exact live value, or the very next apply plans a disruptive replacement of a target group actively registered with the live ALB and ECS service."
  type        = string
  default     = null
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

variable "health_check_matcher" {
  description = "HTTP status-code matcher accepted by the ALB target-group health check."
  type        = string
  default     = "200"

  validation {
    condition     = can(regex("^[0-9]{3}(-[0-9]{3})?(,[0-9]{3}(-[0-9]{3})?)*$", var.health_check_matcher))
    error_message = "health_check_matcher must be an ALB-compatible HTTP code or range such as 200 or 200-399."
  }
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

variable "alb_adoption_tags" {
  description = "Extra literal tags merged onto the ALB, meant for exactly one purpose: reproducing a pre-Terraform-adoption tag set an already-imported live ALB carries so it becomes part of this resource's real, managed tag set instead of being silently dropped by the next apply. Empty (default) for a brand-new environment. Never used for ordinary environment-wide tagging — use var.tags for that."
  type        = map(string)
  default     = {}
}

variable "target_group_adoption_tags" {
  description = "Extra literal tags merged onto the web target group — see alb_adoption_tags above for the identical rationale, applied to this sibling resource."
  type        = map(string)
  default     = {}
}

variable "https_listener_tags" {
  description = "Extra literal tags merged onto the HTTPS listener — see alb_adoption_tags above for the identical rationale, applied to this sibling resource."
  type        = map(string)
  default     = {}
}

variable "http_redirect_listener_tags" {
  description = "Extra literal tags merged onto the HTTP-redirect listener — see alb_adoption_tags above for the identical rationale, applied to this sibling resource."
  type        = map(string)
  default     = {}
}
