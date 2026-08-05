# Proves the tags/tags_all lifecycle.ignore_changes protection added
# alongside task_definition (see docs/ecs/state-adoption-plan.md §9.21):
# this environment's AWS provider default_tags (Project, Mission — see
# environments/staging/versions.tf) has no per-service override for those
# two keys, so a live-vs-config tag diff would otherwise appear on the
# very next plan/apply after importing any existing service. ignore_changes
# freezes whatever tags/tags_all exist in state at import/creation time.
#
# ignore_changes is a Terraform-core meta-argument, not provider-specific
# behavior, so its diff-suppression is faithfully exercised under
# mock_provider even though the mock does not implement the AWS provider's
# own default_tags merge logic (that part is proven separately via
# `terraform validate` accepting tags_all as a real ignore_changes target,
# and via the documented, deterministic AWS provider merge semantics — see
# StagingEcsServiceTagLifecycleTest.php).
#
# Run with: terraform test (from infrastructure/ecs/modules/ecs_service)

mock_provider "aws" {}

variables {
  name                           = "web"
  family                         = "firmsbase-staging-web"
  image                          = "603013471426.dkr.ecr.us-east-1.amazonaws.com/firmsbase-staging@sha256:0000000000000000000000000000000000000000000000000000000000000000"
  command                        = ["web"]
  cpu                            = 512
  memory                         = 1024
  execution_role_arn             = "arn:aws:iam::603013471426:role/mock-task-execution"
  task_role_arn                  = "arn:aws:iam::603013471426:role/mock-task-web"
  log_group_name                 = "/ecs/firmsbase-staging/web"
  aws_region                     = "us-east-1"
  stop_timeout_seconds           = 90
  create_service                 = true
  cluster_id                     = "arn:aws:ecs:us-east-1:603013471426:cluster/firmsbase-staging-cluster"
  subnet_ids                     = ["subnet-020540b8377bb4d0e", "subnet-07efcb5d4bcf5aa59"]
  security_group_ids             = ["sg-0db14e50ea5c5466c"]
  assign_public_ip               = true
  use_capacity_provider_strategy = false
  attach_target_group            = false
  tags = {
    Environment = "staging"
    ManagedBy   = "manual-reviewed-deployment"
  }
}

run "initial_apply_sets_the_configured_tags" {
  command = apply

  assert {
    condition     = aws_ecs_service.this[0].tags["Environment"] == "staging"
    error_message = "First apply (resource does not yet exist in state) must set tags from config exactly — ignore_changes has no effect on initial creation."
  }

  assert {
    condition     = aws_ecs_service.this[0].tags["ManagedBy"] == "manual-reviewed-deployment"
    error_message = "First apply must set every configured tag key, not just one."
  }
}

run "subsequent_apply_with_changed_tags_is_ignored" {
  command = apply

  variables {
    tags = {
      Environment = "production"
      ManagedBy   = "terraform"
      Project     = "firmsbase"
    }
  }

  assert {
    condition     = aws_ecs_service.this[0].tags["Environment"] == "staging"
    error_message = "tags must remain frozen at the first apply's value (\"staging\") — lifecycle.ignore_changes=[tags, tags_all] must suppress reconciliation toward the new config value (\"production\") on a subsequent apply, once the resource already exists in state."
  }

  assert {
    condition     = aws_ecs_service.this[0].tags["ManagedBy"] == "manual-reviewed-deployment"
    error_message = "ManagedBy must remain frozen at the first apply's value, not be reconciled to the new config value \"terraform\"."
  }
}
