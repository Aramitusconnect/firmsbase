variable "name_prefix" {
  type = string
}

variable "vpc_id" {
  type = string
}

variable "subnet_ids" {
  description = "Subnet IDs registered in the ElastiCache subnet group. No default, deliberately — this module previously reused the caller's ECS private_subnet_ids unconditionally, but ElastiCache subnet-group membership is a genuinely different, broader concern than ECS task placement (this staging environment's live subnet group registers 6 subnets across every AZ in the VPC, while ECS tasks place into only 2 of them) — conflating the two silently under-registered the live subnet group's actual membership. Every caller must supply this explicitly."
  type        = list(string)
}

variable "ecs_tasks_security_group_id" {
  description = "Only source allowed to reach Redis — see docs/ecs/queue-and-redis-architecture.md."
  type        = string
}

variable "node_type" {
  description = "Small, single-node default appropriate for staging (cache/session/queue data, not durable business data — see docs/ecs/storage-readiness.md classification). Production sizing/HA (replication group, Multi-AZ) is a separate, explicitly human-approved decision — see docs/ecs/staging-readiness-report.md."
  type        = string
  default     = "cache.t4g.micro"
}

variable "engine_version" {
  type    = string
  default = "7.1"
}

variable "engine" {
  description = "\"redis\" or \"valkey\". Cannot be changed in place post-creation — see caller's elasticache_engine variable and docs/ecs/state-adoption-plan.md §3B/§9."
  type        = string
  default     = "redis"

  validation {
    condition     = contains(["redis", "valkey"], var.engine)
    error_message = "engine must be \"redis\" or \"valkey\"."
  }
}

variable "parameter_group_name" {
  description = "Must be a parameter-group family matching var.engine (e.g. default.redis7 for redis, default.valkey7 for valkey)."
  type        = string
  default     = "default.redis7"
}

variable "subnet_group_name" {
  description = "Null (default) falls back to \"<name_prefix>-redis\" (this module's original computation)."
  type        = string
  default     = null
}

variable "auth_token" {
  description = "Redis AUTH token. Must be sourced from a Secrets Manager-backed value by the caller (e.g. `data.aws_secretsmanager_secret_version`) — never a literal in any .tf/.tfvars file. See docs/ecs/iam-matrix.md."
  type        = string
  sensitive   = true
}

variable "tags" {
  type    = map(string)
  default = {}
}

variable "security_group_name" {
  description = "Override for the Redis security group's exact name. Null (default) preserves this module's original name_prefix-generated pattern (\"<name_prefix>-redis-<random>\") — the AWS-recommended way to avoid name collisions when Terraform creates a brand-new security group. name (like name_prefix) is ForceNew on aws_security_group — an already-imported live security group with a fixed, pre-existing name (not the name_prefix pattern) MUST have this set to the exact live value, or the very next apply plans a disruptive replacement of a security group that may already be in active use. See security_group_description below (also ForceNew, same rationale)."
  type        = string
  default     = null
}

variable "security_group_description" {
  description = "Override for the Redis security group's exact description. Defaults to this module's original description (\"FirmsBase ElastiCache Redis — ingress from ECS tasks only.\"), fine when Terraform creates a brand-new security group. description is ForceNew on aws_security_group (same as name/name_prefix above, and for the same underlying reason — the EC2 API has no in-place UpdateSecurityGroupDescription call) — an already-imported live security group whose description differs from this default MUST override it to the exact live value, or the very next apply plans a disruptive replacement."
  type        = string
  default     = "FirmsBase ElastiCache Redis — ingress from ECS tasks only."
}

variable "subnet_group_description" {
  description = "Override for the ElastiCache subnet group's description. Null (default) leaves the argument unset, which the AWS provider's own schema then defaults to \"Managed by Terraform\" — fine for a brand-new environment. description IS safely updatable in place (never ForceNew) on aws_elasticache_subnet_group, but an already-imported live subnet group with a real, human-written description should have this set to the exact live value so a routine plan does not propose overwriting it with the generic placeholder."
  type        = string
  default     = null
}

variable "replication_group_description" {
  description = "Override for the replication group's description. Defaults to this module's original description, fine for a brand-new environment. description IS safely updatable in place (never ForceNew) on aws_elasticache_replication_group, but an already-imported live replication group with a real, human-written description should have this set to the exact live value so a routine plan does not propose overwriting it."
  type        = string
  default     = "FirmsBase staging Redis — cache/session/queue/locks (see docs/ecs/queue-and-redis-architecture.md)"
}

variable "snapshot_retention_limit" {
  description = "Number of days to retain automatic snapshots. Defaults to 0 (snapshots disabled) — appropriate for a brand-new staging environment per this module's own \"no durable business data in Redis\" design (see file header). An already-imported live replication group that already has automatic backups enabled at a specific retention should have this set to the exact live value, or a routine plan proposes silently disabling backups (a real, live ElastiCache mutation, not cosmetic)."
  type        = number
  default     = 0
}
