locals {
  container_name = "app"

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
    # in-container log file, so no mountPoints/volumes are needed here.
    readonlyRootFilesystem = false # storage/{framework,logs} and bootstrap/cache must remain writable — see docs/ecs/container-architecture.md. A split read-only-root + tmpfs-mounted writable dirs is a documented hardening follow-up, not the staging default.
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
  health_check_grace_period_seconds = var.attach_target_group ? 60 : null

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
