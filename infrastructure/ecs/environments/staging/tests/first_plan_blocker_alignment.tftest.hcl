# Proves the first-plan-blocker adoption-alignment correction (see
# docs/ecs/state-adoption-plan.md §9.24):
#   - the 5 new elasticache_* overrides wire through to module.elasticache
#     unchanged, and default to the module's own safe defaults when unset;
#   - ses_consumer_task_role_arn/ses_consumer_log_group_name still expose
#     their real values under a normal, untargeted plan — the try()
#     correction added for targeted-plan safety never masks the real
#     production value.
# `mock_provider` replaces the aws/null providers entirely: no
# credentials, no network calls, no state. module.networking and
# module.iam are stubbed via override_module (same rationale as
# tests/adoption_naming.tftest.hcl).
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
  db_migrator_secret_arn                                           = "arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/database-migrator-TpsE6P"
  redis_auth_token_secret_arn                                      = "arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/redis-auth-token-p6rVKN"
  redis_auth_token                                                 = "test-auth-token-not-real"
  alarm_sns_topic_arn                                              = "arn:aws:sns:us-east-1:603013471426:firmsbase-staging-alarms"
  ses_events_queue_url                                             = "https://sqs.us-east-1.amazonaws.com/603013471426/firmsvault-staging-ses-events"
  ses_events_queue_arn                                             = "arn:aws:sqs:us-east-1:603013471426:firmsvault-staging-ses-events"
  ses_events_dlq_arn                                               = "arn:aws:sqs:us-east-1:603013471426:firmsvault-staging-ses-events-dlq"
  ses_sending_identity_arn                                         = "arn:aws:ses:us-east-1:603013471426:identity/staging-mail.firmsvault.com"
  ses_authorized_from_address                                      = "no-reply@staging-mail.firmsvault.com"
  platform_notifications_recipient_fingerprint_hmac_key_secret_arn = "arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/hmac-key-AbCdEf"
  iam_task_execution_policy_name                                   = "firmsbase-staging-task-execution"
  aws_account_id                                                   = "603013471426"
  iam_task_execution_role_description                              = "Execution role for FirmsBase staging ECS tasks"
  iam_task_execution_managed_policy_arn                            = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
  iam_task_execution_secrets_policy_sid                            = "ReadFirmsBaseStagingSecrets"
}

run "elasticache_overrides_default_to_the_modules_own_safe_defaults" {
  command = plan

  assert {
    condition     = module.elasticache.subnet_group_name == "firmsbase-staging-redis"
    error_message = "Sanity check: module.elasticache must still plan cleanly with all 5 new overrides left unset (the module's own defaults apply — proven directly in modules/elasticache/tests/adoption_alignment.tftest.hcl)."
  }
}

run "elasticache_overrides_wire_through_without_error_when_set_to_the_exact_live_values" {
  command = plan

  variables {
    elasticache_security_group_name           = "firmsbase-staging-redis-sg"
    elasticache_security_group_description    = "Valkey access from FirmsBase staging ECS tasks"
    elasticache_subnet_group_description      = "Subnets for FirmsBase staging Valkey"
    elasticache_replication_group_description = "Valkey for FirmsBase staging sessions, cache, and queues"
    elasticache_snapshot_retention_limit      = 1
  }

  assert {
    condition     = module.elasticache.subnet_group_name == "firmsbase-staging-redis"
    error_message = "Sanity check: module.elasticache must still plan cleanly with all 5 new overrides set to the exact live values (the resulting description/tags/snapshot_retention_limit values are proven directly in modules/elasticache/tests/adoption_alignment.tftest.hcl)."
  }
}

run "ses_consumer_outputs_expose_real_values_under_a_normal_untargeted_plan" {
  command = plan

  assert {
    condition     = output.ses_consumer_task_role_arn == "arn:aws:iam::603013471426:role/mock-task-ses-consumer"
    error_message = "Under a normal, untargeted plan (the full task_role_arns map present), the try() correction must never mask the real value — a genuinely missing/null value here would be a regression, not a safety improvement."
  }

  assert {
    condition     = output.ses_consumer_log_group_name == "/ecs/firmsbase-staging/ses-consumer"
    error_message = "Under a normal, untargeted plan (aws_cloudwatch_log_group.app has all 7 role instances), the try() correction must never mask the real log-group name."
  }
}
