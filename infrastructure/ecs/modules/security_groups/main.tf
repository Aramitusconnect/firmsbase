resource "aws_security_group" "alb" {
  name_prefix = "${var.name_prefix}-alb-"
  description = "FirmsBase ALB — public HTTPS ingress only, no direct application access."
  vpc_id      = var.vpc_id

  tags = merge(var.tags, { Name = "${var.name_prefix}-alb" })

  lifecycle {
    create_before_destroy = true
  }
}

resource "aws_security_group_rule" "alb_ingress_https" {
  type              = "ingress"
  from_port         = 443
  to_port           = 443
  protocol          = "tcp"
  cidr_blocks       = var.alb_ingress_cidr_blocks
  security_group_id = aws_security_group.alb.id
  description       = "HTTPS from allowed staging access ranges"
}

resource "aws_security_group_rule" "alb_egress_to_ecs_tasks" {
  type                     = "egress"
  from_port                = var.container_port
  to_port                  = var.container_port
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.ecs_tasks.id
  security_group_id        = aws_security_group.alb.id
  description              = "ALB to ECS web tasks on the container port only"
}

resource "aws_security_group" "ecs_tasks" {
  name_prefix = "${var.name_prefix}-ecs-tasks-"
  description = "FirmsBase ECS tasks (web/worker/scheduler/migrate/maintenance) — no direct internet ingress."
  vpc_id      = var.vpc_id

  tags = merge(var.tags, { Name = "${var.name_prefix}-ecs-tasks" })

  lifecycle {
    create_before_destroy = true
  }
}

# Only the web role needs an inbound rule at all (from the ALB, on the
# container port). Worker/scheduler/migrate/maintenance tasks have no
# listener and receive no ingress rule.
resource "aws_security_group_rule" "ecs_tasks_ingress_from_alb" {
  type                     = "ingress"
  from_port                = var.container_port
  to_port                  = var.container_port
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.alb.id
  security_group_id        = aws_security_group.ecs_tasks.id
  description              = "ALB to web task container port"
}

# Egress: AWS API calls (ECR pull, CloudWatch Logs, Secrets Manager, S3,
# STS) all go over HTTPS. Scoping this further to specific AWS service IP
# ranges or VPC endpoints is a documented hardening follow-up (see
# docs/ecs/infrastructure-architecture.md) rather than a default here — a
# staging environment without VPC endpoints yet still needs open 443 egress
# via NAT to function at all.
resource "aws_security_group_rule" "ecs_tasks_egress_https" {
  type              = "egress"
  from_port         = 443
  to_port           = 443
  protocol          = "tcp"
  cidr_blocks       = ["0.0.0.0/0"]
  security_group_id = aws_security_group.ecs_tasks.id
  description       = "HTTPS egress for ECR/CloudWatch Logs/Secrets Manager/S3/STS"
}

resource "aws_security_group_rule" "ecs_tasks_egress_postgres" {
  count = var.existing_rds_security_group_id != null ? 1 : 0

  type              = "egress"
  from_port         = 5432
  to_port           = 5432
  protocol          = "tcp"
  security_group_id = aws_security_group.ecs_tasks.id
  # Egress rules can't target a security_group_id as source on this
  # resource type in the same way ingress can reference one as source; AWS
  # requires the destination as either a CIDR or a security group ID via
  # `source_security_group_id`, which for an EGRESS rule represents the
  # allowed destination SG. This is a same-VPC SG-to-SG reference.
  source_security_group_id = var.existing_rds_security_group_id
  description              = "ECS tasks to RDS PostgreSQL"
}

resource "aws_security_group_rule" "rds_ingress_from_ecs_tasks" {
  count = var.existing_rds_security_group_id != null ? 1 : 0

  type                     = "ingress"
  from_port                = 5432
  to_port                  = 5432
  protocol                 = "tcp"
  source_security_group_id = aws_security_group.ecs_tasks.id
  security_group_id        = var.existing_rds_security_group_id
  description              = "ECS tasks to RDS PostgreSQL"
}
