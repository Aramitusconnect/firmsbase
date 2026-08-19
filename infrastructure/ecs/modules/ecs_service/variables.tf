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

variable "health_check_grace_period_seconds" {
  description = "Seconds ECS ignores ALB health-check failures for a newly started task. Only meaningful when attach_target_group is true; ECS rejects it otherwise, so the module passes null for roles with no load balancer. Defaults to 60, the value this module previously hardcoded, so existing environments are unchanged. Preproduction raises it to 90 because /up traverses Laravel rather than being answered synthetically by Caddy, so a cold task needs longer before its first successful health check."
  type        = number
  default     = 60

  validation {
    condition     = var.health_check_grace_period_seconds >= 0 && var.health_check_grace_period_seconds <= 2147483647
    error_message = "health_check_grace_period_seconds must be between 0 and 2147483647."
  }
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

variable "use_capacity_provider_strategy" {
  description = "Whether the service places tasks via a capacity_provider_strategy block (true) or a fixed launch_type=\"FARGATE\" (false). No default, deliberately — every caller must decide explicitly, matching this module's original design intent of always using a capacity-provider strategy versus this staging environment's live reality, where every service currently runs with launch_type=FARGATE and the cluster has no capacity-provider association at all (see docs/ecs/state-adoption-plan.md §9.10/§9.11). The two are mutually exclusive on aws_ecs_service; AWS rejects setting both."
  type        = bool
}

variable "tags" {
  type    = map(string)
  default = {}
}

variable "enable_ecs_managed_tags" {
  description = "Whether ECS-managed tags (e.g. aws:ecs:serviceName) are applied to tasks. Defaults to false, matching the AWS API's own default and this staging environment's live \"web\" service. The other three live services here (worker/scheduler/critical-worker) currently run with this set true — every caller adopting an already-imported service must decide explicitly rather than silently inheriting a value that may not match the live service being adopted. See docs/ecs/state-adoption-plan.md."
  type        = bool
  default     = false
}

variable "readonly_root_filesystem" {
  description = "Mission 1B (Extreme Security Hardening), section 32: read-only root filesystem, writable temp mounts only where necessary. Defaults to false, matching this module's existing readonlyRootFilesystem=false default (documented there as 'a documented hardening follow-up, not the staging default') — flipping this to true is a deliberate, later, per-environment decision, not a side effect of this mission's own code landing. When true, the container's root filesystem is read-only and the exact six writable leaf directories docs/ecs/container-architecture.md documents (storage/framework/{cache,sessions,testing,views}, storage/logs, bootstrap/cache) are each backed by their own empty Fargate-managed ephemeral volume via mountPoints — never a shared parent-directory mount, which would wipe out subdirectories the image already created via chown at build time."
  type        = bool
  default     = false
}

variable "propagate_tags" {
  description = "Whether/how tags propagate from the task definition or service to tasks — \"NONE\", \"SERVICE\", or \"TASK_DEFINITION\". Defaults to \"NONE\", matching the AWS API's own default and this staging environment's live \"web\" service. The other three live services here (worker/scheduler/critical-worker) currently run with this set to \"TASK_DEFINITION\" — every caller adopting an already-imported service must decide explicitly. See docs/ecs/state-adoption-plan.md."
  type        = string
  default     = "NONE"

  validation {
    condition     = contains(["NONE", "SERVICE", "TASK_DEFINITION"], var.propagate_tags)
    error_message = "propagate_tags must be \"NONE\", \"SERVICE\", or \"TASK_DEFINITION\"."
  }
}
