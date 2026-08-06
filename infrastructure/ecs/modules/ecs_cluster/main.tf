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

  # var.cluster_adoption_tags carries this cluster's pre-Terraform-adoption
  # live tags (e.g. {Application = "FirmsBase", Name = "firmsbase-staging-cluster"}
  # for staging) — an explicit, narrowly-scoped module input rather than a
  # hardcoded literal, so the module stays generic for a brand-new
  # environment (default {}) while the staging root supplies the exact
  # historical values. See variables.tf and docs/ecs/state-adoption-plan.md.
  tags = merge(var.tags, var.cluster_adoption_tags)

  lifecycle {
    # This staging environment's live cluster's tags_all predates this
    # environment's provider default_tags block gaining its Mission/
    # ManagedBy keys — tags_all is computed fresh from tags + the CURRENT
    # default_tags on every plan, so a routine plan otherwise proposes
    # adding those two keys (real, additive-only drift, never a
    # deletion). tags itself is NOT ignored — it is fully, explicitly
    # modeled via cluster_adoption_tags above and already matches live
    # exactly, so it remains actively drift-checked. Scoped to this one
    # resource only — never a provider-wide ignore_tags. See
    # docs/ecs/state-adoption-plan.md.
    ignore_changes = [tags_all]
  }
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
