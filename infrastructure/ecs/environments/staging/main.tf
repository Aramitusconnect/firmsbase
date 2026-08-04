locals {
  roles = ["web", "worker", "critical-worker", "scheduler", "migrate", "maintenance", "ses-consumer"]

  # See variables.tf "Live-infrastructure-adoption overrides" and
  # docs/ecs/state-adoption-plan.md — null overrides fall back to this
  # config's original name_prefix-derived computation, so a brand-new
  # environment is unaffected.
  ecs_cluster_name    = coalesce(var.ecs_cluster_name, var.name_prefix)
  ecr_repository_name = coalesce(var.ecr_repository_name, "firmsbase-app")

  # See docs/ecs/state-adoption-plan.md §9.1 — the only safe default until
  # real private-subnet + NAT egress exists and private_egress_ready is
  # deliberately flipped on.
  assign_public_ip = !var.private_egress_ready
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
  repository_name = local.ecr_repository_name
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

  subnet_group_name    = coalesce(var.elasticache_subnet_group_name, "${var.name_prefix}-redis")
  engine               = var.elasticache_engine
  engine_version       = var.elasticache_engine_version
  parameter_group_name = var.elasticache_parameter_group_name
}

module "ecs_cluster" {
  source                    = "../../modules/ecs_cluster"
  cluster_name              = local.ecs_cluster_name
  capacity_providers        = var.ecs_capacity_providers
  default_capacity_provider = var.ecs_default_capacity_provider
}

resource "aws_cloudwatch_log_group" "app" {
  for_each = toset(local.roles)

  name              = "/ecs/${var.name_prefix}/${each.value}"
  retention_in_days = 30
  kms_key_id        = module.kms.key_arn
}

module "iam" {
  source = "../../modules/iam"

  name_prefix                = var.name_prefix
  task_execution_role_name   = var.iam_task_execution_role_name
  task_execution_policy_name = var.iam_task_execution_policy_name
  ecr_repository_arn         = module.ecr.repository_arn
  # trimsuffix guards against the aws_cloudwatch_log_group.arn attribute's
  # trailing ":*" varying by provider version — normalize then re-append so
  # the IAM policy always ends up with exactly one ":*" (needed for
  # logs:PutLogEvents to match every stream within the group, not just a
  # literal-named one).
  log_group_arns = [for lg in aws_cloudwatch_log_group.app : "${trimsuffix(lg.arn, ":*")}:*"]

  # Bare ARNs only — never a ":<json-key>::" selector. IAM's
  # secretsmanager:GetSecretValue grant applies to the whole secret; the
  # JSON-key selector ECS needs is derived separately, only for the
  # `secrets` valueFrom entries below (local.shared_secrets/local.hmac_secret).
  secret_arns = [
    var.app_key_secret_arn,
    var.db_password_secret_arn,
    var.redis_auth_token_secret_arn,
    var.platform_notifications_recipient_fingerprint_hmac_key_secret_arn,
  ]

  # Literal true — this environment always provisions module.kms/
  # module.s3_documents; never derived from whether their outputs are null
  # (both are unknown-until-apply for these not-yet-created resources,
  # which would otherwise collapse dependent for_each/count instance sets
  # to unknown during import — see docs/ecs/state-adoption-plan.md).
  kms_encryption_enabled  = true
  s3_documents_enabled    = true
  kms_key_arn             = module.kms.key_arn
  s3_documents_bucket_arn = module.s3_documents.bucket_arn

  ses_events_queue_arn        = var.ses_events_queue_arn
  ses_sending_identity_arn    = var.ses_sending_identity_arn
  ses_authorized_from_address = var.ses_authorized_from_address
}

module "alb" {
  source = "../../modules/alb"

  name_prefix         = var.name_prefix
  vpc_id              = var.vpc_id
  public_subnet_ids   = var.public_subnet_ids
  security_group_id   = module.security_groups.alb_security_group_id
  acm_certificate_arn = var.acm_certificate_arn

  readiness_health_check_path   = var.alb_health_check_path
  health_check_interval_seconds = var.alb_health_check_interval_seconds
  health_check_matcher          = var.alb_health_check_matcher
}

