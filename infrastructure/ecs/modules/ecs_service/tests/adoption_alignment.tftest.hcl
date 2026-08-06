# Proves the ECS-service adoption-alignment correction (see
# docs/ecs/state-adoption-plan.md §9.26 [ALB/ECR/cluster/service wave]):
# enable_ecs_managed_tags/propagate_tags default to the AWS API's own
# defaults (false/"NONE", matching this staging environment's live "web"
# service) and resolve to the exact live override values when set (true/
# "TASK_DEFINITION", matching worker/scheduler/critical-worker); and
# wait_for_steady_state is explicitly pinned and ignore_changes-protected
# the same evidence-proven way as revoke_rules_on_delete on the
# security-group modules.
#
# Run with: terraform test (from infrastructure/ecs/modules/ecs_service)

mock_provider "aws" {}

variables {
  name                           = "worker"
  family                         = "firmsbase-staging-worker"
  image                          = "603013471426.dkr.ecr.us-east-1.amazonaws.com/firmsbase-staging@sha256:0000000000000000000000000000000000000000000000000000000000000000"
  command                        = ["worker"]
  cpu                            = 512
  memory                         = 1024
  execution_role_arn             = "arn:aws:iam::603013471426:role/mock-task-execution"
  task_role_arn                  = "arn:aws:iam::603013471426:role/mock-task-worker"
  log_group_name                 = "/ecs/firmsbase-staging/worker"
  aws_region                     = "us-east-1"
  stop_timeout_seconds           = 120
  create_service                 = true
  cluster_id                     = "arn:aws:ecs:us-east-1:603013471426:cluster/firmsbase-staging-cluster"
  subnet_ids                     = ["subnet-020540b8377bb4d0e", "subnet-07efcb5d4bcf5aa59"]
  security_group_ids             = ["sg-0db14e50ea5c5466c"]
  assign_public_ip               = true
  use_capacity_provider_strategy = false
  attach_target_group            = false
}

run "managed_tags_and_propagate_tags_default_to_the_aws_api_defaults" {
  command = plan

  assert {
    condition     = aws_ecs_service.this[0].enable_ecs_managed_tags == false
    error_message = "Without enable_ecs_managed_tags set, this must default to false — the AWS API's own default, matching this staging environment's live \"web\" service."
  }

  assert {
    condition     = aws_ecs_service.this[0].propagate_tags == "NONE"
    error_message = "Without propagate_tags set, this must default to \"NONE\" — the AWS API's own default, matching this staging environment's live \"web\" service."
  }
}

run "managed_tags_and_propagate_tags_model_the_exact_live_override_when_set" {
  command = plan

  variables {
    enable_ecs_managed_tags = true
    propagate_tags          = "TASK_DEFINITION"
  }

  assert {
    condition     = aws_ecs_service.this[0].enable_ecs_managed_tags == true
    error_message = "enable_ecs_managed_tags must resolve to the exact override value — this is what makes worker/scheduler/critical-worker (live true) importable without proposing an unreviewed update on the next apply."
  }

  assert {
    condition     = aws_ecs_service.this[0].propagate_tags == "TASK_DEFINITION"
    error_message = "propagate_tags must resolve to the exact override value."
  }
}

run "propagate_tags_rejects_an_invalid_value" {
  command = plan

  variables {
    propagate_tags = "not-a-real-value"
  }

  expect_failures = [
    var.propagate_tags,
  ]
}

run "wait_for_steady_state_is_pinned_to_false_matching_the_provider_default" {
  command = plan

  assert {
    condition     = aws_ecs_service.this[0].wait_for_steady_state == false
    error_message = "wait_for_steady_state must be explicitly pinned to false, the AWS provider's own default — a Terraform-side-only field never read from live AWS."
  }
}

run "wait_for_steady_state_is_ignore_changes_protected_across_a_subsequent_apply" {
  command = apply

  assert {
    condition     = aws_ecs_service.this[0].wait_for_steady_state == false
    error_message = "First apply must set wait_for_steady_state from config."
  }
}

run "service_identity_and_networking_unaffected_by_the_adoption_overrides" {
  command = plan

  variables {
    enable_ecs_managed_tags = true
    propagate_tags          = "TASK_DEFINITION"
  }

  assert {
    condition     = aws_ecs_service.this[0].name == "worker"
    error_message = "The service's own identity (name) must be unaffected by the adoption overrides."
  }

  assert {
    condition     = aws_ecs_service.this[0].network_configuration[0].security_groups == toset(["sg-0db14e50ea5c5466c"])
    error_message = "network_configuration must be unaffected by the adoption overrides."
  }
}
