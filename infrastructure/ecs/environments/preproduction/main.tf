# FirmsBase preproduction — the release-certification environment.
#
# This is a THIRD ECS cluster, not a third application build. It runs the exact
# immutable digest CI already certified; the only thing that differs from
# production is configuration supplied here — environment variables, secrets,
# network and sizing. Nothing about the image changes, and no ECR repository is
# created for this environment: it pulls the artifact from wherever CI
# published it, so the digest running here is the digest that will later be
# promoted to production unchanged.
#
#   development / WIP     -> firmsbase-staging-cluster
#   freeze + build ONCE   -> certified immutable digest
#   certify that digest   -> firmsbase-preprod-cluster   (this file)
#   promote same digest   -> firmsbase-production-cluster (Blue/Green, later)

locals {
  log_group_name = "/ecs/${var.name_prefix}/app"

  # Digest-pinned, never a tag. var.image_digest is validated to be
  # sha256:<64 hex>, so a tag or "latest" cannot reach this string.
  image = "${var.source_image_repository_url}@${var.image_digest}"

  common_environment = {
    # APP_ENV is "production", not "preproduction": the purpose of this
    # environment is to exercise the code paths production will take. Laravel
    # branches on this value for error rendering and driver selection, and
    # certifying under a different value would leave those paths unexercised.
    # config/hosts.php only falls back to *.test defaults under local/testing,
    # so strict TrustHosts stays strict here.
    APP_ENV          = "production"
    APP_DEBUG        = "false"
    APP_URL          = "https://app.${var.apex_hostname}"
    LOG_CHANNEL      = "stderr"
    CACHE_STORE      = "redis"
    SESSION_DRIVER   = "redis"
    QUEUE_CONNECTION = "redis"
    FILESYSTEM_DISK  = "s3"

    # All six canonical hosts, supplied as configuration. The certified web
    # entrypoint fails closed if MARKETING_URL is missing or is not a valid
    # hostname, and derives the ALB health-check Host rewrite from it — no
    # hostname is baked into the image.
    MARKETING_URL     = "https://${var.apex_hostname}"
    FIRM_APP_URL      = "https://app.${var.apex_hostname}"
    CLIENT_PORTAL_URL = "https://client.${var.apex_hostname}"
    ADMIN_URL         = "https://admin.${var.apex_hostname}"
    MYATTORNEY_URL    = "https://myattorney.${var.apex_hostname}"
    API_URL           = "https://api.${var.apex_hostname}"

    AWS_BUCKET         = module.s3_documents.bucket_name
    AWS_DEFAULT_REGION = var.aws_region
    REDIS_HOST         = module.elasticache.primary_endpoint_address
    REDIS_PORT         = tostring(module.elasticache.port)

    # SES stays in verified-recipient mode for this environment. Certification
    # exercises the real mail path, but preproduction must never generate
    # uncontrolled mail to real customers.
    MAIL_MAILER       = "ses"
    MAIL_FROM_ADDRESS = "no-reply@${var.apex_hostname}"
    MAIL_FROM_NAME    = "FirmsVault Preproduction"

    # Identical on every task so onOneServer()'s cache lock shares one
    # namespace — see ScheduledCommandSingleExecutionContract. Distinct from
    # every other environment's prefix so a scheduler lock can never be shared
    # across environments even if a Redis endpoint were ever misconfigured.
    APP_NAME     = "FirmsBase"
    CACHE_PREFIX = "firmsbase-preprod-cache-"
  }

  # Runtime roles receive the APP identity only. The migrator credential is
  # deliberately absent from this map.
  common_secrets = {
    APP_KEY                                               = aws_secretsmanager_secret.app_key.arn
    DB_HOST                                               = "${aws_secretsmanager_secret.database_app.arn}:host::"
    DB_PORT                                               = "${aws_secretsmanager_secret.database_app.arn}:port::"
    DB_DATABASE                                           = "${aws_secretsmanager_secret.database_app.arn}:dbname::"
    DB_USERNAME                                           = "${aws_secretsmanager_secret.database_app.arn}:username::"
    DB_PASSWORD                                           = "${aws_secretsmanager_secret.database_app.arn}:password::"
    REDIS_PASSWORD                                        = aws_secretsmanager_secret.redis_auth_token.arn
    PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY = aws_secretsmanager_secret.platform_notifications_hmac_key.arn
  }

  # The one-off migrate task is the ONLY thing that authenticates as the
  # migrator. Same shape, different database identity.
  migrator_secrets = merge(local.common_secrets, {
    DB_HOST     = "${aws_secretsmanager_secret.database_migrator.arn}:host::"
    DB_PORT     = "${aws_secretsmanager_secret.database_migrator.arn}:port::"
    DB_DATABASE = "${aws_secretsmanager_secret.database_migrator.arn}:dbname::"
    DB_USERNAME = "${aws_secretsmanager_secret.database_migrator.arn}:username::"
    DB_PASSWORD = "${aws_secretsmanager_secret.database_migrator.arn}:password::"
  })
}

