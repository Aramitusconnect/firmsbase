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
  # name/name_prefix and description are all ForceNew on aws_security_group
  # (the EC2 API has no in-place rename or UpdateSecurityGroupDescription
  # call) — see security_group_name/security_group_description in
  # variables.tf. var.security_group_name null (the default) preserves the
  # original name_prefix-generated behavior for a brand-new environment;
  # set, it selects the exact live name instead, so name_prefix must be
  # omitted (both cannot be set on the same resource).
  name        = var.security_group_name
  name_prefix = var.security_group_name == null ? "${var.name_prefix}-redis-" : null
  # Explicitly coalesced, not a bare var reference — a caller passing an
  # explicit null (e.g. an unset root-module override) does not itself
  # trigger this variable's own default, since nullable is not set to
  # false. See replication_group_description below for the same pattern.
  description = coalesce(var.security_group_description, "FirmsBase ElastiCache Redis — ingress from ECS tasks only.")
  vpc_id      = var.vpc_id

  # Explicit, not left to the provider schema default (also false) — a
  # diagnostic plan against this already-imported live security group
  # otherwise proposes "adding" this attribute (a newer AWS provider
  # schema field this resource's state predates), a real plan action even
  # though the effective behavior is unchanged.
  revoke_rules_on_delete = false

  tags = merge(var.tags, { Name = "${var.name_prefix}-redis" })

  lifecycle {
    create_before_destroy = true

    # This staging environment's live security group carries a single,
    # manually-set tag (key "firmsbase-staging-redis-sg", empty value) that
    # predates Terraform adoption — externally established adoption
    # metadata, not something this config should silently overwrite with
    # the Name-tag convention above. Tags (unlike name/description above)
    # are NOT ForceNew, so without this a routine plan would propose a
    # real, live tag mutation. Scoped to this one resource only — never a
    # provider-wide ignore_tags.
    #
    # revoke_rules_on_delete: confirmed via the installed AWS provider's
    # own schema (`terraform providers schema -json`) to be a plain
    # Optional argument (not Computed) — the EC2 API has no concept of
    # "revoke rules on delete" at all; it purely controls this provider's
    # own DELETE-time behavior and is never read from or written to live
    # AWS on apply. This already-imported resource's state predates this
    # schema field entirely, so even with the exact same value explicitly
    # configured (false, above), a plan still proposes "adding" it once —
    # a one-time, harmless state-bookkeeping backfill, not a live
    # mutation. Ignoring it here does not hide a security or availability
    # setting: it only ever affects a future, unplanned `destroy`, which
    # this mission does not perform. See docs/ecs/state-adoption-plan.md.
    ignore_changes = [revoke_rules_on_delete, tags, tags_all]
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
  name = coalesce(var.subnet_group_name, "${var.name_prefix}-redis")
  # Explicitly coalesced rather than left to the AWS provider schema's own
  # "Managed by Terraform" default — an already-imported live subnet group
  # with a real, human-written description should keep it, not have it
  # silently overwritten on the next routine plan.
  description = coalesce(var.subnet_group_description, "Managed by Terraform")
  subnet_ids  = var.subnet_ids
  tags        = var.tags

  lifecycle {
    # This staging environment's live subnet group carries manually-set
    # tags (Environment/Application/Name) that predate Terraform adoption
    # and don't match this module's tags/default_tags shape — externally
    # established adoption metadata, not something to silently overwrite.
    # Scoped to this one resource only — never a provider-wide ignore_tags.
    ignore_changes = [tags, tags_all]
  }
}

resource "aws_elasticache_replication_group" "this" {
  replication_group_id = "${var.name_prefix}-redis"
  # Explicitly coalesced (see subnet_group description above for the same
  # rationale) — an already-imported live replication group's real
  # description should be preserved, not silently overwritten.
  description = coalesce(var.replication_group_description, "FirmsBase staging Redis — cache/session/queue/locks (see docs/ecs/queue-and-redis-architecture.md)")

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
  # Explicit, not left to the provider schema default (also "ROTATE") — a
  # diagnostic plan against this already-imported live replication group
  # otherwise proposes "adding" this attribute (a newer AWS provider
  # schema field this resource's state predates), a real plan action even
  # though the effective behavior is unchanged. See revoke_rules_on_delete
  # on aws_security_group.redis above for the identical pattern.
  auth_token_update_strategy = "ROTATE"

  # Defaults to 0 (disabled) for a brand-new environment — see
  # snapshot_retention_limit's own description in variables.tf. An
  # already-imported live replication group with backups enabled at a
  # specific retention should have this set to the exact live value.
  snapshot_retention_limit = var.snapshot_retention_limit

  apply_immediately = false

  tags = var.tags

  lifecycle {
    # auth_token is a write-only argument — AWS never returns it via any
    # read API, so a post-import plan would otherwise show a permanent
    # diff (or attempt a disruptive in-place auth-token rotation) every
    # time. See docs/ecs/state-adoption-plan.md §9.4.
    #
    # tags/tags_all: same externally-established-adoption-metadata
    # rationale as aws_elasticache_subnet_group.this above — this live
    # replication group's tags predate Terraform adoption and don't match
    # this module's tags/default_tags shape. Scoped to this one resource
    # only.
    #
    # apply_immediately: confirmed via the installed AWS provider's own
    # schema to be Optional+Computed — a write-only control parameter for
    # HOW a future change is applied (immediately vs. next maintenance
    # window), never itself read back from or persisted by the
    # ElastiCache API. It has no effect here: every other attribute this
    # resource could otherwise drift on (auth_token, tags, tags_all) is
    # already ignored above, so no code path exists through which
    # apply_immediately's value could ever matter. This already-imported
    # resource's state predates the field, so even the exact same value
    # explicitly configured (false, above) still proposes adding it once
    # — a one-time, harmless bookkeeping backfill, not a live mutation.
    #
    # auth_token_update_strategy: same rationale — Optional (not
    # Computed), write-only, controls only HOW an auth-token rotation is
    # applied if one is ever attempted. Since auth_token itself is
    # ignore_changes'd above, Terraform never attempts that operation
    # through this resource, so this field can never trigger a live call.
    # See docs/ecs/state-adoption-plan.md.
    ignore_changes = [auth_token, apply_immediately, auth_token_update_strategy, tags, tags_all]
  }
}
