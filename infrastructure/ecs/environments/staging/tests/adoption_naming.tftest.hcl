# Proves the live-infrastructure-adoption naming/config overrides added in
# response to the audit (see docs/ecs/state-adoption-plan.md §3B, §5, §9)
# actually resolve the way the plan document claims — both the "override
# supplied" and "fall back to original behavior" paths — without touching
# real AWS. `mock_provider` replaces the aws/null providers entirely for
# this run: no credentials, no network calls, no state.
#
# module.networking and module.iam are stubbed via override_module: this
# file isn't testing their internals (networking is a data-source-only
# module with a plan-time precondition that a blanket mock can't satisfy;
# iam's assume-role/inline-policy data sources need real JSON, which a
# blanket mock also can't produce). iam's own naming-override logic gets
# its own narrower test file: modules/iam/tests/naming.tftest.hcl.
#
# Run with: terraform test (from infrastructure/ecs/environments/staging)

mock_provider "aws" {}
mock_provider "null" {}

override_module {
  target = module.networking
}

override_module {
  target = module.iam
  outputs = {
    task_execution_role_arn = "arn:aws:iam::603013471426:role/mock-task-execution"
    task_role_arns = {
      web             = "arn:aws:iam::603013471426:role/mock-task-web"
      worker          = "arn:aws:iam::603013471426:role/mock-task-worker"
      critical_worker = "arn:aws:iam::603013471426:role/mock-task-critical-worker"
      scheduler       = "arn:aws:iam::603013471426:role/mock-task-scheduler"
      migrate         = "arn:aws:iam::603013471426:role/mock-task-migrate"
      maintenance     = "arn:aws:iam::603013471426:role/mock-task-maintenance"
      ses_consumer    = "arn:aws:iam::603013471426:role/mock-task-ses-consumer"
    }
  }
}

variables {
  name_prefix                                                      = "firmsbase-staging"
  vpc_id                                                           = "vpc-0fd81b688155ded2b"
  public_subnet_ids                                                = ["subnet-aaaa1111", "subnet-aaaa2222"]
  private_subnet_ids                                               = ["subnet-bbbb1111", "subnet-bbbb2222"]
  alb_ingress_cidr_blocks                                          = ["0.0.0.0/0"]
  acm_certificate_arn                                              = "arn:aws:acm:us-east-1:603013471426:certificate/test-cert"
  app_url                                                          = "https://staging.firmsvault.com"
  rds_instance_id                                                  = "firmsbase-staging-db"
  rds_security_group_id                                            = "sg-0d4c5eedb2ee21743"
  db_host                                                          = "firmsbase-staging-db.example.rds.amazonaws.com"
  app_image_digest                                                 = "603013471426.dkr.ecr.us-east-1.amazonaws.com/firmsbase-staging@sha256:0000000000000000000000000000000000000000000000000000000000000000"
  app_key_secret_arn                                               = "arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/app-key-QigVGy"
  db_password_secret_arn                                           = "arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/database-app-8NUj2a"
  redis_auth_token_secret_arn                                      = "arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/redis-auth-token-p6rVKN"
  redis_auth_token                                                 = "test-auth-token-not-real"
  alarm_sns_topic_arn                                              = "arn:aws:sns:us-east-1:603013471426:firmsbase-staging-alarms"
  ses_events_queue_url                                             = "https://sqs.us-east-1.amazonaws.com/603013471426/firmsvault-staging-ses-events"
  ses_events_queue_arn                                             = "arn:aws:sqs:us-east-1:603013471426:firmsvault-staging-ses-events"
  ses_events_dlq_arn                                               = "arn:aws:sqs:us-east-1:603013471426:firmsvault-staging-ses-events-dlq"
  ses_sending_identity_arn                                         = "arn:aws:ses:us-east-1:603013471426:identity/staging-mail.firmsvault.com"
  ses_authorized_from_address                                      = "no-reply@staging-mail.firmsvault.com"
  platform_notifications_recipient_fingerprint_hmac_key_secret_arn = "arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/hmac-key-AbCdEf"
  # iam_task_execution_policy_name has no default (see variables.tf) — every
  # caller must set it explicitly.
  iam_task_execution_policy_name      = "firmsbase-staging-task-execution"
  aws_account_id                      = "603013471426"
  iam_task_execution_role_description = "Execution role for FirmsBase staging ECS tasks"
}

# --- ECS cluster name ---------------------------------------------------

run "cluster_name_defaults_to_name_prefix_when_no_override" {
  command = plan

  assert {
    condition     = module.ecs_cluster.cluster_name == "firmsbase-staging"
    error_message = "Without ecs_cluster_name set, the cluster must fall back to name_prefix (\"firmsbase-staging\") — this is the original, pre-audit behavior and must not change for a brand-new environment."
  }
}

run "container_insights_defaults_to_enabled_for_a_new_environment" {
  command = plan

  assert {
    condition     = module.ecs_cluster.container_insights_enabled == true
    error_message = "Without ecs_container_insights_enabled set, the cluster must default to enabled — original module design, unaffected for a brand-new environment."
  }
}