module "kms" {
  source = "../../modules/kms"

  name_prefix                           = var.name_prefix
  aws_account_id                        = var.aws_account_id
  aws_region                            = var.aws_region
  cloudwatch_logs_log_group_arn_pattern = "arn:aws:logs:${var.aws_region}:${var.aws_account_id}:log-group:/ecs/${var.name_prefix}/*"
}

module "security_groups" {
  source = "../../modules/security_groups"

  name_prefix    = var.name_prefix
  vpc_id         = aws_vpc.main.id
  container_port = var.container_port

  # Internet-facing: certification drives the real hostnames over TLS, and an
  # IP allowlist would break the moment the acceptance suite runs from
  # anywhere else.
  alb_ingress_cidr_blocks = ["0.0.0.0/0"]
}

module "s3_documents" {
  source = "../../modules/s3_documents"

  bucket_name = "${var.name_prefix}-documents"
  kms_key_arn = module.kms.key_arn
}

module "elasticache" {
  source = "../../modules/elasticache"

  name_prefix                 = var.name_prefix
  vpc_id                      = aws_vpc.main.id
  subnet_ids                  = aws_subnet.private_data[*].id
  ecs_tasks_security_group_id = module.security_groups.ecs_tasks_security_group_id
  node_type                   = var.redis_node_type
  auth_token                  = random_password.redis_auth.result
}

module "ecs_cluster" {
  source = "../../modules/ecs_cluster"

  cluster_name               = "${var.name_prefix}-cluster"
  container_insights_enabled = true
}

# No permissions boundaries are passed. The three boundary policies are
# production-scoped (firmsbase-production-*) and must not be reused by another
# environment; preproduction boundaries would be a separate, owner-approved
# bootstrap. The module defaults to no boundary, so this is an explicit
# omission rather than an inherited one.
module "iam" {
  source = "../../modules/iam"

  name_prefix    = var.name_prefix
  aws_account_id = var.aws_account_id
  aws_region     = var.aws_region
  kms_key_arn    = module.kms.key_arn

  task_execution_role_description    = "ECS task execution role for FirmsBase preproduction"
  task_execution_policy_name         = "${var.name_prefix}-task-execution-secrets"
  task_execution_managed_policy_arn  = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
  task_execution_secrets_policy_sid  = "PreproductionTaskExecutionSecrets"
  task_execution_kms_decrypt_enabled = true
  kms_encryption_enabled             = true
  s3_documents_enabled               = true
  s3_documents_bucket_arn            = module.s3_documents.bucket_arn

  # Image pull from the source repository is granted by the attached AWS
  # managed policy AmazonECSTaskExecutionRolePolicy, which already covers
  # ecr:GetAuthorizationToken / BatchCheckLayerAvailability /
  # GetDownloadUrlForLayer / BatchGetImage. No ECR push is granted to any
  # preproduction runtime role.
  task_execution_secret_arns = [
    aws_secretsmanager_secret.app_key.arn,
    aws_secretsmanager_secret.database_app.arn,
    aws_secretsmanager_secret.database_migrator.arn,
    aws_secretsmanager_secret.redis_auth_token.arn,
    aws_secretsmanager_secret.platform_notifications_hmac_key.arn,
  ]
}

module "alb" {
  source = "../../modules/alb"

  name_prefix       = var.name_prefix
  vpc_id            = aws_vpc.main.id
  public_subnet_ids = aws_subnet.public[*].id
  security_group_id = module.security_groups.alb_security_group_id
  container_port    = var.container_port

  acm_certificate_arn = data.aws_acm_certificate.preprod.arn

