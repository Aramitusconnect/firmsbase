# FirmsBase production application stack.
#
# Composes the same modules staging proved, instantiated as distinct production
# resources. Two things are authored locally rather than by module because no
# module covers them: the VPC (modules/networking only consumes one) and RDS
# (no module exists) — see network.tf and database.tf.

locals {
  log_group_name = "/ecs/${var.name_prefix}/app"
  image          = "${module.ecr.repository_url}@${var.image_digest}"

  # Pre-existing boundary policies, created during the IAM bootstrap before
  # this stack was ever planned. Referenced by ARN rather than by data source
  # on purpose: a data lookup would make the boundary unknown at plan time,
  # and the whole point of these is that the reviewer can see the exact
  # ceiling each role gets in the plan output.
  execution_permissions_boundary_arn = "arn:aws:iam::${var.aws_account_id}:policy/${var.name_prefix}-execution-boundary"
  migrator_permissions_boundary_arn  = "arn:aws:iam::${var.aws_account_id}:policy/${var.name_prefix}-migrator-boundary"
  task_permissions_boundary_arn      = "arn:aws:iam::${var.aws_account_id}:policy/${var.name_prefix}-task-boundary"

  common_environment = {
    APP_ENV          = "production"
    APP_DEBUG        = "false"
    APP_URL          = "https://app.firmsvault.com"
    LOG_CHANNEL      = "stderr"
    CACHE_STORE      = "redis"
    SESSION_DRIVER   = "redis"
    QUEUE_CONNECTION = "redis"
    FILESYSTEM_DISK  = "s3"

    MARKETING_URL     = "https://firmsvault.com"
    FIRM_APP_URL      = "https://app.firmsvault.com"
    CLIENT_PORTAL_URL = "https://client.firmsvault.com"
    ADMIN_URL         = "https://admin.firmsvault.com"
    MYATTORNEY_URL    = "https://myattorney.firmsvault.com"

    AWS_BUCKET         = module.s3_documents.bucket_name
    AWS_DEFAULT_REGION = var.aws_region
    REDIS_HOST         = module.elasticache.primary_endpoint_address
    REDIS_PORT         = tostring(module.elasticache.port)

    MAIL_MAILER       = "ses"
    MAIL_FROM_ADDRESS = "no-reply@firmsvault.com"
    MAIL_FROM_NAME    = "FirmsVault"

    # Identical on every task so onOneServer()'s cache lock shares one
    # namespace — see ScheduledCommandSingleExecutionContract.
    APP_NAME     = "FirmsBase"
    CACHE_PREFIX = "firmsbase-production-cache-"
  }

  common_secrets = {
    APP_KEY                                               = aws_secretsmanager_secret.app_key.arn
    DB_HOST                                               = "${aws_secretsmanager_secret.db_app.arn}:host::"
    DB_PORT                                               = "${aws_secretsmanager_secret.db_app.arn}:port::"
    DB_DATABASE                                           = "${aws_secretsmanager_secret.db_app.arn}:dbname::"
    DB_USERNAME                                           = "${aws_secretsmanager_secret.db_app.arn}:username::"
    DB_PASSWORD                                           = "${aws_secretsmanager_secret.db_app.arn}:password::"
    REDIS_PASSWORD                                        = aws_secretsmanager_secret.redis_auth.arn
    PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY = aws_secretsmanager_secret.notification_hmac.arn
  }
}

module "kms" {
  source = "../../modules/kms"

  name_prefix                           = var.name_prefix
  aws_account_id                        = var.aws_account_id
  aws_region                            = var.aws_region
  cloudwatch_logs_log_group_arn_pattern = "arn:aws:logs:${var.aws_region}:${var.aws_account_id}:log-group:/ecs/${var.name_prefix}/*"
}

module "ecr" {
  source = "../../modules/ecr"

  repository_name      = var.name_prefix
  image_tag_mutability = "IMMUTABLE"
  encryption_type      = "KMS"
}

module "security_groups" {
  source = "../../modules/security_groups"

  name_prefix    = var.name_prefix
  vpc_id         = aws_vpc.main.id
  container_port = var.container_port

  # Public beta: the ALB is internet-facing. Restricting to owner IPs was
  # considered and rejected — the acceptance gate uses real hostnames over
  # TLS, and an IP allowlist would silently break once DNS is activated.
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

module "iam" {
  source = "../../modules/iam"

  name_prefix    = var.name_prefix
  aws_account_id = var.aws_account_id
  aws_region     = var.aws_region
  kms_key_arn    = module.kms.key_arn

