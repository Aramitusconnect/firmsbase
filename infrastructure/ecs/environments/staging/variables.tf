variable "aws_region" {
  type    = string
  default = "us-east-1"
}

variable "name_prefix" {
  type    = string
  default = "firmsbase-staging"
}

# --- Networking (existing VPC — see infrastructure/ecs/modules/networking) -
variable "vpc_id" {
  type = string
}

variable "public_subnet_ids" {
  type = list(string)
}

variable "private_subnet_ids" {
  type = list(string)
}

variable "alb_ingress_cidr_blocks" {
  description = "See infrastructure/ecs/modules/security_groups — restrict to a known range for staging."
  type        = list(string)
}

# --- DNS / TLS — human-supplied, this mission does not create either -------
variable "acm_certificate_arn" {
  description = "See infrastructure/ecs/modules/alb — must already be issued/validated."
  type        = string
}

# --- RDS — existing instance, not created by this mission ------------------
variable "rds_instance_id" {
  type = string
}

variable "rds_security_group_id" {
  type = string
}

variable "db_host" {
  type = string
}

variable "db_database" {
  type    = string
  default = "firmsbase_staging"
}

# --- Application image (supplied by CI/CD — see
# .github/workflows/ecs-pipeline.yml — never hand-typed for a real deploy) -
variable "app_image_digest" {
  description = "Full image reference including immutable digest: <ecr-repo-url>@sha256:<64 hex chars>. See docs/ecs/container-architecture.md."
  type        = string
}

# --- Secrets — ARNs only; values live in Secrets Manager, never in
# Terraform state or var files. See docs/ecs/iam-matrix.md and
# docs/ecs/env.ecs.example. ------------------------------------------------
variable "app_key_secret_arn" {
  type = string
}

variable "db_password_secret_arn" {
  type = string
}

variable "redis_auth_token_secret_arn" {
  type = string
}

variable "redis_auth_token" {
  description = "Same value as redis_auth_token_secret_arn resolves to, needed directly by the aws_elasticache_cluster resource itself (not just by ECS tasks at runtime). Pass via `-var` sourced from Secrets Manager at plan/apply time (e.g. a wrapper script doing `aws secretsmanager get-secret-value`), never committed to a .tfvars file. See infrastructure/ecs/modules/elasticache."
  type        = string
  sensitive   = true
}

# --- SNS topic for alarm notifications — who gets paged is an operational
# decision, not created by this mission. See docs/ecs/alarm-inventory.md. --
variable "alarm_sns_topic_arn" {
  type = string
}

variable "enable_custom_metric_alarms" {
  description = "See infrastructure/ecs/modules/cloudwatch_alarms — false until app-level CloudWatch metric emission exists."
  type        = bool
  default     = false
}
