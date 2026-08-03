# Single-node staging Redis for cache/session/queue/locks (see
# docs/ecs/queue-and-redis-architecture.md). Not Multi-AZ, no read
# replicas — staging does not hold durable business data in Redis
# (everything here is reproducible: sessions can be dropped, cache can be
# rebuilt, queued jobs can be redriven from source). Production would need
# a Multi-AZ replication group with automatic failover — a distinct,
# larger, explicitly-approved decision, not defaulted here.
#
# Uses aws_elasticache_replication_group (not aws_elasticache_cluster) even
# for this single node: auth_token/transit_encryption_enabled — required so
# REDIS_PASSWORD is meaningful (see docs/ecs/env.ecs.example) — are only
# supported on the replication-group resource, not the plain cluster one.

resource "aws_security_group" "redis" {
  name_prefix = "${var.name_prefix}-redis-"
  description = "FirmsBase ElastiCache Redis — ingress from ECS tasks only."
  vpc_id      = var.vpc_id

  tags = merge(var.tags, { Name = "${var.name_prefix}-redis" })

  lifecycle {
    create_before_destroy = true
  }
}

resource "aws_security_group_rule" "redis_ingress_from_ecs_tasks" {
  type                     = "ingress"
  from_port                = 6379
  to_port                  = 6379
  protocol                 = "tcp"
  source_security_group_id = var.ecs_tasks_security_group_id
  security_group_id        = aws_security_group.redis.id
  description              = "ECS tasks to Redis"
}

resource "aws_elasticache_subnet_group" "this" {
  name       = coalesce(var.subnet_group_name, "${var.name_prefix}-redis")
  subnet_ids = var.private_subnet_ids
}

resource "aws_elasticache_replication_group" "this" {
  replication_group_id = "${var.name_prefix}-redis"
  description          = "FirmsBase staging Redis — cache/session/queue/locks (see docs/ecs/queue-and-redis-architecture.md)"

  engine               = var.engine
  engine_version       = var.engine_version
  node_type            = var.node_type
  num_cache_clusters   = 1 # single node, no read replica — see module header comment
  port                 = 6379
  parameter_group_name = var.parameter_group_name

  subnet_group_name  = aws_elasticache_subnet_group.this.name
  security_group_ids = [aws_security_group.redis.id]

  # AUTH token — required for any environment carrying real (even
  # low-sensitivity) session data. Supplied via a variable sourced from
  # Secrets Manager by the environment root module, never a literal here.
  # See docs/ecs/iam-matrix.md and docs/ecs/env.ecs.example
  # (REDIS_PASSWORD). transit_encryption_enabled is required by AWS for
  # auth_token to be accepted at all.
  transit_encryption_enabled = true
  at_rest_encryption_enabled = true
  auth_token                 = var.auth_token

  apply_immediately = false

  tags = var.tags

  lifecycle {
    # auth_token is a write-only argument — AWS never returns it via any
    # read API, so a post-import plan would otherwise show a permanent
    # diff (or attempt a disruptive in-place auth-token rotation) every
    # time. See docs/ecs/state-adoption-plan.md §9.4.
    ignore_changes = [auth_token]
  }
}
