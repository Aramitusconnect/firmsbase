locals {
  roles = ["web", "worker", "critical-worker", "scheduler", "migrate", "maintenance"]
}

module "networking" {
  source = "../../modules/networking"

  vpc_id             = var.vpc_id
  public_subnet_ids  = var.public_subnet_ids
  private_subnet_ids = var.private_subnet_ids
}

module "kms" {
  source      = "../../modules/kms"
  name_prefix = var.name_prefix
}

module "ecr" {
  source          = "../../modules/ecr"
  repository_name = "firmsbase-app"
}

module "security_groups" {
  source = "../../modules/security_groups"

  name_prefix                    = var.name_prefix
  vpc_id                         = var.vpc_id
  alb_ingress_cidr_blocks        = var.alb_ingress_cidr_blocks
  existing_rds_security_group_id = var.rds_security_group_id
}

module "s3_documents" {
  source      = "../../modules/s3_documents"
  bucket_name = "${var.name_prefix}-documents"
  kms_key_arn = module.kms.key_arn
}

module "elasticache" {
  source = "../../modules/elasticache"

  name_prefix                 = var.name_prefix
  vpc_id                      = var.vpc_id
  private_subnet_ids          = var.private_subnet_ids
  ecs_tasks_security_group_id = module.security_groups.ecs_tasks_security_group_id
  auth_token                  = var.redis_auth_token
}

module "ecs_cluster" {
  source       = "../../modules/ecs_cluster"
  cluster_name = var.name_prefix
}

resource "aws_cloudwatch_log_group" "app" {
  for_each = toset(local.roles)

  name              = "/ecs/${var.name_prefix}/${each.value}"
  retention_in_days = 30
  kms_key_id        = module.kms.key_arn
}

module "iam" {
  source = "../../modules/iam"

  name_prefix        = var.name_prefix
  ecr_repository_arn = module.ecr.repository_arn
  # trimsuffix guards against the aws_cloudwatch_log_group.arn attribute's
  # trailing ":*" varying by provider version — normalize then re-append so
  # the IAM policy always ends up with exactly one ":*" (needed for
  # logs:PutLogEvents to match every stream within the group, not just a
  # literal-named one).
  log_group_arns = [for lg in aws_cloudwatch_log_group.app : "${trimsuffix(lg.arn, ":*")}:*"]

  secret_arns = [
    var.app_key_secret_arn,
    var.db_password_secret_arn,
    var.redis_auth_token_secret_arn,
  ]

  kms_key_arn             = module.kms.key_arn
  s3_documents_bucket_arn = module.s3_documents.bucket_arn
}

module "alb" {
  source = "../../modules/alb"

  name_prefix         = var.name_prefix
  vpc_id              = var.vpc_id
  public_subnet_ids   = var.public_subnet_ids
  security_group_id   = module.security_groups.alb_security_group_id
  acm_certificate_arn = var.acm_certificate_arn
}

locals {
  # Shared, non-secret environment for every role. Role-specific env vars
  # are merged in per ecs_service call below. See docs/ecs/env.ecs.example
  # for the full annotated reference this mirrors.
  shared_environment = {
    APP_NAME                = "FirmsBase"
    APP_ENV                 = "staging"
    APP_DEBUG               = "false"
    APP_MAINTENANCE_DRIVER  = "cache"
    APP_MAINTENANCE_STORE   = "redis"
    DB_CONNECTION           = "pgsql"
    DB_HOST                 = var.db_host
    DB_PORT                 = "5432"
    DB_DATABASE             = var.db_database
    DB_USERNAME             = "firmsbase_app"
    DB_SSLMODE              = "require"
    REDIS_CLIENT            = "phpredis"
    REDIS_HOST              = module.elasticache.primary_endpoint_address
    REDIS_PORT              = tostring(module.elasticache.port)
    REDIS_CACHE_DB          = "1"
    REDIS_QUEUE_DB          = "2"
    CACHE_STORE             = "redis"
    SESSION_DRIVER          = "redis"
    SESSION_SECURE_COOKIE   = "true"
    QUEUE_CONNECTION        = "redis"
    REDIS_QUEUE_CONNECTION  = "queue"
    REDIS_QUEUE_RETRY_AFTER = "150"
    FILESYSTEM_DISK         = "s3"
    AWS_DEFAULT_REGION      = var.aws_region
    AWS_BUCKET              = module.s3_documents.bucket_name
    LOG_CHANNEL             = "stderr"
    LOG_LEVEL               = "info"
    MAIL_MAILER             = "log"
  }

  shared_secrets = {
    APP_KEY        = var.app_key_secret_arn
    DB_PASSWORD    = var.db_password_secret_arn
    REDIS_PASSWORD = var.redis_auth_token_secret_arn
  }
}

