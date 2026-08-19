resource "aws_security_group" "alb" {
  # name/name_prefix and description are all ForceNew on aws_security_group
  # (the EC2 API has no in-place rename or UpdateSecurityGroupDescription
  # call) — see alb_security_group_name/alb_security_group_description in
  # variables.tf. This mirrors the identical, evidence-proven pattern
  # already applied to aws_security_group.ecs_tasks and
  # module.elasticache.aws_security_group.redis — confirmed via direct
  # config comparison against live (aws ec2 describe-security-groups) and
  # a real diagnostic plan's own "# forces replacement" annotations on
  # both name and description for this specific resource, not assumed
  # from the other two security groups' similar symptom. See
  # docs/ecs/state-adoption-plan.md.
  name        = var.alb_security_group_name
  name_prefix = var.alb_security_group_name == null ? "${var.name_prefix}-alb-" : null
  # Explicitly coalesced, not a bare var reference — a caller passing an
  # explicit null (e.g. an unset root-module override) does not itself
  # trigger this variable's own default, since nullable is not set to
  # false.
  description = coalesce(var.alb_security_group_description, "FirmsBase ALB — public HTTPS ingress only, no direct application access.")
  vpc_id      = var.vpc_id

  # Explicit, not left to the provider schema default (also false) — a
  # diagnostic plan against this already-imported live security group
  # otherwise proposes "adding" this attribute (a newer AWS provider
  # schema field this resource's state predates), a real plan action even
  # though the effective behavior is unchanged.
  revoke_rules_on_delete = false

  tags = merge(var.tags, { Name = "${var.name_prefix}-alb" })

  lifecycle {
    create_before_destroy = true

    # This staging environment's live security group carries real,
    # manually-set tags (Environment/Application/Name) that predate
    # Terraform adoption and don't match this module's tags/default_tags
    # shape — externally established adoption metadata, not something
    # this config should silently overwrite. Tags (unlike name/
    # description above) are NOT ForceNew, so without this a routine
    # plan would propose a real, live tag mutation. Scoped to this one
    # resource only — never a provider-wide ignore_tags.
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
    # mutation. See docs/ecs/state-adoption-plan.md.
    ignore_changes = [revoke_rules_on_delete, tags, tags_all]
  }
}

resource "aws_security_group_rule" "alb_ingress_https" {
  type              = "ingress"
  from_port         = 443
  to_port           = 443
  protocol          = "tcp"
  cidr_blocks       = var.alb_ingress_cidr_blocks
  security_group_id = aws_security_group.alb.id
  # No description — the live, pre-Terraform-created rule (confirmed via
  # aws ec2 describe-security-group-rules) has none. Adding cosmetic
  # documentation to an already-imported live rule during adoption is
  # explicitly out of scope; see ecs_tasks_ingress_from_alb below for the
  # identical rationale.
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
  # name/name_prefix and description are all ForceNew on aws_security_group
  # (the EC2 API has no in-place rename or UpdateSecurityGroupDescription
  # call) — see ecs_tasks_security_group_name/ecs_tasks_security_group_description
  # in variables.tf. var.ecs_tasks_security_group_name null (the default)
  # preserves the original name_prefix-generated behavior for a brand-new
  # environment; set, it selects the exact live name instead, so
  # name_prefix must be omitted (both cannot be set on the same resource).
  # This mirrors the identical, evidence-proven pattern already applied to
  # module.elasticache.aws_security_group.redis — confirmed via a real
  # diagnostic plan's own "# forces replacement" annotations on both name
  # and description for this specific resource, not assumed from symptom
  # similarity alone. See docs/ecs/state-adoption-plan.md.
  name        = var.ecs_tasks_security_group_name
  name_prefix = var.ecs_tasks_security_group_name == null ? "${var.name_prefix}-ecs-tasks-" : null
  # Explicitly coalesced, not a bare var reference — a caller passing an
  # explicit null (e.g. an unset root-module override) does not itself
  # trigger this variable's own default, since nullable is not set to
  # false.
  description = coalesce(var.ecs_tasks_security_group_description, "FirmsBase ECS tasks (web/worker/scheduler/migrate/maintenance) — no direct internet ingress.")
  vpc_id      = var.vpc_id

  # Explicit, not left to the provider schema default (also false) — a
  # diagnostic plan against this already-imported live security group
  # otherwise proposes "adding" this attribute (a newer AWS provider
  # schema field this resource's state predates), a real plan action even
  # though the effective behavior is unchanged.
  revoke_rules_on_delete = false

  # var.ecs_tasks_security_group_adoption_tags carries this one resource's
  # pre-Terraform-adoption legacy tag (e.g. {"firmsbase-staging-ecs-sg" = ""}
  # for staging) — an explicit, narrowly-scoped module input rather than a
  # hardcoded literal, so the module stays generic for a brand-new
  # environment (default {}) while the staging root supplies the exact
  # historical value. See variables.tf and docs/ecs/state-adoption-plan.md.
  tags = merge(var.tags, var.ecs_tasks_security_group_adoption_tags, { Name = "${var.name_prefix}-ecs-tasks" })

  lifecycle {
    create_before_destroy = true

    # tags/tags_all are deliberately NOT ignored here (unlike the alb
    # sibling resource above): the legacy pre-Terraform tag is now
    # explicitly modeled via var.ecs_tasks_security_group_adoption_tags
    # above, merged with the Name tag and the provider's default_tags, so
    # a routine plan can verify the live security group's tags match
    # config exactly rather than masking any drift. Previously this tag
    # was protected only by an ignore_changes entry, which — per
    # docs/ecs/state-adoption-plan.md — does not reliably survive a real
    # apply's tag-reconciliation call.
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
    ignore_changes = [revoke_rules_on_delete]
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
  # No description — the live, pre-Terraform-created rule (confirmed via
  # aws ec2 describe-security-group-rules) has none. Adding cosmetic
  # documentation to an already-imported live rule during adoption is
  # explicitly out of scope for this correction.
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
  # No description — the live, pre-Terraform-created rule (confirmed via
  # aws ec2 describe-security-group-rules) has none. Adding cosmetic
  # documentation to an already-imported live rule during adoption is
  # explicitly out of scope for this correction.
}