  # /up, not the module's /readyz default. The certified image rewrites the
  # ALB health-check Host header for path /up ONLY, so a /readyz health check
  # arriving with a private-IP Host would be rejected by strict TrustHosts.
  readiness_health_check_path = "/up"
  health_check_matcher        = "200"

  # Ephemeral environment: the ALB must be destroyable.
  enable_deletion_protection = false
}

resource "aws_cloudwatch_log_group" "app" {
  name              = local.log_group_name
  retention_in_days = 30
  kms_key_id        = module.kms.key_arn
}

# --------------------------------------------------------------------------
# Long-running services.
#
# Deployment settings are production-like, NOT the 0/100 the hand-built staging
# environment uses. With minimum_healthy_percent = 100 and maximum_percent =
# 200, ECS starts replacement tasks, waits for them to pass the ALB health
# check, and only then drains the old ones — the same rolling model production
# Blue/Green will rely on. The circuit breaker rolls the deployment back
# automatically if the replacements never become healthy.
# --------------------------------------------------------------------------

module "web" {
  source = "../../modules/ecs_service"

  name               = "web"
  family             = "${var.name_prefix}-web"
  image              = local.image
  command            = ["web"]
  cpu                = 1024
  memory             = 2048
  container_port     = var.container_port
  execution_role_arn = module.iam.task_execution_role_arn
  task_role_arn      = module.iam.task_role_arns["web"]
  environment        = local.common_environment
  secrets            = local.common_secrets
  log_group_name     = aws_cloudwatch_log_group.app.name
  aws_region         = var.aws_region

  create_service                     = true
  desired_count                      = var.web_desired_count
  cluster_id                         = module.ecs_cluster.cluster_id
  subnet_ids                         = aws_subnet.private_app[*].id
  security_group_ids                 = [module.security_groups.ecs_tasks_security_group_id]
  assign_public_ip                   = false
  target_group_arn                   = module.alb.target_group_arn
  attach_target_group                = true
  stop_timeout_seconds               = 90
  use_capacity_provider_strategy     = false
  deployment_minimum_healthy_percent = 100
  deployment_maximum_percent         = 200
  enable_deployment_circuit_breaker  = true

  # 90s rather than the module's 60s default: /up traverses Laravel rather than
  # being answered synthetically by Caddy, so a cold task needs longer before
  # its first successful health check.
  health_check_grace_period_seconds = 90
}

module "worker" {
  source = "../../modules/ecs_service"

  name               = "worker"
  family             = "${var.name_prefix}-worker"
  image              = local.image
  command            = ["worker"]
  cpu                = 512
  memory             = 1024
  container_port     = var.container_port
  execution_role_arn = module.iam.task_execution_role_arn
  task_role_arn      = module.iam.task_role_arns["worker"]
  environment        = merge(local.common_environment, { WORKER_QUEUES = "default,documents,notifications,integrations,billing,low-priority" })
  secrets            = local.common_secrets
  log_group_name     = aws_cloudwatch_log_group.app.name
  aws_region         = var.aws_region

  create_service                     = true
  desired_count                      = 1
  cluster_id                         = module.ecs_cluster.cluster_id
  subnet_ids                         = aws_subnet.private_app[*].id
  security_group_ids                 = [module.security_groups.ecs_tasks_security_group_id]
  assign_public_ip                   = false
  attach_target_group                = false
  stop_timeout_seconds               = 120
  use_capacity_provider_strategy     = false
  deployment_minimum_healthy_percent = 100
  deployment_maximum_percent         = 200
  enable_deployment_circuit_breaker  = true
}

module "critical_worker" {
  source = "../../modules/ecs_service"

  name               = "critical-worker"
  family             = "${var.name_prefix}-critical-worker"
  image              = local.image
  command            = ["worker"]
  cpu                = 512
  memory             = 1024
  container_port     = var.container_port
  execution_role_arn = module.iam.task_execution_role_arn
  task_role_arn      = module.iam.task_role_arns["critical_worker"]
  environment        = merge(local.common_environment, { WORKER_QUEUES = "trust" })
  secrets            = local.common_secrets
  log_group_name     = aws_cloudwatch_log_group.app.name
  aws_region         = var.aws_region