  # Permissions boundaries — the ceiling on every production role this module
  # creates, so a later policy edit (by Terraform or by hand) cannot widen a
  # task role past what the boundary permits. Three distinct boundaries,
  # bootstrapped ahead of this stack and NOT managed here:
  #
  #   execution-boundary  the ECS agent's role: image pull, log write, secret
  #                       read. No application data access at all.
  #   migrator-boundary   schema migration only. Separated from task-boundary
  #                       because it is the one role holding DDL authority.
  #   task-boundary       every application task role.
  #
  # Written out per role rather than derived from a pattern: the mapping is the
  # security decision, so it should be readable in one place and diffable in
  # the plan, not reconstructed from string matching at apply time.
  task_execution_permissions_boundary_arn = local.execution_permissions_boundary_arn

  task_permissions_boundary_arns = {
    web             = local.task_permissions_boundary_arn
    worker          = local.task_permissions_boundary_arn
    critical_worker = local.task_permissions_boundary_arn
    scheduler       = local.task_permissions_boundary_arn
    maintenance     = local.task_permissions_boundary_arn
    ses_consumer    = local.task_permissions_boundary_arn
    migrate         = local.migrator_permissions_boundary_arn
  }

  task_execution_role_description    = "ECS task execution role for FirmsBase production"
  task_execution_policy_name         = "${var.name_prefix}-task-execution-secrets"
  task_execution_managed_policy_arn  = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
  task_execution_secrets_policy_sid  = "ProductionTaskExecutionSecrets"
  task_execution_kms_decrypt_enabled = true
  kms_encryption_enabled             = true
  s3_documents_enabled               = true
  s3_documents_bucket_arn            = module.s3_documents.bucket_arn

  # Bare ARNs — the JSON-key selectors used in `secrets` below are an ECS
  # concern, not an IAM one.
  task_execution_secret_arns = [
    aws_secretsmanager_secret.app_key.arn,
    aws_secretsmanager_secret.db_app.arn,
    aws_secretsmanager_secret.db_migrator.arn,
    aws_secretsmanager_secret.redis_auth.arn,
    aws_secretsmanager_secret.notification_hmac.arn,
  ]
}

module "alb" {
  source = "../../modules/alb"

  name_prefix         = var.name_prefix
  vpc_id              = aws_vpc.main.id
  public_subnet_ids   = aws_subnet.public[*].id
  security_group_id   = module.security_groups.alb_security_group_id
  container_port      = var.container_port
  acm_certificate_arn = aws_acm_certificate_validation.app.certificate_arn
}

resource "aws_cloudwatch_log_group" "app" {
  name              = local.log_group_name
  retention_in_days = 90
  kms_key_id        = module.kms.key_arn
}

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

  create_service                 = true
  desired_count                  = var.web_desired_count
  cluster_id                     = module.ecs_cluster.cluster_id
  subnet_ids                     = aws_subnet.private_app[*].id
  security_group_ids             = [module.security_groups.ecs_tasks_security_group_id]
  assign_public_ip               = false
  target_group_arn               = module.alb.target_group_arn
  stop_timeout_seconds           = 90
  attach_target_group            = true
  use_capacity_provider_strategy = false
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

  create_service                 = true
  desired_count                  = 1
  cluster_id                     = module.ecs_cluster.cluster_id
  subnet_ids                     = aws_subnet.private_app[*].id
  security_group_ids             = [module.security_groups.ecs_tasks_security_group_id]
  assign_public_ip               = false
  stop_timeout_seconds           = 120
  attach_target_group            = false
  use_capacity_provider_strategy = false
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

  create_service                 = true
  desired_count                  = 1
  cluster_id                     = module.ecs_cluster.cluster_id
  subnet_ids                     = aws_subnet.private_app[*].id
  security_group_ids             = [module.security_groups.ecs_tasks_security_group_id]
  assign_public_ip               = false
  stop_timeout_seconds           = 120
  attach_target_group            = false
  use_capacity_provider_strategy = false
}

# Exactly one. Never scaled beyond 1 — duplicate schedulers double every
# scheduled sweep, and five of them have no atomic duplicate protection.
module "scheduler" {
  source = "../../modules/ecs_service"

  name               = "scheduler"
  family             = "${var.name_prefix}-scheduler"
  image              = local.image
  command            = ["scheduler"]
  cpu                = 512
  memory             = 1024
  container_port     = var.container_port
  execution_role_arn = module.iam.task_execution_role_arn
  task_role_arn      = module.iam.task_role_arns["scheduler"]
  environment        = local.common_environment
  secrets            = local.common_secrets
  log_group_name     = aws_cloudwatch_log_group.app.name
  aws_region         = var.aws_region

  create_service                 = true
  desired_count                  = 1
  cluster_id                     = module.ecs_cluster.cluster_id
  subnet_ids                     = aws_subnet.private_app[*].id
  security_group_ids             = [module.security_groups.ecs_tasks_security_group_id]
  assign_public_ip               = false
  stop_timeout_seconds           = 120
  attach_target_group            = false
  use_capacity_provider_strategy = false
}
