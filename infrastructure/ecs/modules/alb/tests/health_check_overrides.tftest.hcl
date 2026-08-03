# Proves the ALB target-group health-check adoption overrides added in
# response to the corrected audit (see docs/ecs/state-adoption-plan.md
# §9.5) actually resolve the way the plan document claims — both the
# "override supplied" (live-compatible) and "fall back to original
# design" paths — without touching real AWS.
#
# Run with: terraform test (from infrastructure/ecs/modules/alb)

mock_provider "aws" {}

variables {
  name_prefix         = "firmsbase-staging"
  container_port      = 8080
  vpc_id              = "vpc-0fd81b688155ded2b"
  public_subnet_ids   = ["subnet-aaaa1111", "subnet-aaaa2222"]
  security_group_id   = "sg-02a26ff122a9a1d29"
  acm_certificate_arn = "arn:aws:acm:us-east-1:603013471426:certificate/test-cert"
}

run "matcher_defaults_to_the_original_exact_200_design" {
  command = plan

  assert {
    condition     = aws_lb_target_group.web.health_check[0].matcher == "200"
    error_message = "Without health_check_matcher set, the matcher must fall back to the original exact \"200\" design — this is what a brand-new environment must keep getting."
  }
}

run "matcher_resolves_to_the_live_compatible_range_when_overridden" {
  command = plan

  variables {
    health_check_matcher = "200-399"
  }

  assert {
    condition     = aws_lb_target_group.web.health_check[0].matcher == "200-399"
    error_message = "With health_check_matcher set, the matcher must resolve to the exact override value — this is what makes a live target group with a wider matcher importable without silently narrowing it on the next apply. See docs/ecs/state-adoption-plan.md §9.5."
  }
}

run "matcher_rejects_a_malformed_value" {
  command = plan

  variables {
    health_check_matcher = "not-a-status-code"
  }

  expect_failures = [
    var.health_check_matcher,
  ]
}

run "other_health_check_fields_still_default_to_original_values" {
  command = plan

  assert {
    condition     = aws_lb_target_group.web.health_check[0].path == "/readyz"
    error_message = "readiness_health_check_path must still default to /readyz when not overridden."
  }

  assert {
    condition     = aws_lb_target_group.web.health_check[0].interval == 15
    error_message = "health_check_interval_seconds must still default to 15 when not overridden."
  }
}