  create_service                     = true
  desired_count                      = 1
  cluster_id                         = module.ecs_cluster.cluster_id
  subnet_ids                         = aws_subnet.private_app[*].id
  security_group_ids                 = [module.security_groups.ecs_tasks_security_group_id]
  assign_public_ip                   = false
  attach_target_group                = false
  stop_timeout_seconds               = 120
  use_capacity_provider_strategy     = false
  deployment_minimum_healthy_percent = 100
  deployment_maximum_percent         = 200
  enable_deployment_circuit_breaker  = true
}

# Exactly one, always. Duplicate schedulers double every scheduled sweep, and
# five of them have no atomic duplicate protection — see
# ScheduledCommandSingleExecutionContract. Certification asserts
# runningCount == 1.
module "scheduler" {
  source = "../../modules/ecs_service"

  name               = "scheduler"
  family             = "${var.name_prefix}-scheduler"
  image              = local.image
  command            = ["scheduler"]
  cpu                = 256
  memory             = 512
  container_port     = var.container_port
  execution_role_arn = module.iam.task_execution_role_arn
  task_role_arn      = module.iam.task_role_arns["scheduler"]
  environment        = local.common_environment
  secrets            = local.common_secrets
  log_group_name     = aws_cloudwatch_log_group.app.name
  aws_region         = var.aws_region

  create_service                     = true
  desired_count                      = 1
  cluster_id                         = module.ecs_cluster.cluster_id
  subnet_ids                         = aws_subnet.private_app[*].id
  security_group_ids                 = [module.security_groups.ecs_tasks_security_group_id]
  assign_public_ip                   = false
  attach_target_group                = false
  stop_timeout_seconds               = 120
  use_capacity_provider_strategy     = false
  deployment_minimum_healthy_percent = 100
  deployment_maximum_percent         = 200
  enable_deployment_circuit_breaker  = true
}

# --------------------------------------------------------------------------
# One-off task definitions. create_service = false: these are registered and
# invoked with ecs run-task, never run as services.
#
# ses-consumer is deliberately NOT created in this environment.
# --------------------------------------------------------------------------

# The ONLY task that authenticates as firmsbase_migrator. Run exactly once per
# certification cycle against an empty database; the certified release's own
# migration set is authoritative and nothing is ever copied from staging.
module "migrate" {
  source = "../../modules/ecs_service"

  name               = "migrate"
  family             = "${var.name_prefix}-migrate"
  image              = local.image
  command            = ["migrate"]
  cpu                = 512
  memory             = 1024
  container_port     = var.container_port
  execution_role_arn = module.iam.task_execution_role_arn
  task_role_arn      = module.iam.task_role_arns["migrate"]
  environment        = local.common_environment
  secrets            = local.migrator_secrets
  log_group_name     = aws_cloudwatch_log_group.app.name
  aws_region         = var.aws_region

  create_service = false

  # Required by the module even with create_service = false. They describe how
  # `ecs run-task` should place the task, not a service: the same private-app
  # subnets and task security group every long-running role uses.
  cluster_id                     = module.ecs_cluster.cluster_id
  subnet_ids                     = aws_subnet.private_app[*].id
  security_group_ids             = [module.security_groups.ecs_tasks_security_group_id]
  assign_public_ip               = false
  attach_target_group            = false
  stop_timeout_seconds           = 120
  use_capacity_provider_strategy = false
}

module "maintenance" {
  source = "../../modules/ecs_service"

  name               = "maintenance"
  family             = "${var.name_prefix}-maintenance"
  image              = local.image
  command            = ["maintenance", "list"]
  cpu                = 512
  memory             = 1024
  container_port     = var.container_port
  execution_role_arn = module.iam.task_execution_role_arn
  task_role_arn      = module.iam.task_role_arns["maintenance"]
  environment        = local.common_environment
  secrets            = local.common_secrets
  log_group_name     = aws_cloudwatch_log_group.app.name
  aws_region         = var.aws_region

  create_service = false

  # Required by the module even with create_service = false. They describe how
  # `ecs run-task` should place the task, not a service: the same private-app
  # subnets and task security group every long-running role uses.
  cluster_id                     = module.ecs_cluster.cluster_id
  subnet_ids                     = aws_subnet.private_app[*].id
  security_group_ids             = [module.security_groups.ecs_tasks_security_group_id]
  assign_public_ip               = false
  attach_target_group            = false
  stop_timeout_seconds           = 120
  use_capacity_provider_strategy = false
}