module "web" {
  source = "../../modules/ecs_service"

  name           = "web"
  family         = "${var.name_prefix}-web"
  image          = var.app_image_digest
  command        = ["web"]
  cpu            = 512
  memory         = 1024
  container_port = 8080

  execution_role_arn = module.iam.task_execution_role_arn
  task_role_arn      = module.iam.task_role_arns["web"]

  environment = local.shared_environment
  secrets     = local.shared_secrets

  log_group_name = aws_cloudwatch_log_group.app["web"].name
  aws_region     = var.aws_region

  stop_timeout_seconds           = 90
  container_health_check_command = ["CMD-SHELL", "curl -f http://localhost:8080/up || exit 1"]

  create_service     = true
  desired_count      = 2
  cluster_id         = module.ecs_cluster.cluster_id
  subnet_ids         = var.private_subnet_ids
  security_group_ids = [module.security_groups.ecs_tasks_security_group_id]
  target_group_arn   = module.alb.target_group_arn

  enable_autoscaling             = true
  autoscaling_min_capacity       = 2
  autoscaling_max_capacity       = 6
  autoscaling_cpu_target_percent = 60
}

module "worker" {
  source = "../../modules/ecs_service"

  name    = "worker"
  family  = "${var.name_prefix}-worker"
  image   = var.app_image_digest
  command = ["worker"]
  cpu     = 512
  memory  = 1024

  execution_role_arn = module.iam.task_execution_role_arn
  task_role_arn      = module.iam.task_role_arns["worker"]

  environment = merge(local.shared_environment, {
    WORKER_QUEUES   = "default,documents,notifications,integrations,billing,low-priority"
    WORKER_TRIES    = "3"
    WORKER_TIMEOUT  = "90"
    WORKER_MAX_JOBS = "500"
    WORKER_MAX_TIME = "3600"
    WORKER_MEMORY   = "256"
    WORKER_BACKOFF  = "10,30,60"
  })
  secrets = local.shared_secrets

  log_group_name = aws_cloudwatch_log_group.app["worker"].name
  aws_region     = var.aws_region

  stop_timeout_seconds = 120

  create_service     = true
  desired_count      = 2
  cluster_id         = module.ecs_cluster.cluster_id
  subnet_ids         = var.private_subnet_ids
  security_group_ids = [module.security_groups.ecs_tasks_security_group_id]

  enable_autoscaling             = true
  autoscaling_min_capacity       = 1
  autoscaling_max_capacity       = 6
  autoscaling_cpu_target_percent = 70
}

module "critical_worker" {
  source = "../../modules/ecs_service"

  name    = "critical-worker"
  family  = "${var.name_prefix}-critical-worker"
  image   = var.app_image_digest
  command = ["worker"]
  cpu     = 512
  memory  = 1024

  execution_role_arn = module.iam.task_execution_role_arn
  task_role_arn      = module.iam.task_role_arns["critical_worker"]

  environment = merge(local.shared_environment, {
    WORKER_QUEUES   = "trust"
    WORKER_TRIES    = "3"
    WORKER_TIMEOUT  = "90"
    WORKER_MAX_JOBS = "500"
    WORKER_MAX_TIME = "3600"
    WORKER_MEMORY   = "256"
    WORKER_BACKOFF  = "10,30,60"
  })
  secrets = local.shared_secrets

  log_group_name = aws_cloudwatch_log_group.app["critical-worker"].name
  aws_region     = var.aws_region

  stop_timeout_seconds = 120

