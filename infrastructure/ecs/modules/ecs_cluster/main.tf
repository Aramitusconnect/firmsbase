# Fargate only — no EC2 capacity providers. This mission migrates AWAY from
# EC2-hosted application processes (see mission statement); provisioning a
# new EC2-backed ECS capacity provider would reintroduce exactly what's
# being retired.

resource "aws_ecs_cluster" "this" {
  name = var.cluster_name

  setting {
    name  = "containerInsights"
    value = "enabled"
  }

  tags = var.tags
}

resource "aws_ecs_cluster_capacity_providers" "this" {
  cluster_name = aws_ecs_cluster.this.name

  capacity_providers = ["FARGATE", "FARGATE_SPOT"]

  # Default to on-demand FARGATE; FARGATE_SPOT is available per-service for
  # workloads that tolerate interruption (e.g. the low-priority queue lane —
  # see docs/ecs/queue-and-redis-architecture.md) but is not the default
  # weight for anything web-facing or trust-adjacent.
  default_capacity_provider_strategy {
    capacity_provider = "FARGATE"
    weight            = 100
    base              = 0
  }
}
