variable "name_prefix" {
  type = string
}

variable "vpc_id" {
  type = string
}

variable "container_port" {
  description = "Port the FrankenPHP web process listens on inside the container (see docker/web/Caddyfile)."
  type        = number
  default     = 8080
}

variable "alb_ingress_cidr_blocks" {
  description = "CIDRs allowed to reach the ALB on 443. Restrict to a known range (VPN/office/corporate egress) for staging — this mission does not open staging to 0.0.0.0/0 by default. Override explicitly if public staging access is genuinely wanted (a human decision, not a default)."
  type        = list(string)
}

variable "existing_rds_security_group_id" {
  description = "Security group ID already attached to the target RDS instance. This module adds an ingress rule to it (ECS tasks -> 5432) rather than creating a new RDS security group, since the RDS instance itself is assumed pre-existing (see docs/ecs/infrastructure-architecture.md). Set to null to skip (e.g. RDS access is already open via a different mechanism, or not yet decided)."
  type        = string
  default     = null
}

variable "tags" {
  type    = map(string)
  default = {}
}
