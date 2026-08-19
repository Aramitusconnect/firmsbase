resource "aws_lb" "this" {
  # name/name_prefix are ForceNew on aws_lb (the ELBv2 API has no in-place
  # rename) — see alb_name in variables.tf. var.alb_name null (the default)
  # preserves the original name_prefix-generated behavior for a brand-new
  # environment; set, it selects the exact live name instead, so
  # name_prefix must be omitted (both cannot be set on the same resource).
  # This mirrors the identical, evidence-proven pattern already applied to
  # the security-group modules — see docs/ecs/state-adoption-plan.md.
  name               = var.alb_name
  name_prefix        = var.alb_name == null ? substr(var.name_prefix, 0, 6) : null
  internal           = false
  load_balancer_type = "application"
  subnets            = var.public_subnet_ids
  security_groups    = [var.security_group_id]

  enable_deletion_protection = var.enable_deletion_protection

  dynamic "access_logs" {
    for_each = var.access_logs_bucket == null ? [] : [1]
    content {
      bucket  = var.access_logs_bucket
      enabled = true
    }
  }

  # var.alb_adoption_tags carries this ALB's pre-Terraform-adoption live
  # tags (e.g. {Name = "firmsbase-staging-alb", Project = "FirmsBase"} for
  # staging) — an explicit, narrowly-scoped module input rather than a
  # hardcoded literal, so the module stays generic for a brand-new
  # environment (default {}) while the staging root supplies the exact
  # historical values. See variables.tf and docs/ecs/state-adoption-plan.md.
  tags = merge(var.tags, var.alb_adoption_tags)

  lifecycle {
    # tags_all is computed fresh from tags + the provider's current
    # default_tags block on every plan — this live ALB predates the
    # Mission/ManagedBy keys being added to that block (its cached
    # tags_all only carries the older Project/Environment/Name subset),
    # so a routine plan otherwise proposes adding those two keys. This is
    # real, additive-only drift (never a deletion), not the identity
    # (name/name_prefix) drift var.alb_name above already resolves —
    # ignore_changes here does not conceal any ForceNew mismatch. Scoped
    # to this one resource only — never a provider-wide ignore_tags.
    ignore_changes = [tags_all]
  }
}

# Readiness endpoint is the ALB target-group health check (see
# app/Http/Controllers/ReadinessController.php and
# docs/ecs/container-architecture.md "Health checks" — deliberately
# distinct from the liveness `/up` route, which the container-level ECS
# health check uses instead, see docs/ecs/graceful-shutdown.md).
resource "aws_lb_target_group" "web" {
  # name/name_prefix are ForceNew on aws_lb_target_group (the ELBv2 API has
  # no in-place rename) — see target_group_name in variables.tf. Same
  # null-default/ternary pattern as aws_lb.this above.
  name        = var.target_group_name
  name_prefix = var.target_group_name == null ? substr(var.name_prefix, 0, 6) : null
  port        = var.container_port
  protocol    = "HTTP"
  vpc_id      = var.vpc_id
  target_type = "ip" # Fargate requires "ip", not "instance"

  deregistration_delay = var.deregistration_delay_seconds

  health_check {
    enabled             = true
    path                = var.readiness_health_check_path
    protocol            = "HTTP"
    port                = "traffic-port"
    interval            = var.health_check_interval_seconds
    timeout             = var.health_check_timeout_seconds
    healthy_threshold   = var.healthy_threshold_count
    unhealthy_threshold = var.unhealthy_threshold_count
    matcher             = var.health_check_matcher
  }

  # Explicit, not left to the provider schema defaults (both also false)
  # — a diagnostic plan against this already-imported live target group
  # otherwise proposes "adding" these two attributes (newer AWS provider
  # schema fields this resource's state predates, and which don't apply
  # to an "ip" target type anyway), a real plan action even though the
  # effective behavior is unchanged. Mirrors the identical,
  # evidence-proven revoke_rules_on_delete pattern already applied to
  # the security-group modules.
  lambda_multi_value_headers_enabled = false
  proxy_protocol_v2                  = false

  # var.target_group_adoption_tags carries this target group's
  # pre-Terraform-adoption live tags — see aws_lb.this's identical
  # alb_adoption_tags treatment above.
  tags = merge(var.tags, var.target_group_adoption_tags)

  lifecycle {
    create_before_destroy = true
    # tags_all: same additive-only, default_tags-growth rationale as
    # aws_lb.this above.
    ignore_changes = [lambda_multi_value_headers_enabled, proxy_protocol_v2, tags_all]
  }
}

resource "aws_lb_listener" "https" {
  load_balancer_arn = aws_lb.this.arn
  port              = 443
  protocol          = "HTTPS"
  ssl_policy        = "ELBSecurityPolicy-TLS13-1-2-2021-06"
  certificate_arn   = var.acm_certificate_arn

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.web.arn
  }

  # var.https_listener_tags carries this listener's pre-Terraform-adoption
  # live tag (e.g. {Name = "firmsbase-staging-https"} for staging) — see
  # aws_lb.this's identical alb_adoption_tags treatment above. This
  # resource had no tags argument at all before this correction.
  tags = merge(var.tags, var.https_listener_tags)

  lifecycle {
    # default_action: confirmed via a real, saved plan's JSON diff — this
    # listener is configured via the legacy default_action.target_group_arn
    # shorthand (which already matches live exactly), but the AWS API's
    # own read-back always populates the newer, richer
    # default_action.forward block representation of the identical
    # routing config. Config's plan-time computation of forward=[] (since
    # it isn't declared explicitly) differs from state's populated
    # forward block even though both express the exact same live
    # forwarding rule (confirmed target_group_arn matches exactly) — a
    # provider representational artifact, not a real routing difference.
    # tags_all: same additive-only, default_tags-growth rationale as
    # aws_lb.this above.
    ignore_changes = [default_action, tags_all]
  }
}

resource "aws_lb_listener" "http_redirect" {
  load_balancer_arn = aws_lb.this.arn
  port              = 80
  protocol          = "HTTP"

  default_action {
    type = "redirect"

    redirect {
      port        = "443"
      protocol    = "HTTPS"
      status_code = "HTTP_301"
    }
  }

  # var.http_redirect_listener_tags — see aws_lb_listener.https above.
  # Empty (default) for this listener: the live resource carries no tags
  # at all (confirmed via aws elbv2 describe-tags).
  tags = merge(var.tags, var.http_redirect_listener_tags)

  lifecycle {
    # tags_all: same additive-only, default_tags-growth rationale as
    # aws_lb.this above.
    ignore_changes = [tags_all]
  }
}

# Mission 1 (canonical reconstruction — Domain & Security Boundary
# Architecture). Host-header routing for the six canonical FirmsVault
# hostnames — all forward to the same aws_lb_target_group.web (one ECS
# service, multiple hostnames; see var.canonical_hostnames' own
# docstring). No-op (creates nothing) until var.canonical_hostnames is
# actually supplied with real, externally-provisioned hostnames — see
# that variable's description for why this module cannot invent or
# assign them itself.
locals {
  canonical_hostname_priorities = {
    marketing     = 101
    firm_app      = 102
    client_portal = 103
    admin         = 104
    myattorney    = 105
    api           = 106
  }
}

resource "aws_lb_listener_rule" "host_routed" {
  for_each = var.canonical_hostnames == null ? {} : var.canonical_hostnames

  listener_arn = aws_lb_listener.https.arn
  priority     = local.canonical_hostname_priorities[each.key]

  action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.web.arn
  }

  condition {
    host_header {
      values = [each.value]
    }
  }

  tags = var.tags
}
