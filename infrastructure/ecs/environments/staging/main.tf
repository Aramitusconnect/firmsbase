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

  # ElastiCache subnet-group membership is a genuinely different concern
  # from ECS task placement (var.private_subnet_ids) — this staging
  # environment's live subnet group registers 6 subnets across every AZ,
  # not just the 2 ECS places tasks into. Null (default) preserves original
  # behavior (falls back to private_subnet_ids) for a brand-new
  # environment. See docs/ecs/state-adoption-plan.md §9.15.
  elasticache_subnet_ids = coalesce(var.elasticache_subnet_ids, var.private_subnet_ids)
}

module "networking" {
  source = "../../modules/networking"

  vpc_id             = var.vpc_id
  public_subnet_ids  = var.public_subnet_ids
  private_subnet_ids = var.private_subnet_ids
}

module "kms" {
  source         = "../../modules/kms"
  name_prefix    = var.name_prefix
  aws_account_id = var.aws_account_id
  aws_region     = var.aws_region

  # Every one of this environment's 7 workload log groups
  # (aws_cloudwatch_log_group.app, for_each over local.roles) is wired to
  # this key via kms_key_id and shares the "/ecs/${var.name_prefix}/"
  # name prefix — see docs/ecs/state-adoption-plan.md for the real
  # incident this fixes (CloudWatch Logs' AccessDeniedException against
  # AWS's own default, root-only key policy).
  cloudwatch_logs_log_group_arn_pattern = "arn:aws:logs:${var.aws_region}:${var.aws_account_id}:log-group:/ecs/${var.name_prefix}/*"

  # module.ses_events_pipeline's queue+DLQ (both "${var.name_prefix}-ses-events*")
  # and topic — see that module for why SQS/SNS need the identical
  # service-linked-trust statement CloudWatch Logs already needed above.
  sqs_queue_arn_pattern = "arn:aws:sqs:${var.aws_region}:${var.aws_account_id}:${var.name_prefix}-ses-events*"
  sns_topic_arn_pattern = "arn:aws:sns:${var.aws_region}:${var.aws_account_id}:${var.name_prefix}-ses-events"
}

module "ses_events_pipeline" {
  source      = "../../modules/ses_events_pipeline"
  name_prefix = var.name_prefix
  kms_key_arn = module.kms.key_arn

  # Must match SES_EVENTS_VISIBILITY_TIMEOUT_SECONDS (local.ses_events_environment
  # below) exactly — the queue's own actual visibility timeout and the
  # value the consumer's SQS client is configured to assume are the same
  # real setting seen from two sides, not independently chosen.
  visibility_timeout_seconds = var.ses_events_visibility_timeout_seconds
}

module "ecr" {
  source          = "../../modules/ecr"
  repository_name = local.ecr_repository_name
  encryption_type = var.ecr_encryption_type
}

module "security_groups" {
  source = "../../modules/security_groups"

  name_prefix                    = var.name_prefix
  vpc_id                         = var.vpc_id
  alb_ingress_cidr_blocks        = var.alb_ingress_cidr_blocks
  existing_rds_security_group_id = var.rds_security_group_id

  ecs_tasks_security_group_name        = var.ecs_tasks_security_group_name
  ecs_tasks_security_group_description = var.ecs_tasks_security_group_description

  # This staging environment's live ECS-tasks security group carries a
  # single, pre-Terraform-adoption tag with an empty value (confirmed via
  # aws ec2 describe-security-groups) — a fixed, historical fact about this
  # one environment, not a reusable module default, so it's supplied here
  # rather than hardcoded in modules/security_groups. See
  # docs/ecs/state-adoption-plan.md.
  ecs_tasks_security_group_adoption_tags = {
    "firmsbase-staging-ecs-sg" = ""
  }

  alb_security_group_name        = var.alb_security_group_name
  alb_security_group_description = var.alb_security_group_description
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
  subnet_ids                  = local.elasticache_subnet_ids
  ecs_tasks_security_group_id = module.security_groups.ecs_tasks_security_group_id
  auth_token                  = var.redis_auth_token

  subnet_group_name    = coalesce(var.elasticache_subnet_group_name, "${var.name_prefix}-redis")
  engine               = var.elasticache_engine
  engine_version       = var.elasticache_engine_version
  parameter_group_name = var.elasticache_parameter_group_name

  security_group_name           = var.elasticache_security_group_name
  security_group_description    = var.elasticache_security_group_description
  subnet_group_description      = var.elasticache_subnet_group_description
  replication_group_description = var.elasticache_replication_group_description
  snapshot_retention_limit      = var.elasticache_snapshot_retention_limit

