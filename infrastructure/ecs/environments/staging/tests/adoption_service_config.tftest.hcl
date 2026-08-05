# Proves the Phase A3 ECS-service adoption corrections (see
# docs/ecs/state-adoption-plan.md §9.10/§9.11): every service caller
# supplies the new required use_capacity_provider_strategy boolean
# (matching live's launch_type=FARGATE, no capacity-provider association),
# and the four new desired_count variables both preserve their original
# new-environment defaults and resolve to the live-adoption override
# values when set. `mock_provider` replaces the aws/null providers
# entirely: no credentials, no network calls, no state.
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

# --- use_capacity_provider_strategy: every caller supplies it, all false ---

run "every_service_caller_uses_launch_type_fargate_not_capacity_provider_strategy" {
  command = plan

  assert {
    condition     = module.web.use_capacity_provider_strategy == false
    error_message = "module.web must set use_capacity_provider_strategy=false — live staging services run launch_type=FARGATE, not a capacity-provider strategy."
  }

  assert {
    condition     = module.worker.use_capacity_provider_strategy == false
    error_message = "module.worker must set use_capacity_provider_strategy=false."
  }

  assert {
    condition     = module.critical_worker.use_capacity_provider_strategy == false
    error_message = "module.critical_worker must set use_capacity_provider_strategy=false."
  }

  assert {
    condition     = module.scheduler.use_capacity_provider_strategy == false
    error_message = "module.scheduler must set use_capacity_provider_strategy=false."
  }

  assert {
    condition     = module.migrate.use_capacity_provider_strategy == false
    error_message = "module.migrate must set use_capacity_provider_strategy=false (even though create_service=false, the module requires it regardless)."
  }

  assert {
    condition     = module.maintenance.use_capacity_provider_strategy == false
    error_message = "module.maintenance must set use_capacity_provider_strategy=false."
  }

  assert {
    condition     = module.ses_consumer.use_capacity_provider_strategy == false
    error_message = "module.ses_consumer must set use_capacity_provider_strategy=false."
  }
}

# --- desired_count: defaults preserve original design intent -------------

run "desired_count_defaults_match_original_design" {
  command = plan

  assert {
    condition     = module.web.desired_count == 2
    error_message = "Without web_desired_count set, web must default to 2 — this module's original design intent for a load-balanced HTTP service, unaffected for a brand-new environment."
  }

  assert {
    condition     = module.worker.desired_count == 2
    error_message = "Without worker_desired_count set, worker must default to 2 — original design intent."
  }

  assert {
    condition     = module.critical_worker.desired_count == 1
    error_message = "Without critical_worker_desired_count set, critical_worker must default to 1 — original design intent (fixed capacity, never scaled to zero)."
  }

  assert {
    condition     = module.scheduler.desired_count == 1
    error_message = "Without scheduler_desired_count set, scheduler must default to 1 — original design intent (single instance)."
  }
}

# --- desired_count: live-adoption overrides resolve exactly --------------

run "desired_count_resolves_to_live_adoption_values_when_overridden" {
  command = plan

  variables {
    web_desired_count             = 1
    worker_desired_count          = 1
    critical_worker_desired_count = 1
    scheduler_desired_count       = 1
  }

  assert {
    condition     = module.web.desired_count == 1
    error_message = "With web_desired_count=1, web must resolve to 1 — this staging environment's live service currently runs 1 task (confirmed via aws ecs describe-services), not the module's 2-task default."
  }

  assert {
    condition     = module.worker.desired_count == 1
    error_message = "With worker_desired_count=1, worker must resolve to 1 — matching live."
  }

  assert {
    condition     = module.critical_worker.desired_count == 1
    error_message = "critical_worker must resolve to 1 — matching live (no drift from its own default here, but the variable must still flow through)."
  }

  assert {
    condition     = module.scheduler.desired_count == 1
    error_message = "scheduler must resolve to 1 — matching live (no drift from its own default here, but the variable must still flow through)."
  }
}
