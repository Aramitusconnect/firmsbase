variable "name" {
  description = "Role name, e.g. web/worker/critical-worker/scheduler/migrate/maintenance."
  type        = string
}

variable "family" {
  description = "Task definition family name."
  type        = string
}

variable "image" {
  description = "Full image reference INCLUDING the immutable digest (repo@sha256:...), never a mutable tag. See docs/ecs/container-architecture.md 'Image tagging and immutable digest promotion'."
  type        = string

  validation {
    condition     = can(regex("@sha256:[a-f0-9]{64}$", var.image))
    error_message = "image must reference an immutable digest (repo@sha256:<64 hex chars>), not a mutable tag — this is how the mission's 'no mutable latest tag as deployable identity' requirement is enforced in Terraform, not just by convention."
  }
}

variable "command" {
  description = "Container command array, e.g. [\"web\"] or [\"worker\"] or [\"maintenance\", \"queue:prune-failed\", \"--hours=24\"]. See docker/entrypoint.sh."
  type        = list(string)
}

variable "cpu" {
  type = number
}

variable "memory" {
  type = number
}

variable "container_port" {
  description = "Null for every role except web (no other role has an HTTP listener)."
  type        = number
  default     = null
}

variable "execution_role_arn" {
  type = string
}

variable "task_role_arn" {
  type = string
}

variable "environment" {
  description = "Plain (non-secret) environment variables. See docs/ecs/env.ecs.example for the reference set."
  type        = map(string)
  default     = {}
}

variable "secrets" {
  description = "Map of container env var name -> Secrets Manager/SSM ARN. Resolved by the execution role at task start, never baked into the image or this Terraform state as a value. See docs/ecs/iam-matrix.md."
  type        = map(string)
  default     = {}
}

variable "log_group_name" {
  type = string
}

variable "aws_region" {
  type = string
}

variable "stop_timeout_seconds" {
  description = "See docs/ecs/graceful-shutdown.md for the per-role rationale."
  type        = number
}

variable "container_health_check_command" {
  description = "Container-level health check command (CMD-SHELL array), e.g. liveness via /up for web. Null to omit (worker/scheduler/migrate/maintenance have no HTTP listener to probe; ECS's own process-exit detection is their liveness signal — see docs/ecs/container-architecture.md)."
  type        = list(string)
  default     = null
}

variable "create_service" {
  description = "True for long-running roles (web/worker/critical-worker/scheduler). False for one-off roles (migrate/maintenance), which only get a task definition — invoked via ECS RunTask by the deploy pipeline or an operator, never as a standing service. See docs/ecs/database-migrations.md."
  type        = bool
}

variable "desired_count" {
  type    = number
  default = 1
}

variable "cluster_id" {
  type = string
}

variable "subnet_ids" {
  type = list(string)
}

variable "security_group_ids" {
  type = list(string)
}

variable "assign_public_ip" {
  description = "Whether ECS tasks in this service get a public IP. No default, deliberately — every caller must decide explicitly. In a VPC with no NAT gateway (true of this repo's default staging VPC — see docs/ecs/state-adoption-plan.md §9.1), this is the ONLY way tasks reach the internet at all (ECR pulls, Secrets Manager, CloudWatch Logs, SES, SQS); setting it false there cuts off all outbound connectivity. The staging root module derives this from var.private_egress_ready, which itself cannot be set true without real, verified NAT egress (nat_gateway_ids) — see environments/staging/variables.tf."
  type        = bool
}

variable "target_group_arn" {
  description = "Web role only — the ALB target group to register tasks in. Value only — see attach_target_group for whether the load_balancer block is included; an unknown-until-apply ARN (e.g. before the target group is imported/created) cannot be compared to null to decide a for_each/count instance set."
  type        = string
  default     = null
}

variable "attach_target_group" {
  description = "Whether to register this service with target_group_arn. Must be a literal true/false set explicitly by every caller — never derived from whether target_group_arn is null, since that value can be unknown during import/plan for a not-yet-created target group, and an unknown value can't determine a for_each key set. No default, deliberately: a default of false would let an existing caller that already passes target_group_arn silently lose its load_balancer registration by simply omitting this variable during an upgrade, rather than failing loudly at plan/validate time."
  type        = bool
}

variable "deployment_minimum_healthy_percent" {
  type    = number
  default = 100
}

variable "deployment_maximum_percent" {
  type    = number
  default = 200
}

variable "enable_deployment_circuit_breaker" {
  description = "Mission requirement: 'deployment circuit breaker' + 'rollback behavior'. Automatically rolls back a service deployment that fails to reach a steady state."
  type        = bool
  default     = true
}

variable "enable_execute_command" {
  description = "ECS Exec for operator debugging (`aws ecs execute-command`). Off by default — this is a real access-expansion decision (shell access into a running task) that should be turned on deliberately per environment, not defaulted on. See docs/ecs/iam-matrix.md."
  type        = bool
  default     = false
}

variable "enable_autoscaling" {
  type    = bool
  default = false
}

variable "autoscaling_min_capacity" {
  type    = number
  default = 1
}

variable "autoscaling_max_capacity" {
  type    = number
  default = 4
}

variable "autoscaling_cpu_target_percent" {
  description = "Target-tracking scaling policy basis. See docs/ecs/infrastructure-architecture.md for why CPU (not custom queue-depth metrics, which are prepared but not wired as the default policy) is the default policy for this skeleton."
  type        = number
  default     = 60
}

variable "capacity_provider" {
  type    = string
  default = "FARGATE"

  validation {
    condition     = contains(["FARGATE", "FARGATE_SPOT"], var.capacity_provider)
    error_message = "capacity_provider must be FARGATE or FARGATE_SPOT."
  }
}

variable "tags" {
  type    = map(string)
  default = {}
}
