resource "aws_lb" "this" {
  name_prefix        = substr(var.name_prefix, 0, 6)
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

  tags = var.tags
}

# Readiness endpoint is the ALB target-group health check (see
# app/Http/Controllers/ReadinessController.php and
# docs/ecs/container-architecture.md "Health checks" — deliberately
# distinct from the liveness `/up` route, which the container-level ECS
# health check uses instead, see docs/ecs/graceful-shutdown.md).
resource "aws_lb_target_group" "web" {
  name_prefix = substr(var.name_prefix, 0, 6)
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
    matcher             = "200"
  }

  tags = var.tags

  lifecycle {
    create_before_destroy = true
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
}

# Mission 1 (Domain & Security Boundary Architecture), section 40. Host-
# header routing for the six canonical FirmsVault hostnames — all forward
# to the same aws_lb_target_group.web (one ECS service, multiple
# hostnames; see var.canonical_hostnames' own docstring). No-op (creates
# nothing) until var.canonical_hostnames is actually supplied with real,
# externally-provisioned hostnames — see that variable's description for
# why this module cannot invent or assign them itself.
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
