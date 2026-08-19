locals {
  container_name = "app"

  # Mission 1B (Extreme Security Hardening), section 32. Exact leaf
  # directories per docs/ecs/container-architecture.md's own "Non-root
  # execution, file permissions, writable directories" list — each
  # becomes its own empty Fargate-managed ephemeral volume, never a
  # shared parent-directory mount (which would wipe out the
  # subdirectory structure the image's build-time chown already
  # created at that path).
  readonly_root_writable_paths = {
    "storage-framework-cache"    = "storage/framework/cache"
    "storage-framework-sessions" = "storage/framework/sessions"
    "storage-framework-testing"  = "storage/framework/testing"
    "storage-framework-views"    = "storage/framework/views"
    "storage-logs"               = "storage/logs"
    "bootstrap-cache"            = "bootstrap/cache"
  }

  container_definition = {
    name      = local.container_name
    image     = var.image
    essential = true
    command   = var.command

    portMappings = var.container_port == null ? [] : [
      {
        containerPort = var.container_port
        protocol      = "tcp"
      }
    ]

    environment = [
      for k, v in var.environment : { name = k, value = v }
    ]

    secrets = [
      for k, v in var.secrets : { name = k, valueFrom = v }
    ]

    logConfiguration = {
      logDriver = "awslogs"
      options = {
        "awslogs-group"         = var.log_group_name
        "awslogs-region"        = var.aws_region
        "awslogs-stream-prefix" = var.name
      }
    }

    healthCheck = var.container_health_check_command == null ? null : {
      command     = var.container_health_check_command
      interval    = 30
      timeout     = 5
      retries     = 3
      startPeriod = 30
    }

    # See docs/ecs/observability.md — logs go to stdout/stderr only, no
    # in-container log file. mountPoints below exist ONLY to satisfy
    # readonlyRootFilesystem, never for log output.
    readonlyRootFilesystem = var.readonly_root_filesystem

    mountPoints = var.readonly_root_filesystem ? [
      for volume_name, relative_path in local.readonly_root_writable_paths : {
        sourceVolume  = volume_name
        containerPath = "/var/www/html/${relative_path}"
        readOnly      = false
      }
    ] : []
  }
}

resource "aws_ecs_task_definition" "this" {
  family                   = var.family
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.cpu
  memory                   = var.memory
  execution_role_arn       = var.execution_role_arn
  task_role_arn            = var.task_role_arn

  container_definitions = jsonencode([
    { for k, v in local.container_definition : k => v if v != null }
  ])

  # Mission 1B (Extreme Security Hardening), section 32. One empty,
  # Fargate-managed ephemeral volume per writable leaf directory — see
  # local.readonly_root_writable_paths and this variable's own
  # docblock for why these are never a single shared parent-directory
  # mount. No-op (empty for_each) when readonly_root_filesystem is
  # false, the default.
  dynamic "volume" {
    for_each = var.readonly_root_filesystem ? local.readonly_root_writable_paths : {}
    content {
      name = volume.key
    }
  }

  tags = var.tags
}