  create_service     = true
  desired_count      = 1 # fixed — never scaled to zero, see docs/ecs/queue-and-redis-architecture.md
  cluster_id         = module.ecs_cluster.cluster_id
  subnet_ids         = var.private_subnet_ids
  security_group_ids = [module.security_groups.ecs_tasks_security_group_id]

  enable_autoscaling = false
}

module "scheduler" {
  source = "../../modules/ecs_service"

  name    = "scheduler"
  family  = "${var.name_prefix}-scheduler"
  image   = var.app_image_digest
  command = ["scheduler"]
  cpu     = 256
  memory  = 512

  execution_role_arn = module.iam.task_execution_role_arn
  task_role_arn      = module.iam.task_role_arns["scheduler"]

  environment = local.shared_environment
  secrets     = local.shared_secrets

  log_group_name = aws_cloudwatch_log_group.app["scheduler"].name
  aws_region     = var.aws_region

  stop_timeout_seconds = 30

  create_service     = true
  desired_count      = 1 # single instance — see docs/ecs/graceful-shutdown.md
  cluster_id         = module.ecs_cluster.cluster_id
  subnet_ids         = var.private_subnet_ids
  security_group_ids = [module.security_groups.ecs_tasks_security_group_id]

  # See docs/ecs/graceful-shutdown.md — avoids a transient two-instance
  # overlap during deploys for this single-instance service.
  deployment_minimum_healthy_percent = 0
  deployment_maximum_percent         = 100

  enable_autoscaling = false
}

module "migrate" {
  source = "../../modules/ecs_service"

  name    = "migrate"
  family  = "${var.name_prefix}-migrate"
  image   = var.app_image_digest
  command = ["migrate"]
  cpu     = 512
  memory  = 1024

  execution_role_arn = module.iam.task_execution_role_arn
  task_role_arn      = module.iam.task_role_arns["migrate"]

  environment = local.shared_environment
  secrets     = local.shared_secrets

  log_group_name = aws_cloudwatch_log_group.app["migrate"].name
  aws_region     = var.aws_region

  stop_timeout_seconds = 120 # see docs/ecs/database-migrations.md

  create_service     = false # one-off task definition only — invoked via ECS RunTask, never a standing service. See docs/ecs/database-migrations.md.
  cluster_id         = module.ecs_cluster.cluster_id
  subnet_ids         = var.private_subnet_ids
  security_group_ids = [module.security_groups.ecs_tasks_security_group_id]
}

module "maintenance" {
  source = "../../modules/ecs_service"

  name   = "maintenance"
  family = "${var.name_prefix}-maintenance"
  image  = var.app_image_digest
  # Baseline command is a placeholder — a real invocation supplies the
  # actual Artisan subcommand via ECS RunTask's containerOverrides.command,
  # e.g. ["maintenance", "queue:prune-failed", "--hours=24"]. See
  # docker/commands/maintenance.sh and docs/ecs/database-migrations.md.
  command = ["maintenance", "list"]
  cpu     = 512
  memory  = 1024

  execution_role_arn = module.iam.task_execution_role_arn
  task_role_arn      = module.iam.task_role_arns["maintenance"]

  environment = local.shared_environment
  secrets     = local.shared_secrets

  log_group_name = aws_cloudwatch_log_group.app["maintenance"].name
  aws_region     = var.aws_region

  stop_timeout_seconds = 120

  create_service     = false
  cluster_id         = module.ecs_cluster.cluster_id
  subnet_ids         = var.private_subnet_ids
  security_group_ids = [module.security_groups.ecs_tasks_security_group_id]
}

module "cloudwatch_alarms" {
  source = "../../modules/cloudwatch_alarms"

  name_prefix   = var.name_prefix
  sns_topic_arn = var.alarm_sns_topic_arn

  alb_arn_suffix               = module.alb.alb_arn_suffix
  target_group_arn_suffix      = module.alb.target_group_arn_suffix
  ecs_cluster_name             = module.ecs_cluster.cluster_name
  web_service_name             = module.web.service_name
  general_worker_service_name  = module.worker.service_name
  critical_worker_service_name = module.critical_worker.service_name
  rds_instance_id              = var.rds_instance_id
  redis_cluster_id             = "${var.name_prefix}-redis"

  enable_custom_metric_alarms = var.enable_custom_metric_alarms
}
