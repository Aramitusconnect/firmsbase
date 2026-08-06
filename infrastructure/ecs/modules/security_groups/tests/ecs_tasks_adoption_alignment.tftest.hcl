# Proves the ECS-task security-group adoption-alignment correction (see
# docs/ecs/state-adoption-plan.md §9.25): ecs_tasks_security_group_name/
# ecs_tasks_security_group_description model the exact live security
# group instead of forcing its replacement — the identical, already-proven
# pattern applied to module.elasticache.aws_security_group.redis. Also
# proves §9.26: ecs_tasks_security_group_adoption_tags defaults to {} for
# a brand-new environment and, when supplied, merges the legacy
# pre-Terraform-adoption tag alongside the Name tag — explicitly modeled,
# not ignore_changes-only.
#
# Run with: terraform test (from infrastructure/ecs/modules/security_groups)

mock_provider "aws" {}

variables {
  name_prefix             = "firmsbase-staging"
  vpc_id                  = "vpc-0fd81b688155ded2b"
  alb_ingress_cidr_blocks = ["0.0.0.0/0"]
}

run "ecs_tasks_sg_defaults_to_name_prefix_when_not_overridden" {
  command = plan

  assert {
    condition     = aws_security_group.ecs_tasks.name_prefix == "firmsbase-staging-ecs-tasks-"
    error_message = "Without ecs_tasks_security_group_name set, name_prefix must resolve to the module's original pattern."
  }

  assert {
    condition     = aws_security_group.ecs_tasks.description == "FirmsBase ECS tasks (web/worker/scheduler/migrate/maintenance) — no direct internet ingress."
    error_message = "Without ecs_tasks_security_group_description set, the module's own default description must apply."
  }
}

run "ecs_tasks_sg_models_the_exact_live_name_and_description_when_overridden" {
  command = plan

  variables {
    ecs_tasks_security_group_name        = "firmsbase-staging-ecs-sg"
    ecs_tasks_security_group_description = "FirmsBase staging ECS tasks"
  }

  assert {
    condition     = aws_security_group.ecs_tasks.name == "firmsbase-staging-ecs-sg"
    error_message = "ecs_tasks_security_group_name must resolve to the exact live name."
  }

  assert {
    condition     = aws_security_group.ecs_tasks.description == "FirmsBase staging ECS tasks"
    error_message = "ecs_tasks_security_group_description must resolve to the exact live description."
  }
}

run "ecs_tasks_sg_resource_address_and_vpc_are_unaffected" {
  command = plan

  variables {
    ecs_tasks_security_group_name        = "firmsbase-staging-ecs-sg"
    ecs_tasks_security_group_description = "FirmsBase staging ECS tasks"
  }

  assert {
    condition     = aws_security_group.ecs_tasks.vpc_id == "vpc-0fd81b688155ded2b"
    error_message = "The security group's VPC must be unaffected by these overrides."
  }
}

run "ecs_tasks_adoption_tags_defaults_to_empty_for_a_brand_new_environment" {
  command = plan

  assert {
    condition     = aws_security_group.ecs_tasks.tags == tomap({ Name = "firmsbase-staging-ecs-tasks" })
    error_message = "Without ecs_tasks_security_group_adoption_tags set, the security group's tags must contain only the Name tag this module always sets — a brand-new environment must be unaffected."
  }
}

run "ecs_tasks_adoption_tags_models_the_exact_legacy_tag_when_supplied" {
  command = plan

  variables {
    ecs_tasks_security_group_adoption_tags = {
      "firmsbase-staging-ecs-sg" = ""
    }
  }

  assert {
    condition     = aws_security_group.ecs_tasks.tags == tomap({ Name = "firmsbase-staging-ecs-tasks", "firmsbase-staging-ecs-sg" = "" })
    error_message = "ecs_tasks_security_group_adoption_tags must be merged onto the security group alongside the Name tag, exactly reproducing the confirmed live legacy tag."
  }

  assert {
    condition     = aws_security_group.alb.tags == tomap({ Name = "firmsbase-staging-alb" })
    error_message = "module.security_groups.aws_security_group.alb must be unaffected by the ecs_tasks-specific adoption tags — it is a separate, out-of-scope resource."
  }
}

run "alb_security_group_is_unaffected_by_ecs_tasks_overrides" {
  command = plan

  variables {
    ecs_tasks_security_group_name        = "firmsbase-staging-ecs-sg"
    ecs_tasks_security_group_description = "FirmsBase staging ECS tasks"
  }

  assert {
    condition     = aws_security_group.alb.name_prefix == "firmsbase-staging-alb-"
    error_message = "module.security_groups.aws_security_group.alb must not be touched by the ecs_tasks-specific overrides — it is a separate, out-of-scope resource."
  }
}

run "ecs_tasks_ingress_and_rds_ingress_rules_plan_cleanly_alongside_the_overrides" {
  command = plan

  # aws_security_group.ecs_tasks.id is unknown-until-apply under
  # mock_provider, so the rules' reference to it cannot be asserted by
  # value equality here — that exact wiring (security_group_id =
  # aws_security_group.ecs_tasks.id / source_security_group_id =
  # aws_security_group.ecs_tasks.id) is proven by a source-text test
  # instead (tests/Feature/Ecs/StagingEcsTaskSecurityGroupAlignmentTest.php).
  # This run only proves the two rules still plan without error alongside
  # the name/description overrides — their own resource addresses are
  # unchanged from the module source.
  variables {
    ecs_tasks_security_group_name        = "firmsbase-staging-ecs-sg"
    ecs_tasks_security_group_description = "FirmsBase staging ECS tasks"
    existing_rds_security_group_id       = "sg-0d4c5eedb2ee21743"
  }

  assert {
    condition     = aws_security_group_rule.ecs_tasks_ingress_from_alb.type == "ingress"
    error_message = "ecs_tasks_ingress_from_alb must remain an ingress rule."
  }

  assert {
    condition     = aws_security_group_rule.rds_ingress_from_ecs_tasks[0].type == "ingress"
    error_message = "rds_ingress_from_ecs_tasks must remain an ingress rule."
  }
}
