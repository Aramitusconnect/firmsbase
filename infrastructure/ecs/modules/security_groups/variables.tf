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

variable "ecs_tasks_security_group_name" {
  description = "Override for the ECS-tasks security group's exact name. Null (default) preserves this module's original name_prefix-generated pattern (\"<name_prefix>-ecs-tasks-<random>\") — the AWS-recommended way to avoid name collisions when Terraform creates a brand-new security group. name (like name_prefix) is ForceNew on aws_security_group — an already-imported live security group with a fixed, pre-existing name (not the name_prefix pattern) MUST have this set to the exact live value, or the very next apply plans a disruptive replacement of a security group that is already in active use (referenced by the RDS ingress rule and the Redis ingress rule alike). See ecs_tasks_security_group_description below (also ForceNew, same rationale)."
  type        = string
  default     = null
}

variable "ecs_tasks_security_group_description" {
  description = "Override for the ECS-tasks security group's exact description. Defaults to this module's original description (\"FirmsBase ECS tasks (web/worker/scheduler/migrate/maintenance) — no direct internet ingress.\"), fine when Terraform creates a brand-new security group. description is ForceNew on aws_security_group (same as name/name_prefix above, and for the same underlying reason — the EC2 API has no in-place UpdateSecurityGroupDescription call) — an already-imported live security group whose description differs from this default MUST override it to the exact live value, or the very next apply plans a disruptive replacement."
  type        = string
  default     = "FirmsBase ECS tasks (web/worker/scheduler/migrate/maintenance) — no direct internet ingress."
}

variable "ecs_tasks_security_group_adoption_tags" {
  description = "Extra literal tags merged onto the ECS-tasks security group ahead of the Name tag, meant for exactly one purpose: reproducing a pre-Terraform-adoption tag an already-imported live security group carries (e.g. a legacy empty-value key an earlier, non-Terraform process set) so it becomes part of this resource's real, managed tag set instead of being masked by ignore_changes. Empty (default) for a brand-new environment. Never used for ordinary environment-wide tagging — use var.tags for that. Scoped to the ecs_tasks security group only — the sibling alb security group has its own, different live tags and is out of scope here."
  type        = map(string)
  default     = {}
}

variable "alb_security_group_name" {
  description = "Override for the ALB security group's exact name. Null (default) preserves this module's original name_prefix-generated pattern (\"<name_prefix>-alb-<random>\") — the AWS-recommended way to avoid name collisions when Terraform creates a brand-new security group. name (like name_prefix) is ForceNew on aws_security_group — an already-imported live security group with a fixed, pre-existing name (not the name_prefix pattern) MUST have this set to the exact live value, or the very next apply plans a disruptive replacement of a security group already in active use (referenced by the ecs_tasks_ingress_from_alb rule). See alb_security_group_description below (also ForceNew, same rationale)."
  type        = string
  default     = null
}

variable "alb_security_group_description" {
  description = "Override for the ALB security group's exact description. Defaults to this module's original description (\"FirmsBase ALB — public HTTPS ingress only, no direct application access.\"), fine when Terraform creates a brand-new security group. description is ForceNew on aws_security_group (same as name/name_prefix above, and for the same underlying reason — the EC2 API has no in-place UpdateSecurityGroupDescription call) — an already-imported live security group whose description differs from this default MUST override it to the exact live value, or the very next apply plans a disruptive replacement."
  type        = string
  default     = "FirmsBase ALB — public HTTPS ingress only, no direct application access."
}