resource "aws_ecs_service" "this" {
  count = var.create_service ? 1 : 0

  name            = var.name
  cluster         = var.cluster_id
  task_definition = aws_ecs_task_definition.this.arn
  desired_count   = var.desired_count

  # launch_type and capacity_provider_strategy are mutually exclusive on
  # this resource — AWS rejects both being set. var.use_capacity_provider_strategy
  # (no default; every caller must decide explicitly) selects between them:
  # this staging environment's live services currently run with a fixed
  # launch_type=FARGATE and no capacity-provider association at the cluster
  # level at all (see docs/ecs/state-adoption-plan.md §9.10/§9.11), so every
  # current caller sets this false. Setting launch_type to null when true is
  # equivalent to omitting the argument entirely.
  launch_type = var.use_capacity_provider_strategy ? null : "FARGATE"

  dynamic "capacity_provider_strategy" {
    for_each = var.use_capacity_provider_strategy ? [1] : []
    content {
      capacity_provider = var.capacity_provider
      weight            = 100
      base              = 0
    }
  }

  network_configuration {
    subnets          = var.subnet_ids
    security_groups  = var.security_group_ids
    assign_public_ip = var.assign_public_ip # see docs/ecs/state-adoption-plan.md §9.1 — no default; every caller must decide explicitly
  }

  dynamic "load_balancer" {
    for_each = var.attach_target_group ? [1] : []
    content {
      target_group_arn = var.target_group_arn
      container_name   = local.container_name
      container_port   = var.container_port
    }
  }

  deployment_minimum_healthy_percent = var.deployment_minimum_healthy_percent
  deployment_maximum_percent         = var.deployment_maximum_percent

  deployment_circuit_breaker {
    enable   = var.enable_deployment_circuit_breaker
    rollback = var.enable_deployment_circuit_breaker
  }

  enable_execute_command = var.enable_execute_command

  # Web tasks need time to pass the ALB health check before the deployment
  # considers them steady; other roles have no load balancer to wait on.
  health_check_grace_period_seconds = var.attach_target_group ? var.health_check_grace_period_seconds : null

  # enable_ecs_managed_tags/propagate_tags: real, live-tracked AWS
  # attributes (confirmed via the installed provider's own schema — both
  # Optional, not Computed — and via aws ecs describe-services, which
  # returns them directly). No default previously set explicitly here
  # (the AWS API default is enableECSManagedTags=false/propagateTags=NONE,
  # matching this staging environment's own live "web" service); the other
  # three live services (worker/scheduler/critical-worker) were configured
  # with enableECSManagedTags=true/propagateTags=TASK_DEFINITION by an
  # earlier, non-Terraform process, so every caller must decide explicitly
  # via var.enable_ecs_managed_tags/var.propagate_tags rather than silently
  # inheriting a value that may not match the live service being adopted.
  # See docs/ecs/state-adoption-plan.md.
  enable_ecs_managed_tags = var.enable_ecs_managed_tags
  propagate_tags          = var.propagate_tags

  # wait_for_steady_state: confirmed via the installed AWS provider's own
  # schema to be a plain Optional argument (not Computed) — the ECS API has
  # no concept of "wait for steady state" at all; it purely controls this
  # provider's own apply-time polling behavior and is never read from or
  # written to live AWS. This already-imported resource's state predates
  # this schema field entirely, so even with the exact same value
  # explicitly configured (false, the provider's own default, above), a
  # plan still proposes "adding" it once — a one-time, harmless
  # state-bookkeeping backfill, not a live mutation. Mirrors the identical,
  # evidence-proven revoke_rules_on_delete pattern already applied to the
  # security-group modules. See docs/ecs/state-adoption-plan.md.
  wait_for_steady_state = false

  tags = var.tags

  # tags/tags_all: this environment's AWS provider default_tags (Project,
  # Mission — see versions.tf) has no per-service override for those two
  # keys on any of the seven roles here, so a live-vs-config tag diff would
  # otherwise appear on the very next plan/apply after importing any of the
  # four existing services (import records live's actual tags verbatim;
  # config's computed tags_all always includes the provider defaults on
  # top). Ignoring both here freezes whatever tags exist in state at
  # import/creation time — this is deliberate adoption-metadata
  # preservation, not a blanket exemption from tagging: it has NO effect on
  # a brand-new resource's first creation (there is no prior state to
  # diff against yet), only on every apply AFTER a resource already exists
  # in state. See docs/ecs/state-adoption-plan.md §9.21.
  lifecycle {
    ignore_changes = [
      task_definition, # deploys update this via the CI/CD pipeline (see docs/ecs/env.ecs.example and .github/workflows/ecs-pipeline.yml), not via `terraform apply` re-running with a stale local image reference
      tags,
      tags_all,
      wait_for_steady_state, # see rationale above — Terraform-side-only, never read from live AWS
    ]
  }
}

# ---------------------------------------------------------------------------
# Autoscaling — target-tracking on CPU by default (see
# docs/ecs/infrastructure-architecture.md for why this is the default basis
# rather than the custom queue-depth metric, which is prepared in
# docs/ecs/observability.md but requires the CloudWatch custom metric to
# actually be emitted first). Only wired when enable_autoscaling=true — the
# critical worker and scheduler explicitly leave this off (fixed capacity —
# see docs/ecs/queue-and-redis-architecture.md and
# docs/ecs/graceful-shutdown.md).
# ---------------------------------------------------------------------------
resource "aws_appautoscaling_target" "this" {
  count = var.create_service && var.enable_autoscaling ? 1 : 0

  service_namespace  = "ecs"
  resource_id        = "service/${var.cluster_id}/${aws_ecs_service.this[0].name}"
  scalable_dimension = "ecs:service:DesiredCount"
  min_capacity       = var.autoscaling_min_capacity
  max_capacity       = var.autoscaling_max_capacity
}

resource "aws_appautoscaling_policy" "cpu" {
  count = var.create_service && var.enable_autoscaling ? 1 : 0

  name               = "${var.name}-cpu-target-tracking"
  policy_type        = "TargetTrackingScaling"
  service_namespace  = aws_appautoscaling_target.this[0].service_namespace
  resource_id        = aws_appautoscaling_target.this[0].resource_id
  scalable_dimension = aws_appautoscaling_target.this[0].scalable_dimension

  target_tracking_scaling_policy_configuration {
    predefined_metric_specification {
      predefined_metric_type = "ECSServiceAverageCPUUtilization"
    }
    target_value       = var.autoscaling_cpu_target_percent
    scale_in_cooldown  = 120
    scale_out_cooldown = 60
  }
}
