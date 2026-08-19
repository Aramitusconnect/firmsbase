# Proves the launch_type / capacity_provider_strategy correction (see
# docs/ecs/state-adoption-plan.md §9.10/§9.11): live staging services
# currently run with a fixed launch_type=FARGATE and no capacity-provider
# association at the cluster level, but the module previously hardcoded a
# capacity_provider_strategy block unconditionally — set both launch_type
# and capacity_provider_strategy on the same aws_ecs_service and AWS
# rejects the request outright. var.use_capacity_provider_strategy (no
# default — every caller must decide explicitly) now selects exactly one
# of the two, never both.
#
# Run with: terraform test (from infrastructure/ecs/modules/ecs_service)

mock_provider "aws" {}

variables {
  name                 = "web"
  family               = "firmsbase-staging-web"
  image                = "603013471426.dkr.ecr.us-east-1.amazonaws.com/firmsbase-staging@sha256:0000000000000000000000000000000000000000000000000000000000000000"
  command              = ["web"]
  cpu                  = 512
  memory               = 1024
  execution_role_arn   = "arn:aws:iam::603013471426:role/mock-task-execution"
  task_role_arn        = "arn:aws:iam::603013471426:role/mock-task-web"
  log_group_name       = "/ecs/firmsbase-staging/web"
  aws_region           = "us-east-1"
  stop_timeout_seconds = 90
  create_service       = true
  cluster_id           = "arn:aws:ecs:us-east-1:603013471426:cluster/firmsbase-staging-cluster"
  subnet_ids           = ["subnet-020540b8377bb4d0e", "subnet-07efcb5d4bcf5aa59"]
  security_group_ids   = ["sg-0db14e50ea5c5466c"]
  assign_public_ip     = true
  attach_target_group  = false
  # use_capacity_provider_strategy has no default — this shared block sets
  # the "false" case (live-matching); the run below overrides it to true.
  use_capacity_provider_strategy = false
}

run "false_sets_launch_type_fargate_and_omits_capacity_provider_strategy" {
  command = plan

  assert {
    condition     = aws_ecs_service.this[0].launch_type == "FARGATE"
    error_message = "use_capacity_provider_strategy=false must set launch_type to \"FARGATE\"."
  }

  assert {
    condition     = length(aws_ecs_service.this[0].capacity_provider_strategy) == 0
    error_message = "use_capacity_provider_strategy=false must omit the capacity_provider_strategy block entirely, not merely leave it empty-but-present."
  }
}

run "true_renders_capacity_provider_strategy_block_count" {
  # launch_type is Optional+Computed on aws_ecs_service — explicitly
  # assigning it null (rather than a concrete string) defers to the
  # provider, so it is UNKNOWN at plan time even under mock_provider, not
  # concretely null. That specific assertion moves to the apply-time run
  # below, matching this repo's established pattern (see modules/iam/tests
  # for the same mock_provider limitation on provider-computed values).
  # capacity_provider_strategy is a block, not Computed, so its presence
  # (count) IS knowable at plan time.
  command = plan

  variables {
    use_capacity_provider_strategy = true
  }

  assert {
    condition     = length(aws_ecs_service.this[0].capacity_provider_strategy) == 1
    error_message = "use_capacity_provider_strategy=true must render exactly one capacity_provider_strategy block."
  }
}

run "true_sets_capacity_provider_at_apply" {
  # command = apply (still 100% mocked, zero real AWS contact). Note:
  # launch_type's actual null-ness cannot be proven here — mock_provider
  # synthesizes a fake computed string for any Optional+Computed attribute
  # left null in config, even under apply, since it has no semantic
  # knowledge of AWS's real mutual-exclusivity behavior between
  # launch_type and capacity_provider_strategy. The exact
  # `launch_type = var.use_capacity_provider_strategy ? null : "FARGATE"`
  # source expression is instead proven directly by
  # tests/Feature/Ecs/StagingPhaseA3AdoptionAlignmentTest.php, which reads
  # the real committed HCL.
  command = apply

  variables {
    use_capacity_provider_strategy = true
  }

  assert {
    # capacity_provider_strategy is represented as a set of objects (no
    # addressable index) — use a for/contains check instead of [0].
    condition     = contains([for s in aws_ecs_service.this[0].capacity_provider_strategy : s.capacity_provider], "FARGATE")
    error_message = "The rendered capacity_provider_strategy block must use var.capacity_provider (default FARGATE)."
  }
}

run "true_with_explicit_capacity_provider_spot" {
  command = apply

  variables {
    use_capacity_provider_strategy = true
    capacity_provider              = "FARGATE_SPOT"
  }

  assert {
    condition     = contains([for s in aws_ecs_service.this[0].capacity_provider_strategy : s.capacity_provider], "FARGATE_SPOT")
    error_message = "capacity_provider must flow through into the rendered capacity_provider_strategy block."
  }
}