locals {
  # Shared, non-secret environment for every role. Role-specific env vars
  # are merged in per ecs_service call below. See docs/ecs/env.ecs.example
  # for the full annotated reference this mirrors.
  shared_environment = {
    APP_NAME                = "FirmsBase"
    APP_ENV                 = "staging"
    APP_URL                 = var.app_url
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
    # Keep mail configuration consistent across all ECS services.
    # AWS credentials are supplied by the ECS task role.
    AWS_REGION        = var.aws_region
    AWS_BUCKET        = module.s3_documents.bucket_name
    LOG_CHANNEL       = "stderr"
    LOG_LEVEL         = "info"
    MAIL_MAILER       = "ses"
    MAIL_FROM_ADDRESS = "no-reply@staging-mail.firmsvault.com"
    MAIL_FROM_NAME    = "FirmsVault"
  }

  # Each live secret is a JSON blob with multiple keys — ECS's `secrets`
  # `valueFrom` needs the exact ":<json-key>::" selector appended, while
  # module.iam's secret_arns list (below) must keep receiving the bare ARN
  # unchanged, since an IAM secretsmanager:GetSecretValue Resource entry is
  # scoped to the whole secret and must never carry a JSON-key selector
  # suffix. Deriving the selector here (rather than asking the operator to
  # supply two separately-formatted values for the same secret) means
  # var.app_key_secret_arn/var.db_password_secret_arn/
  # var.redis_auth_token_secret_arn each need to be set exactly once, as a
  # bare ARN. See docs/ecs/staging-variable-inventory.md.
  shared_secrets = {
    APP_KEY        = "${var.app_key_secret_arn}:APP_KEY::"
    DB_PASSWORD    = "${var.db_password_secret_arn}:password::"
    REDIS_PASSWORD = "${var.redis_auth_token_secret_arn}:REDIS_PASSWORD::"
  }

  # Role-specific — deliberately NOT folded into shared_secrets above.
  # Password-reset/owner-invitation notifications are sent synchronously
  # from the web request path (no ShouldQueue) and the ses-consumer
  # resolves platform_notification_correlations rows keyed by a
  # fingerprint derived from this same key; no other role calls that code
  # path today, so no other role receives it (see docs/ecs/iam-matrix.md
  # and CorrelatedPasswordResetSenderService/PlatformNotificationCorrelationService).
  hmac_secret = {
    PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY = var.platform_notifications_recipient_fingerprint_hmac_key_secret_arn
  }

  # Plain (non-secret) SES consumer tuning — shared by web (which doesn't
  # use it) intentionally excluded; only ses-consumer's own environment
  # merges this in, below.
  ses_events_environment = {
    SES_EVENTS_QUEUE_URL                  = var.ses_events_queue_url
    SES_EVENTS_QUEUE_REGION               = var.aws_region
    SES_EVENTS_WAIT_TIME_SECONDS          = tostring(var.ses_events_wait_time_seconds)
    SES_EVENTS_VISIBILITY_TIMEOUT_SECONDS = tostring(var.ses_events_visibility_timeout_seconds)
    SES_EVENTS_MAX_MESSAGES               = tostring(var.ses_events_max_messages)
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
  secrets     = merge(local.shared_secrets, local.hmac_secret)

  log_group_name = aws_cloudwatch_log_group.app["web"].name
  aws_region     = var.aws_region

  stop_timeout_seconds           = 90
  container_health_check_command = ["CMD-SHELL", "curl -f http://localhost:8080/up || exit 1"]

  create_service     = true
  desired_count      = var.web_desired_count
  cluster_id         = module.ecs_cluster.cluster_id
  subnet_ids         = var.private_subnet_ids
  security_group_ids = [module.security_groups.ecs_tasks_security_group_id]
  assign_public_ip   = local.assign_public_ip
  # Literal false — live staging services currently run with launch_type=FARGATE,
  # no capacity-provider association at the cluster level. See
  # docs/ecs/state-adoption-plan.md §9.10/§9.11.
  use_capacity_provider_strategy = false
  # Literal true — web is always registered with the ALB target group;
  # never derived from whether target_group_arn is null (unknown-until-apply
  # for the not-yet-imported target group, which would otherwise collapse
  # the load_balancer dynamic block's for_each to unknown during import).
  attach_target_group = true
  target_group_arn    = module.alb.target_group_arn

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
  desired_count      = var.worker_desired_count
  cluster_id         = module.ecs_cluster.cluster_id
  subnet_ids         = var.private_subnet_ids
  security_group_ids = [module.security_groups.ecs_tasks_security_group_id]
  assign_public_ip   = local.assign_public_ip
  # Literal false — live staging services currently run with launch_type=FARGATE,
  # no capacity-provider association at the cluster level. See
  # docs/ecs/state-adoption-plan.md §9.10/§9.11.
  use_capacity_provider_strategy = false
  # Literal false — worker is never behind the ALB.
  attach_target_group = false

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
  desired_count      = var.critical_worker_desired_count
  cluster_id         = module.ecs_cluster.cluster_id
  subnet_ids         = var.private_subnet_ids
  security_group_ids = [module.security_groups.ecs_tasks_security_group_id]
  assign_public_ip   = local.assign_public_ip
  # Literal false — live staging services currently run with launch_type=FARGATE,
  # no capacity-provider association at the cluster level. See
  # docs/ecs/state-adoption-plan.md §9.10/§9.11.
  use_capacity_provider_strategy = false
  # Literal false — critical-worker is never behind the ALB.
  attach_target_group = false

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
  desired_count      = var.scheduler_desired_count
  cluster_id         = module.ecs_cluster.cluster_id
  subnet_ids         = var.private_subnet_ids
  security_group_ids = [module.security_groups.ecs_tasks_security_group_id]
  assign_public_ip   = local.assign_public_ip
  # Literal false — live staging services currently run with launch_type=FARGATE,
  # no capacity-provider association at the cluster level. See
  # docs/ecs/state-adoption-plan.md §9.10/§9.11.
  use_capacity_provider_strategy = false
  # Literal false — scheduler is never behind the ALB.
  attach_target_group = false

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
  assign_public_ip   = local.assign_public_ip
  # No service is ever created here (create_service=false), but the
  # variable is still required by the module regardless of count — literal
  # false for consistency with every other caller.
  use_capacity_provider_strategy = false
  # Literal false — migrate is never behind the ALB.
  attach_target_group = false
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
  assign_public_ip   = local.assign_public_ip
  # No service is ever created here (create_service=false), but the
  # variable is still required by the module regardless of count — literal
  # false for consistency with every other caller.
  use_capacity_provider_strategy = false
  # Literal false — maintenance is never behind the ALB.
  attach_target_group = false
}

module "ses_consumer" {
  source = "../../modules/ecs_service"

  name    = "ses-consumer"
  family  = "${var.name_prefix}-ses-consumer"
  image   = var.app_image_digest
  command = ["ses-consumer"]
  cpu     = var.ses_consumer_cpu
  memory  = var.ses_consumer_memory

  execution_role_arn = module.iam.task_execution_role_arn
  task_role_arn      = module.iam.task_role_arns["ses_consumer"]

  environment = merge(local.shared_environment, local.ses_events_environment)
  secrets     = merge(local.shared_secrets, local.hmac_secret)

  log_group_name = aws_cloudwatch_log_group.app["ses-consumer"].name
  aws_region     = var.aws_region

  stop_timeout_seconds = var.ses_consumer_stop_timeout

  # No container_health_check_command — a non-HTTP, non-request-serving
  # process has no endpoint to probe; ECS's own task-exit detection (the
  # ecs_service_running_count alarm below) is this service's liveness
  # signal, same rationale as worker/critical-worker/scheduler.
  create_service     = true
  desired_count      = var.ses_consumer_desired_count
  cluster_id         = module.ecs_cluster.cluster_id
  subnet_ids         = var.private_subnet_ids
  security_group_ids = [module.security_groups.ecs_tasks_security_group_id]
  assign_public_ip   = local.assign_public_ip
  # Literal false — live staging services currently run with launch_type=FARGATE,
  # no capacity-provider association at the cluster level. See
  # docs/ecs/state-adoption-plan.md §9.10/§9.11.
  use_capacity_provider_strategy = false
  # No target_group_arn — never behind the ALB (not an HTTP service).
  attach_target_group = false

  enable_autoscaling = false # single long-polling consumer; SQS's own visibility timeout already prevents duplicate concurrent processing of one message.
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

  # Literal true — ses-consumer is always provisioned in this environment;
  # never derived from whether ses_consumer_service_name/
  # ses_consumer_log_group_name is null (both unknown-until-apply for the
  # not-yet-created ses-consumer service, which would otherwise collapse
  # the per-service alarm for_each's key set to unknown during import).
  ses_consumer_enabled        = true
  ses_consumer_service_name   = module.ses_consumer.service_name
  ses_events_queue_name       = element(split("/", var.ses_events_queue_url), length(split("/", var.ses_events_queue_url)) - 1)
  ses_events_dlq_name         = element(split(":", var.ses_events_dlq_arn), 5)
  ses_consumer_log_group_name = aws_cloudwatch_log_group.app["ses-consumer"].name

  enable_custom_metric_alarms = var.enable_custom_metric_alarms
}