run "container_insights_resolves_to_the_live_disabled_value_when_overridden" {
  command = plan

  variables {
    ecs_container_insights_enabled = false
  }

  assert {
    condition     = module.ecs_cluster.container_insights_enabled == false
    error_message = "With ecs_container_insights_enabled=false, the cluster must resolve to disabled — this is what makes the live cluster (confirmed containerInsights=disabled via aws ecs describe-clusters) importable without leaving a permanent drift that would silently enable it on the next apply."
  }
}

run "cluster_name_resolves_to_live_value_when_overridden" {
  command = plan

  variables {
    ecs_cluster_name = "firmsbase-staging-cluster"
  }

  assert {
    condition     = module.ecs_cluster.cluster_name == "firmsbase-staging-cluster"
    error_message = "With ecs_cluster_name set, the cluster must resolve to the exact override value — this is what makes the live cluster \"firmsbase-staging-cluster\" importable without a rename. See docs/ecs/state-adoption-plan.md §3B."
  }
}

# --- ECR repository name --------------------------------------------------

run "ecr_repository_name_defaults_to_original_hardcoded_value" {
  command = plan

  assert {
    condition     = module.ecr.repository_name == "firmsbase-app"
    error_message = "Without ecr_repository_name set, the repository must fall back to the original hardcoded \"firmsbase-app\" — original behavior must not change for a brand-new environment."
  }
}

run "ecr_repository_name_resolves_to_live_value_when_overridden" {
  command = plan

  variables {
    ecr_repository_name = "firmsbase-staging"
  }

  assert {
    condition     = module.ecr.repository_name == "firmsbase-staging"
    error_message = "With ecr_repository_name set, the repository must resolve to the exact live name \"firmsbase-staging\" — this is what makes the live ECR repository importable without a destructive rename. See docs/ecs/state-adoption-plan.md §3B."
  }
}

# --- ElastiCache subnet group / engine -----------------------------------

run "elasticache_defaults_match_original_module_behavior" {
  command = plan

  assert {
    condition     = module.elasticache.subnet_group_name == "firmsbase-staging-redis"
    error_message = "Without elasticache_subnet_group_name set, must fall back to \"<name_prefix>-redis\" — original behavior."
  }

  assert {
    condition     = module.elasticache.engine == "redis"
    error_message = "Without elasticache_engine set, must default to \"redis\" — original behavior."
  }
}

run "elasticache_resolves_to_live_valkey_config_when_overridden" {
  command = plan

  variables {
    elasticache_subnet_group_name    = "firmsbase-staging-cache-subnets"
    elasticache_engine               = "valkey"
    elasticache_parameter_group_name = "default.valkey7"
  }

  assert {
    condition     = module.elasticache.subnet_group_name == "firmsbase-staging-cache-subnets"
    error_message = "Must resolve to the exact live subnet group name — this is what makes it importable. See docs/ecs/state-adoption-plan.md §3B."
  }

  assert {
    condition     = module.elasticache.engine == "valkey"
    error_message = "Must resolve to \"valkey\" — the live replication group's actual engine (confirmed via aws elasticache describe-replication-groups). Applying with engine=\"redis\" against it would plan a full, data-losing replacement."
  }
}

# --- Public-IP / NAT-egress invariant -------------------------------------

run "public_ip_stays_enabled_by_default_no_nat_gateway_exists" {
  command = plan

  assert {
    condition     = module.web.assign_public_ip == true
    error_message = "Default (private_egress_ready=false) must force assign_public_ip=true for every service — this staging VPC has no NAT gateway, so this is the only way tasks reach the internet at all. See docs/ecs/state-adoption-plan.md §9.1."
  }

  assert {
    condition     = module.ses_consumer.assign_public_ip == true
    error_message = "Same invariant must hold for every service module, not just web."
  }
}

run "public_ip_can_be_disabled_only_with_nat_gateway_ids_supplied" {
  command = plan

  variables {
    private_egress_ready = true
    nat_gateway_ids      = ["nat-0123456789abcdef0"]
  }

  assert {
    condition     = module.web.assign_public_ip == false
    error_message = "private_egress_ready=true with nat_gateway_ids supplied must disable assign_public_ip."
  }
}

run "private_egress_ready_without_nat_gateway_ids_fails_validation" {
  command = plan

  variables {
    private_egress_ready = true
    # nat_gateway_ids intentionally left at its default ([]) — this must
    # be rejected by nat_gateway_ids' own cross-variable validation block,
    # not silently accepted.
  }

  expect_failures = [
    var.nat_gateway_ids,
  ]
}

