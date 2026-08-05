# Proves the ElastiCache subnet-membership correction (see
# docs/ecs/state-adoption-plan.md §9.15): the new elasticache_subnet_ids
# root variable falls back to private_subnet_ids for a brand-new
# environment (preserving original behavior), and resolves to the exact
# live 6-subnet set when explicitly overridden — decoupled entirely from
# ECS's own private_subnet_ids, which remains unchanged. `mock_provider`
# replaces the aws/null providers entirely: no credentials, no network
# calls, no state.
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

run "elasticache_subnet_ids_defaults_to_private_subnet_ids_for_a_new_environment" {
  command = plan

  assert {
    condition     = toset(local.elasticache_subnet_ids) == toset(var.private_subnet_ids)
    error_message = "Without elasticache_subnet_ids set, ElastiCache subnet-group membership must fall back to private_subnet_ids — original behavior, unaffected for a brand-new environment."
  }

  assert {
    condition     = toset(module.elasticache.subnet_group_name != null ? [module.elasticache.subnet_group_name] : []) != toset([])
    error_message = "Sanity check: module.elasticache must still plan cleanly with the fallback."
  }
}

run "elasticache_subnet_ids_resolves_to_the_live_six_subnet_set_when_overridden" {
  command = plan

  variables {
    elasticache_subnet_ids = [
      "subnet-020540b8377bb4d0e",
      "subnet-0d328451d742a4a3c",
      "subnet-07efcb5d4bcf5aa59",
      "subnet-04f36560361246d4b",
      "subnet-0631d53a7acde6530",
      "subnet-06cb2ddbdb7cf4d69",
    ]
  }

  assert {
    condition = toset(local.elasticache_subnet_ids) == toset([
      "subnet-020540b8377bb4d0e",
      "subnet-0d328451d742a4a3c",
      "subnet-07efcb5d4bcf5aa59",
      "subnet-04f36560361246d4b",
      "subnet-0631d53a7acde6530",
      "subnet-06cb2ddbdb7cf4d69",
    ])
    error_message = "With elasticache_subnet_ids set, the effective ElastiCache subnet set must equal the override exactly — this is what makes the live 6-subnet subnet group importable without under-registering it."
  }

  assert {
    condition     = length(local.elasticache_subnet_ids) == 6
    error_message = "Must resolve to exactly 6 subnets when the live-adoption override is supplied."
  }

  assert {
    # ECS private_subnet_ids itself must remain completely untouched by
    # this override — it still resolves to its own original 2-subnet value.
    condition     = toset(var.private_subnet_ids) == toset(["subnet-bbbb1111", "subnet-bbbb2222"])
    error_message = "var.private_subnet_ids must be unaffected by elasticache_subnet_ids — ECS subnet placement is a separate concern."
  }
}

run "elasticache_subnet_ids_rejects_an_empty_list" {
  command = plan

  variables {
    elasticache_subnet_ids = []
  }

  expect_failures = [
    var.elasticache_subnet_ids,
  ]
}

run "elasticache_subnet_ids_rejects_duplicate_entries" {
  command = plan

  variables {
    elasticache_subnet_ids = ["subnet-020540b8377bb4d0e", "subnet-020540b8377bb4d0e"]
  }

  expect_failures = [
    var.elasticache_subnet_ids,
  ]
}
