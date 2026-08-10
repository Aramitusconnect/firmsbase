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

variable "canonical_hostnames" {
  description = <<-EOT
    Mission 1 (Domain & Security Boundary Architecture), section 40.
    Optional map of the six canonical FirmsVault hostnames this ALB
    should route by Host header — all to the SAME target group
    (aws_lb_target_group.web): "It is acceptable for all hostnames
    initially to point to the same ECS service/target group... Do NOT
    create separate ECS services simply because there are multiple
    hostnames."

    Real hostnames are an external DNS/ownership input this module does
    not invent or assign — see docs/ecs/env.ecs.example and
    docs/ecs/staging-readiness-report.md. Left null (the default) until
    real hostnames are provisioned; while null, no listener rules are
    created and the existing default forward action continues to serve
    every request exactly as it did before this mission, so adding this
    variable has zero effect on any currently-deployed environment.

    Expected keys (all six, once real values exist): marketing,
    firm_app, client_portal, admin, myattorney, api.
  EOT
  type        = map(string)
  default     = null
}