# --- ALB target-group health-check adoption (path/interval/matcher) ------
#
# Terraform's test framework only exposes a called module's declared
# outputs to `assert` conditions in the root run block, not its nested
# resources (module.alb.aws_lb_target_group.web is not a valid expression
# here even though it's a valid state/CLI address) — so these two runs
# only prove staging can plan cleanly with each variable set. The actual
# value-wiring proof is split across two other, more targeted tests: the
# module's own tests/health_check_overrides.tftest.hcl (infrastructure/ecs
# /modules/alb) proves the module resource honors health_check_matcher (and
# by the same var.* pattern, path/interval), and
# tests/Feature/Ecs/AlbTargetGroupAdoptionTest.php proves this file's
# module "alb" call actually passes var.alb_health_check_* through by
# reading the real committed HCL.

run "alb_health_check_defaults_plan_cleanly" {
  # No assert block: module.alb's outputs (e.g. target_group_arn) are
  # unknown at plan time under mock_provider (only module.networking and
  # module.iam are overridden with known values in this file) — this run
  # only needs to prove the plan itself succeeds with the default
  # (unset) health-check variables, which a failing `run` already checks
  # without any assertion.
  command = plan
}

run "alb_health_check_overrides_plan_cleanly" {
  command = plan

  variables {
    alb_health_check_path             = "/up"
    alb_health_check_interval_seconds = 30
    alb_health_check_matcher          = "200-399"
  }
}

# --- ElastiCache engine_version --------------------------------------------

run "elasticache_engine_version_defaults_to_original_module_value" {
  command = plan

  assert {
    condition     = module.elasticache.engine_version == "7.1"
    error_message = "Without elasticache_engine_version set, must default to \"7.1\" — the module's original Redis-line design, unaffected for a brand-new environment."
  }
}

run "elasticache_engine_version_resolves_to_live_valkey_value_when_overridden" {
  command = plan

  variables {
    elasticache_engine               = "valkey"
    elasticache_parameter_group_name = "default.valkey7"
    elasticache_engine_version       = "7.2"
  }

  assert {
    condition     = module.elasticache.engine_version == "7.2"
    error_message = "Must resolve to \"7.2\" (major.minor only) — the live replication group's exact reported version is 7.2.6 (aws elasticache describe-cache-clusters), but AWS's aws_elasticache_replication_group rejects a major.minor.patch value like \"7.2.6\" outright for Redis v6+/Valkey. See docs/ecs/state-adoption-plan.md §9.4."
  }
}

# --- Secret valueFrom JSON-key derivation (bare ARN in, selector derived) --
#
# local.shared_secrets is a plain string-interpolation local computed
# directly from input variables (not a resource/module attribute), so
# unlike module.alb's/module.elasticache's computed outputs, it is fully
# knowable at plan time without any provider involvement at all — this
# directly proves main.tf's real derivation logic, not just that a plan
# succeeds. See docs/ecs/staging-variable-inventory.md.

run "shared_secrets_derives_the_exact_live_json_key_selector_for_each_secret" {
  command = plan

  assert {
    condition     = local.shared_secrets.APP_KEY == "${var.app_key_secret_arn}:APP_KEY::"
    error_message = "APP_KEY's valueFrom must be the bare app_key_secret_arn with the exact \":APP_KEY::\" selector appended — this is the live secret's actual JSON key."
  }

  assert {
    condition     = local.shared_secrets.DB_PASSWORD == "${var.db_password_secret_arn}:password::"
    error_message = "DB_PASSWORD's valueFrom must be the bare db_password_secret_arn with the exact \":password::\" selector appended — this is the live secret's actual JSON key."
  }

  assert {
    condition     = local.shared_secrets.REDIS_PASSWORD == "${var.redis_auth_token_secret_arn}:REDIS_PASSWORD::"
    error_message = "REDIS_PASSWORD's valueFrom must be the bare redis_auth_token_secret_arn with the exact \":REDIS_PASSWORD::\" selector appended — this is the live secret's actual JSON key."
  }
}

# --- APP_URL (previously unmodeled, see docs/ecs/state-adoption-plan.md §9.8) ---

run "shared_environment_includes_app_url_from_the_variable" {
  command = plan

  assert {
    condition     = local.shared_environment.APP_URL == var.app_url
    error_message = "local.shared_environment.APP_URL must equal var.app_url exactly — every role consuming shared_environment (web, worker, critical-worker, scheduler, migrate, maintenance, ses-consumer) must receive it."
  }
}

run "app_url_resolves_to_the_confirmed_live_value_when_set_to_it" {
  command = plan

  variables {
    app_url = "https://staging.firmsvault.com"
  }

  assert {
    condition     = local.shared_environment.APP_URL == "https://staging.firmsvault.com"
    error_message = "With app_url set to the confirmed live value, shared_environment.APP_URL must resolve to it exactly."
  }
}

run "app_url_rejects_a_non_https_value" {
  command = plan

  variables {
    app_url = "http://staging.firmsvault.com"
  }

  expect_failures = [
    var.app_url,
  ]
}

run "app_url_rejects_a_trailing_slash" {
  command = plan

  variables {
    app_url = "https://staging.firmsvault.com/"
  }

  expect_failures = [
    var.app_url,
  ]
}
