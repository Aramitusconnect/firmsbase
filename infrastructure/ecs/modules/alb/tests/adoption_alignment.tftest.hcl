# Proves the ALB/target-group adoption-alignment correction (see
# docs/ecs/state-adoption-plan.md §9.26 [ALB/ECR/cluster/service wave]):
# alb_name/target_group_name model the exact live ALB and target group
# instead of forcing their replacement — the identical, evidence-proven
# pattern already applied to the security-group modules.
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

run "alb_defaults_to_name_prefix_when_not_overridden" {
  command = plan

  assert {
    condition     = aws_lb.this.name_prefix == "firmsb"
    error_message = "Without alb_name set, name_prefix must resolve to the module's original 6-char-prefix pattern."
  }
}

run "alb_models_the_exact_live_name_when_overridden" {
  command = plan

  variables {
    alb_name = "firmsbase-staging-alb"
  }

  assert {
    condition     = aws_lb.this.name == "firmsbase-staging-alb"
    error_message = "alb_name must resolve to the exact live name."
  }
}

run "target_group_defaults_to_name_prefix_when_not_overridden" {
  command = plan

  assert {
    condition     = aws_lb_target_group.web.name_prefix == "firmsb"
    error_message = "Without target_group_name set, name_prefix must resolve to the module's original 6-char-prefix pattern."
  }
}

run "target_group_models_the_exact_live_name_when_overridden" {
  command = plan

  variables {
    target_group_name = "firmsbase-staging-tg"
  }

  assert {
    condition     = aws_lb_target_group.web.name == "firmsbase-staging-tg"
    error_message = "target_group_name must resolve to the exact live name."
  }
}

run "alb_and_target_group_identity_and_listeners_unaffected_by_the_overrides" {
  command = plan

  variables {
    alb_name          = "firmsbase-staging-alb"
    target_group_name = "firmsbase-staging-tg"
  }

  assert {
    condition     = aws_lb.this.load_balancer_type == "application"
    error_message = "load_balancer_type must be unaffected by the name overrides."
  }

  assert {
    condition     = aws_lb_target_group.web.port == 8080
    error_message = "target group port must be unaffected by the name overrides."
  }

  assert {
    condition     = aws_lb_listener.https.port == 443
    error_message = "The HTTPS listener must be unaffected by the name overrides."
  }

  assert {
    condition     = aws_lb_listener.http_redirect.port == 80
    error_message = "The HTTP-redirect listener must be unaffected by the name overrides."
  }
}