  # This staging environment's live Redis security group carries a single,
  # pre-Terraform-adoption tag with an empty value (confirmed via aws ec2
  # describe-security-groups) — a fixed, historical fact about this one
  # environment, not a reusable module default, so it's supplied here
  # rather than hardcoded in modules/elasticache. See
  # docs/ecs/state-adoption-plan.md.
  security_group_adoption_tags = {
    "firmsbase-staging-redis-sg" = ""
  }
}

module "ecs_cluster" {
  source                     = "../../modules/ecs_cluster"
  cluster_name               = local.ecs_cluster_name
  capacity_providers         = var.ecs_capacity_providers
  default_capacity_provider  = var.ecs_default_capacity_provider
  container_insights_enabled = var.ecs_container_insights_enabled

  # This staging environment's live cluster carries tags that predate
  # Terraform adoption (confirmed via aws ecs describe-clusters) — a
  # fixed, historical fact about this one environment, not a reusable
  # module default, so it's supplied here rather than hardcoded in
  # modules/ecs_cluster. See docs/ecs/state-adoption-plan.md.
  cluster_adoption_tags = {
    Application = "FirmsBase"
    Name        = "firmsbase-staging-cluster"
  }
}

resource "aws_cloudwatch_log_group" "app" {
  for_each = toset(local.roles)

  name              = "/ecs/${var.name_prefix}/${each.value}"
  retention_in_days = 30
  kms_key_id        = module.kms.key_arn
}

module "iam" {
  source = "../../modules/iam"

  name_prefix                       = var.name_prefix
  aws_account_id                    = var.aws_account_id
  aws_region                        = var.aws_region
  task_execution_role_name          = var.iam_task_execution_role_name
  task_execution_role_description   = var.iam_task_execution_role_description
  task_execution_policy_name        = var.iam_task_execution_policy_name
  task_execution_managed_policy_arn = var.iam_task_execution_managed_policy_arn
  task_execution_secrets_policy_sid = var.iam_task_execution_secrets_policy_sid

  # Bare ARNs only — never a ":<json-key>::" selector. IAM's
  # secretsmanager:GetSecretValue grant applies to the whole secret; the
  # JSON-key selector ECS needs is derived separately, only for the
  # `secrets` valueFrom entries below (local.shared_secrets/local.hmac_secret).
  # Exactly the 4 secrets the live inline policy grants (confirmed via aws
  # iam get-role-policy, see docs/ecs/state-adoption-plan.md §9.18) — the
  # platform-notifications HMAC-key secret is deliberately NOT included
  # here; it is delivered to task definitions separately (see
  # local.hmac_secret below) and is not part of the live execution-role
  # grant.
  task_execution_secret_arns = [
    var.app_key_secret_arn,
    var.db_password_secret_arn,
    var.redis_auth_token_secret_arn,
    var.db_migrator_secret_arn,
  ]

  # Both empty/false — this staging environment's live inline policy has
  # no SSM or KMS statement at all (confirmed via aws iam get-role-policy,
  # see docs/ecs/state-adoption-plan.md §9.18). Independent from
  # kms_encryption_enabled below, which is unrelated (S3-document task
  # roles only).
  task_execution_ssm_parameter_arns  = []
  task_execution_kms_decrypt_enabled = false

  # Literal true — this environment always provisions module.kms/
  # module.s3_documents; never derived from whether their outputs are null
  # (both are unknown-until-apply for these not-yet-created resources,
  # which would otherwise collapse dependent for_each/count instance sets
  # to unknown during import — see docs/ecs/state-adoption-plan.md).
  kms_encryption_enabled  = true
  s3_documents_enabled    = true
  kms_key_arn             = module.kms.key_arn
  s3_documents_bucket_arn = module.s3_documents.bucket_arn

  ses_events_queue_arn        = module.ses_events_pipeline.queue_arn
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

  alb_name          = var.alb_name
  target_group_name = var.alb_target_group_name

  canonical_hostnames = var.canonical_hostnames

  # This staging environment's live ALB has deletion protection enabled
  # (confirmed via aws elbv2 describe-load-balancer-attributes) — the
  # module's own default (false) is fine for a brand-new environment.
  enable_deletion_protection = var.alb_enable_deletion_protection

  readiness_health_check_path   = var.alb_health_check_path
  health_check_interval_seconds = var.alb_health_check_interval_seconds
  health_check_matcher          = var.alb_health_check_matcher

  # These four resources' live tags predate Terraform adoption (confirmed
  # via aws elbv2 describe-tags) — fixed, historical facts about this one
  # environment, not reusable module defaults, so they're supplied here
  # rather than hardcoded in modules/alb. http_redirect_listener_tags is
  # deliberately omitted: the live resource carries no tags at all. See
  # docs/ecs/state-adoption-plan.md.
  alb_adoption_tags = {
    Name    = "firmsbase-staging-alb"
    Project = "FirmsBase"
  }
  target_group_adoption_tags = {
    Name    = "firmsbase-staging-tg"
    Project = "FirmsBase"
  }
  https_listener_tags = {
    Name = "firmsbase-staging-https"
  }
}

