variable "name_prefix" {
  type = string
}

variable "vpc_id" {
  type = string
}

variable "private_subnet_ids" {
  type = list(string)
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

variable "auth_token" {
  description = "Redis AUTH token. Must be sourced from a Secrets Manager-backed value by the caller (e.g. `data.aws_secretsmanager_secret_version`) — never a literal in any .tf/.tfvars file. See docs/ecs/iam-matrix.md."
  type        = string
  sensitive   = true
}

variable "tags" {
  type    = map(string)
  default = {}
}
