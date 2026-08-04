# Proves the load_balancer dynamic block / health_check_grace_period_seconds
# fix from the import-graph correction (see docs/ecs/state-adoption-plan.md
# §9.8 and tf-guard.sh's header for the underlying real-import failure this
# responds to): the for_each gating the web service's load_balancer block
# used to be `var.target_group_arn == null ? [] : [1]`, and
# var.target_group_arn (module.alb.target_group_arn at the real call site)
# is unknown-until-apply for a not-yet-imported target group — comparing an
# unknown value to null produces an unknown boolean, which collapses a
# for_each's key set to unknown. See
# infrastructure/ecs/modules/cloudwatch_alarms/tests/service_alarm_keys.tftest.hcl
# for why mock_provider cannot reproduce that exact failure directly; these
# tests instead prove the fix's structural property (the dynamic block's
# inclusion now depends only on the literal var.attach_target_group
# boolean) via its observable side effect (health_check_grace_period_seconds).
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
}

run "attach_target_group_defaults_to_false_no_grace_period" {
  command = plan

  assert {
    condition     = aws_ecs_service.this[0].health_check_grace_period_seconds == null
    error_message = "Without attach_target_group set, health_check_grace_period_seconds must remain null — original 'no load balancer' behavior must be unchanged."
  }
}

run "attach_target_group_true_sets_the_grace_period" {
  command = plan

  variables {
    attach_target_group = true
    target_group_arn    = "arn:aws:elasticloadbalancing:us-east-1:603013471426:targetgroup/firmsbase-staging-tg/1830c01b9aaac37d"
    container_port      = 8080
  }

  assert {
    condition     = aws_ecs_service.this[0].health_check_grace_period_seconds == 60
    error_message = "With attach_target_group=true, health_check_grace_period_seconds must be 60 — proving the load_balancer dynamic block's gate (the same boolean) is correctly wired to true."
  }
}