locals {
  # Shared, non-secret environment for every role. Role-specific env vars
  # are merged in per ecs_service call below. See docs/ecs/env.ecs.example
  # for the full annotated reference this mirrors.
  shared_environment = merge(
    {
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
    },
    local.canonical_hostname_environment,
  )

  # Canonical per-surface hostname env vars consumed by config/hosts.php
  # (CanonicalUrlService, TrustHosts, each Filament panel's ->domain(...),
  # MyAttorney link generation) — see variables.tf's own block comment
  # for the full reconciliation-branch finding this closes. Built as a
  # filtered map, not a flat literal, so a null (unset) variable is
  # OMITTED from shared_environment entirely rather than merged in as a
  # literal "null" string — leaving every one of these six variables at
  # its default produces this local as an empty map and therefore NO
  # plan diff, the identical safety property var.canonical_hostnames
  # already has below.
  canonical_hostname_environment = { for key, value in {
    MARKETING_URL     = var.marketing_url
    FIRM_APP_URL      = var.firm_app_url
    CLIENT_PORTAL_URL = var.client_portal_url
    ADMIN_URL         = var.admin_url
    MYATTORNEY_URL    = var.myattorney_url
    API_URL           = var.api_url
  } : key => value if value != null }

  # Each live secret is a JSON blob with multiple keys — ECS's `secrets`
  # `valueFrom` needs the exact ":<json-key>::" selector appended, while
  # module.iam's task_execution_secret_arns list (below) must keep
  # receiving the bare ARN unchanged, since an IAM
  # secretsmanager:GetSecretValue Resource entry is
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

  # migrate-only — deliberately NOT folded into shared_secrets/
  # shared_environment above. Evidence (staging-deploy/firmsbase-staging-migrate.json,
  # cross-validated live via aws ecs describe-task-definition
  # firmsbase-staging-migrate:6, 2026-08-06) proves the historical migrate
  # task sources ALL FIVE DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD
  # fields from the dedicated, more-privileged database-migrator secret
  # (var.db_migrator_secret_arn) — not the regular database-app secret
  # every other role uses (var.db_password_secret_arn, via shared_secrets'
  # DB_PASSWORD-only selector, with host/port/database left as plain
  # shared_environment values for those roles). The execution role has
  # already carried secretsmanager:GetSecretValue on db_migrator_secret_arn
  # since the IAM policy-alignment pass (see docs/ecs/state-adoption-plan.md
  # §9.18) — that grant was correct but previously unused, since no
  # task definition's secrets map actually referenced it. See
  # docs/ecs/state-adoption-plan.md §9.23 for the full audit.
  migrate_secrets = {
    APP_KEY        = "${var.app_key_secret_arn}:APP_KEY::"
    DB_HOST        = "${var.db_migrator_secret_arn}:host::"
    DB_PORT        = "${var.db_migrator_secret_arn}:port::"
    DB_DATABASE    = "${var.db_migrator_secret_arn}:dbname::"
    DB_USERNAME    = "${var.db_migrator_secret_arn}:username::"
    DB_PASSWORD    = "${var.db_migrator_secret_arn}:password::"
    REDIS_PASSWORD = "${var.redis_auth_token_secret_arn}:REDIS_PASSWORD::"
  }

  # DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME move to migrate_secrets above
  # for migrate specifically — ECS rejects a task definition that declares
  # the same env var name in both `environment` and `secrets`, so they
  # must be excluded here. DB_CONNECTION and DB_SSLMODE are protocol/mode
  # flags, not credentials, and remain plain — matching the historical
  # migrate task definition's own environment array exactly (it has no
  # DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME entries at all).
  migrate_environment = {
    for k, v in local.shared_environment : k => v
    if !contains(["DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME"], k)
  }

  # Plain (non-secret) SES consumer tuning — shared by web (which doesn't
  # use it) intentionally excluded; only ses-consumer's own environment
  # merges this in, below.
  ses_events_environment = {
    SES_EVENTS_QUEUE_URL                  = module.ses_events_pipeline.queue_url
    SES_EVENTS_QUEUE_REGION               = var.aws_region
    SES_EVENTS_WAIT_TIME_SECONDS          = tostring(var.ses_events_wait_time_seconds)
    SES_EVENTS_VISIBILITY_TIMEOUT_SECONDS = tostring(var.ses_events_visibility_timeout_seconds)
    SES_EVENTS_MAX_MESSAGES               = tostring(var.ses_events_max_messages)
  }

  # config/mail.php's 'ses' transport always attaches a ConfigurationSetName
  # (see that file's own docblock: without one, SES never publishes
  # Bounce/Complaint/Reject/RenderingFailure/DeliveryDelay events to the SNS
  # topic feeding ses-consumer's queue at all). web is the only role that
  # sends mail (Illuminate\Mail\Transport\SesTransport, synchronous from the
  # request path — no ShouldQueue mailable/notification exists), so this is
  # web-only, mirroring the identical PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY
  # scoping precedent above.
  ses_configuration_set_environment = {
    SES_CONFIGURATION_SET = module.ses_events_pipeline.configuration_set_name
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

  environment = merge(local.shared_environment, local.ses_configuration_set_environment)
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

  deployment_minimum_healthy_percent = var.web_deployment_minimum_healthy_percent
  deployment_maximum_percent         = var.web_deployment_maximum_percent
  tags                               = var.web_tags

  readonly_root_filesystem = var.readonly_root_filesystem_enabled

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

  deployment_minimum_healthy_percent = var.worker_deployment_minimum_healthy_percent
  deployment_maximum_percent         = var.worker_deployment_maximum_percent
  tags                               = var.worker_tags

  readonly_root_filesystem = var.readonly_root_filesystem_enabled

  # Live-adoption values (confirmed via aws ecs describe-services) — an
  # earlier, non-Terraform process set these true/TASK_DEFINITION on this
  # service, unlike web's false/NONE. See ecs_service module variables.tf
  # and docs/ecs/state-adoption-plan.md.
  enable_ecs_managed_tags = true
  propagate_tags          = "TASK_DEFINITION"

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

  deployment_minimum_healthy_percent = var.critical_worker_deployment_minimum_healthy_percent
  deployment_maximum_percent         = var.critical_worker_deployment_maximum_percent
  tags                               = var.critical_worker_tags

  readonly_root_filesystem = var.readonly_root_filesystem_enabled

  # Live-adoption values — see module.worker's identical override above
  # for rationale.
  enable_ecs_managed_tags = true
  propagate_tags          = "TASK_DEFINITION"

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
  # overlap during deploys for this single-instance service. Sourced from
  # variables (matching web/worker/critical_worker) rather than hardcoded
  # literals, per docs/ecs/state-adoption-plan.md §9.20.
  deployment_minimum_healthy_percent = var.scheduler_deployment_minimum_healthy_percent
  deployment_maximum_percent         = var.scheduler_deployment_maximum_percent
  tags                               = var.scheduler_tags

  readonly_root_filesystem = var.readonly_root_filesystem_enabled

  # Live-adoption values — see module.worker's identical override above
  # for rationale.
  enable_ecs_managed_tags = true
  propagate_tags          = "TASK_DEFINITION"

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

  # migrate uses its own dedicated database-migrator credentials — see
  # local.migrate_secrets/local.migrate_environment above and
  # docs/ecs/state-adoption-plan.md §9.23. Every other role continues on
  # local.shared_environment/local.shared_secrets (database-app),
  # unchanged.
  environment = local.migrate_environment
  secrets     = local.migrate_secrets

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

  readonly_root_filesystem = var.readonly_root_filesystem_enabled
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

  readonly_root_filesystem = var.readonly_root_filesystem_enabled
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

  readonly_root_filesystem = var.readonly_root_filesystem_enabled
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
  ses_events_queue_name       = module.ses_events_pipeline.queue_name
  ses_events_dlq_name         = module.ses_events_pipeline.dlq_name
  ses_consumer_log_group_name = aws_cloudwatch_log_group.app["ses-consumer"].name

  enable_custom_metric_alarms = var.enable_custom_metric_alarms
}

# Mission 1B (Extreme Security Hardening) additions below. Every module
# in this section defaults to fully disabled (see each module's own
# variables.tf) — applying this environment with no new tfvars supplied
# creates NONE of these resources; they exist as reviewed, ready-to-adopt
# infrastructure for an explicit, separate, later decision. See this
# mission's own final report for the classification of each.

module "waf" {
  source = "../../modules/waf"

  name_prefix = var.name_prefix
  alb_arn     = module.alb.alb_arn

  enabled = var.enable_waf

  tags = {
    Name = "${var.name_prefix}-waf"
  }
}

module "security_monitoring" {
  source = "../../modules/security_monitoring"

  name_prefix    = var.name_prefix
  aws_account_id = var.aws_account_id
  kms_key_arn    = module.kms.key_arn

  enable_cloudtrail          = var.enable_cloudtrail
  enable_guardduty           = var.enable_guardduty
  enable_security_hub        = var.enable_security_hub
  enable_iam_access_analyzer = var.enable_iam_access_analyzer

  tags = {
    Name = "${var.name_prefix}-security-monitoring"
  }
}

module "backup" {
  source = "../../modules/backup"

  name_prefix = var.name_prefix
  kms_key_arn = module.kms.key_arn

  enabled = var.enable_backup_plan

  tags = {
    Name = "${var.name_prefix}-backup"
  }
}
