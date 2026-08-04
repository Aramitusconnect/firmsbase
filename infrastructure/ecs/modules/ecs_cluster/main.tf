# Fargate only — no EC2 capacity providers. This mission migrates AWAY from
# EC2-hosted application processes (see mission statement); provisioning a
# new EC2-backed ECS capacity provider would reintroduce exactly what's
# being retired.

resource "aws_ecs_cluster" "this" {
  name = var.cluster_name

  setting {
    name  = "containerInsights"
    value = var.container_insights_enabled ? "enabled" : "disabled"
  }

  tags = var.tags
}

resource "aws_ecs_cluster_capacity_providers" "this" {
  cluster_name = aws_ecs_cluster.this.name

  # Default [\"FARGATE\", \"FARGATE_SPOT\"] preserves this module's original
  # design intent; this staging environment's live cluster currently has no
  # capacity providers associated at all — see var.capacity_providers.
  capacity_providers = var.capacity_providers

  # Default to on-demand FARGATE; FARGATE_SPOT is available per-service for
  # workloads that tolerate interruption (e.g. the low-priority queue lane —
  # see docs/ecs/queue-and-redis-architecture.md) but is not the default
  # weight for anything web-facing or trust-adjacent. Omitted entirely when
  # var.capacity_providers is empty (live-adoption case) — a default
  # strategy referencing zero associated providers would be nonsensical.
  dynamic "default_capacity_provider_strategy" {
    for_each = length(var.capacity_providers) > 0 ? [var.default_capacity_provider] : []
    content {
      capacity_provider = default_capacity_provider_strategy.value
      weight            = 100
      base              = 0
    }
  }
}
