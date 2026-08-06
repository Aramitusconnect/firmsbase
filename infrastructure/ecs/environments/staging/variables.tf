variable "aws_region" {
  type    = string
  default = "us-east-1"
}

variable "name_prefix" {
  type    = string
  default = "firmsbase-staging"
}

# --- Networking (existing VPC — see infrastructure/ecs/modules/networking) -
variable "vpc_id" {
  type = string
}

variable "public_subnet_ids" {
  type = list(string)
}

variable "private_subnet_ids" {
  type = list(string)
}

variable "alb_ingress_cidr_blocks" {
  description = "See infrastructure/ecs/modules/security_groups — restrict to a known range for staging."
  type        = list(string)
}

# --- DNS / TLS — human-supplied, this mission does not create either -------
variable "acm_certificate_arn" {
  description = "See infrastructure/ecs/modules/alb — must already be issued/validated."
  type        = string
}

variable "app_url" {
  description = "Public HTTPS URL used by the staging application for generated links and redirects."
  type        = string

  validation {
    condition = (
      can(regex("^https://[^[:space:]]+$", var.app_url)) &&
      !endswith(var.app_url, "/")
    )
    error_message = "app_url must be an HTTPS URL without whitespace or a trailing slash."
  }
}

# --- RDS — existing instance, not created by this mission ------------------
variable "rds_instance_id" {
  type = string
}

variable "rds_security_group_id" {
  type = string
}

variable "db_host" {
  type = string
}

variable "db_database" {
  type    = string
  default = "firmsbase_staging"
}

# --- Application image (supplied by CI/CD — see
# .github/workflows/ecs-pipeline.yml — never hand-typed for a real deploy) -
variable "app_image_digest" {
  description = "Full image reference including immutable digest: <ecr-repo-url>@sha256:<64 hex chars>. See docs/ecs/container-architecture.md."
  type        = string
}

# --- Secrets — ARNs only; values live in Secrets Manager, never in
# Terraform state or var files. See docs/ecs/iam-matrix.md and
# docs/ecs/env.ecs.example. Each of the three variables below must be the
# BARE Secrets Manager ARN — no ":<json-key>::" selector suffix. Each live
# secret is a JSON blob with multiple keys, so main.tf's local.shared_secrets
# derives the exact ECS `valueFrom` selector Terraform needs
# (":APP_KEY::", ":password::", ":REDIS_PASSWORD::" respectively) by string
# interpolation, while module.iam's secret_arns list receives these bare
# variables unchanged — IAM's secretsmanager:GetSecretValue grant is scoped
# to the whole secret, so its Resource entries must never carry a JSON-key
# suffix (see docs/ecs/staging-variable-inventory.md). --------------------
variable "app_key_secret_arn" {
  description = "Bare ARN of the APP_KEY secret (a JSON blob with an \"APP_KEY\" key — see docs/ecs/staging-variable-inventory.md). Do not append a \":APP_KEY::\" selector here; main.tf derives it automatically."
  type        = string
}

variable "db_password_secret_arn" {
  description = "Bare ARN of the database secret (a JSON blob with a \"password\" key, among others — see docs/ecs/staging-variable-inventory.md). Do not append a \":password::\" selector here; main.tf derives it automatically."
  type        = string
}

variable "db_migrator_secret_arn" {
  description = "Bare ARN of the database migrator secret — a separate, more-privileged DB credential used only by the migrate task (see module \"migrate\" below), distinct from db_password_secret_arn's regular app-user credential. Previously unmodeled entirely: the task-execution role's inline policy incorrectly granted access to the platform-notifications HMAC-key secret instead (see docs/ecs/state-adoption-plan.md §9.18). This staging environment's live inline policy (FirmsBaseStagingSecretsAccess, confirmed via aws iam get-role-policy) actually grants secretsmanager:GetSecretValue on \"firmsbase/staging/database-migrator-TpsE6P\"."
  type        = string

  validation {
    condition     = can(regex("^arn:aws:secretsmanager:[a-z0-9-]+:[0-9]{12}:secret:.+$", var.db_migrator_secret_arn))
    error_message = "db_migrator_secret_arn must be a Secrets Manager ARN: arn:aws:secretsmanager:<region>:<12-digit-account-id>:secret:<name>."
  }
}

variable "redis_auth_token_secret_arn" {
  description = "Bare ARN of the Redis auth-token secret (a JSON blob with a \"REDIS_PASSWORD\" key — see docs/ecs/staging-variable-inventory.md). Do not append a \":REDIS_PASSWORD::\" selector here; main.tf derives it automatically."
  type        = string
}

variable "redis_auth_token" {
  description = "Same value as redis_auth_token_secret_arn resolves to, needed directly by the aws_elasticache_cluster resource itself (not just by ECS tasks at runtime). Pass via `-var` sourced from Secrets Manager at plan/apply time (e.g. a wrapper script doing `aws secretsmanager get-secret-value`), never committed to a .tfvars file. See infrastructure/ecs/modules/elasticache."
  type        = string
  sensitive   = true
}

# --- SNS topic for alarm notifications — who gets paged is an operational
# decision, not created by this mission. See docs/ecs/alarm-inventory.md. --
variable "alarm_sns_topic_arn" {
  type = string
}

variable "enable_custom_metric_alarms" {
  description = "See infrastructure/ecs/modules/cloudwatch_alarms — false until app-level CloudWatch metric emission exists."
  type        = bool
  default     = false
}

# --- SES bounce/complaint SQS consumer (ses-consumer role) — see
# docs/ecs/container-architecture.md and docs/ecs/iam-matrix.md. -----------
variable "ses_events_queue_url" {
  description = "The SES bounce/complaint SQS queue URL (SES_EVENTS_QUEUE_URL). Plain, non-secret environment — a queue URL is an identifier, not a credential."
  type        = string

  validation {
    condition     = length(var.ses_events_queue_url) > 0 && can(regex("^https://sqs\\.[a-z0-9-]+\\.amazonaws\\.com/[0-9]{12}/[a-zA-Z0-9_-]+$", var.ses_events_queue_url))
    error_message = "ses_events_queue_url must be a non-empty, structurally plausible SQS queue URL: https://sqs.<region>.amazonaws.com/<12-digit-account-id>/<queue-name>."
  }
}

variable "ses_events_queue_arn" {
  description = "ARN of the same SQS queue ses_events_queue_url points at. Passed to the iam module so the ses_consumer task role's policy references var.ses_events_queue_arn rather than a hardcoded ARN — see infrastructure/ecs/modules/iam/main.tf."
  type        = string

  validation {
    condition     = can(regex("^arn:aws:sqs:[a-z0-9-]+:[0-9]{12}:[a-zA-Z0-9_-]+$", var.ses_events_queue_arn))
    error_message = "ses_events_queue_arn must be a valid SQS ARN: arn:aws:sqs:<region>:<12-digit-account-id>:<queue-name>."
  }
}

variable "ses_events_dlq_arn" {
  description = "ARN of the SES bounce/complaint dead-letter queue — used ONLY for the DLQ-backlog CloudWatch alarm (infrastructure/ecs/modules/cloudwatch_alarms). Never passed to the iam module and never granted to any task role: SQS's own redrive policy delivers to the DLQ automatically, and ses-consumer has no need to read from, or delete from, it directly."
  type        = string

  validation {
    condition     = can(regex("^arn:aws:sqs:[a-z0-9-]+:[0-9]{12}:[a-zA-Z0-9_-]+$", var.ses_events_dlq_arn))
    error_message = "ses_events_dlq_arn must be a valid SQS ARN: arn:aws:sqs:<region>:<12-digit-account-id>:<queue-name>."
  }
}

variable "ses_sending_identity_arn" {
  description = "ARN of the verified SES identity (domain or email address) web sends outbound mail from — arn:aws:ses:<region>:<account-id>:identity/<domain>. Passed to the iam module so ONLY the web task role's policy references var.ses_sending_identity_arn rather than a hardcoded ARN. Confirmed by direct code inspection (Illuminate\\Mail\\Transport\\SesTransport, synchronous from the request path — no ShouldQueue mailable/notification exists) that web is the only role that sends mail; see infrastructure/ecs/modules/iam/main.tf and docs/ecs/iam-matrix.md."
  type        = string

  validation {
    condition     = can(regex("^arn:aws:ses:[a-z0-9-]+:[0-9]{12}:identity/.+$", var.ses_sending_identity_arn))
    error_message = "ses_sending_identity_arn must be a valid SES identity ARN: arn:aws:ses:<region>:<12-digit-account-id>:identity/<domain-or-email>."
  }
}

variable "ses_authorized_from_address" {
  description = "The exact From address web's SES send is authorized for — must match MAIL_FROM_ADDRESS in local.shared_environment (main.tf) exactly. Enforced as a ses:FromAddress IAM condition, not just documentation, so the grant cannot be used to send as a different address even though it can reach ses:SendRawEmail."
  type        = string

  validation {
    condition     = can(regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$", var.ses_authorized_from_address))
    error_message = "ses_authorized_from_address must look like a real email address (local-part@domain)."
  }
}

variable "platform_notifications_recipient_fingerprint_hmac_key_secret_arn" {
  description = "Secrets Manager ARN of the dedicated PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY secret — a new, dedicated secret, never APP_KEY. Delivered as an ECS `secrets` entry (resolved by the task execution role at task start) to exactly the web and ses-consumer services; never worker/critical-worker/scheduler/migrate/maintenance. Marked sensitive here because Terraform has no narrower way to flag \"handle this value carefully\" — the ARN itself is an identifier, not the secret value, which never enters Terraform state, a tfvars file, or any output."
  type        = string
  sensitive   = true

  validation {
    condition     = can(regex("^arn:aws:secretsmanager:[a-z0-9-]+:[0-9]{12}:secret:.+$", var.platform_notifications_recipient_fingerprint_hmac_key_secret_arn))
    error_message = "platform_notifications_recipient_fingerprint_hmac_key_secret_arn must be a Secrets Manager ARN: arn:aws:secretsmanager:<region>:<12-digit-account-id>:secret:<name>."
  }
}

variable "ses_events_wait_time_seconds" {
  description = "SES_EVENTS_WAIT_TIME_SECONDS — SQS ReceiveMessage long-poll wait. SQS itself supports 0-20 seconds; see config('services.ses_events.wait_time_seconds')."
  type        = number
  default     = 20

  validation {
    condition     = var.ses_events_wait_time_seconds >= 0 && var.ses_events_wait_time_seconds <= 20
    error_message = "ses_events_wait_time_seconds must be within SQS's own supported range: 0 to 20 seconds."
  }
}

variable "ses_events_visibility_timeout_seconds" {
  description = "SES_EVENTS_VISIBILITY_TIMEOUT_SECONDS — how long a received-but-undeleted message stays invisible to other receivers. SQS's own ceiling is 12 hours (43200s)."
  type        = number
  default     = 60

  validation {
    condition     = var.ses_events_visibility_timeout_seconds > 0 && var.ses_events_visibility_timeout_seconds <= 43200
    error_message = "ses_events_visibility_timeout_seconds must be positive and at most 43200 (SQS's 12-hour maximum)."
  }
}

variable "ses_events_max_messages" {
  description = "SES_EVENTS_MAX_MESSAGES — ReceiveMessage's MaxNumberOfMessages. SQS itself caps this at 10 per call."
  type        = number
  default     = 10

  validation {
    condition     = var.ses_events_max_messages >= 1 && var.ses_events_max_messages <= 10
    error_message = "ses_events_max_messages must be within SQS's own supported range: 1 to 10."
  }
}

variable "ses_consumer_desired_count" {
  description = "ses-consumer ECS service desired task count. 1 by default (a single long-polling consumer is sufficient — SQS's own visibility timeout already prevents two receivers from processing the same message concurrently); 0 is a valid, deliberate way to stop the service (see docs/ecs/runbooks/rollback-runbook.md) without destroying it."
  type        = number
  default     = 1

  validation {
    condition     = var.ses_consumer_desired_count >= 0
    error_message = "ses_consumer_desired_count must be non-negative."
  }
}

variable "web_desired_count" {
  description = "web ECS service desired task count. Defaults to 2 (this module's original design intent — a load-balanced HTTP service with more than one task for availability). This staging environment's live service currently runs 1 (confirmed via aws ecs describe-services) — see docs/ecs/state-adoption-plan.md §9.10/§9.11. Left at its 2 default, applying after import would scale the live service up; set to 1 in terraform.tfvars to match live exactly before any apply."
  type        = number
  default     = 2

  validation {
    condition     = var.web_desired_count >= 0
    error_message = "web_desired_count must be non-negative."
  }
}

variable "worker_desired_count" {
  description = "worker ECS service desired task count. Defaults to 2 (this module's original design intent). This staging environment's live service currently runs 1 (confirmed via aws ecs describe-services) — see docs/ecs/state-adoption-plan.md §9.10/§9.11. Left at its 2 default, applying after import would scale the live service up; set to 1 in terraform.tfvars to match live exactly before any apply."
  type        = number
  default     = 2

  validation {
    condition     = var.worker_desired_count >= 0
    error_message = "worker_desired_count must be non-negative."
  }
}

variable "critical_worker_desired_count" {
  description = "critical_worker ECS service desired task count. Defaults to 1 (this module's original design intent — a fixed-capacity, never-scaled-to-zero worker; see docs/ecs/queue-and-redis-architecture.md). This staging environment's live service also currently runs 1 (confirmed via aws ecs describe-services) — no live-adoption override is required, but the variable exists for consistency with web/worker/scheduler and to make this explicit rather than a hardcoded literal."
  type        = number
  default     = 1

  validation {
    condition     = var.critical_worker_desired_count >= 0
    error_message = "critical_worker_desired_count must be non-negative."
  }
}

variable "scheduler_desired_count" {
  description = "scheduler ECS service desired task count. Defaults to 1 (this module's original design intent — a single instance; see docs/ecs/graceful-shutdown.md). This staging environment's live service also currently runs 1 (confirmed via aws ecs describe-services) — no live-adoption override is required, but the variable exists for consistency with web/worker/critical_worker and to make this explicit rather than a hardcoded literal."
  type        = number
  default     = 1

  validation {
    condition     = var.scheduler_desired_count >= 0
    error_message = "scheduler_desired_count must be non-negative."
  }
}

# ---------------------------------------------------------------------------
# Deployment min/max healthy percent — the ecs_service module's own
# deployment_minimum_healthy_percent/deployment_maximum_percent variables
# already default to 100/200 (this module's original rolling-deployment
# design intent, preserved unchanged for a brand-new environment). This
# staging environment's four live services ALL currently run 0/100
# (confirmed via aws ecs describe-services, 2026-08-05) — previously only
# the scheduler module call overrode these; web/worker/critical_worker fell
# through to the module's 100/200 defaults with no override at all. Left
# unset, applying against these services after import would silently
# propose raising minimum-healthy from 0% to 100%, a materially different
# rolling-deployment mechanic for desired_count=1 services than what is
# live today. Explicit per-role variables (rather than one shared pair)
# match this file's existing per-role pattern (desired_count above) and
# let each role's adoption value diverge from the others later without a
# cross-cutting edit. These values preserve current LIVE BEHAVIOR ONLY for
# state-only adoption; they do not constitute review or approval of 0/100
# as the intended steady-state for a future production-style, no-downtime
# cutover — that deployment strategy remains a separate, later, explicitly
# reviewed decision. See docs/ecs/state-adoption-plan.md §9.20.
# ---------------------------------------------------------------------------

variable "web_deployment_minimum_healthy_percent" {
  description = "web ECS service deployment_minimum_healthy_percent. Defaults to 100 (this module's original design intent). This staging environment's live service currently runs 0 (confirmed via aws ecs describe-services, 2026-08-05) — set to 0 in terraform.tfvars to match live exactly before any apply. See docs/ecs/state-adoption-plan.md §9.20."
  type        = number
  default     = 100

  validation {
    condition     = var.web_deployment_minimum_healthy_percent >= 0 && var.web_deployment_minimum_healthy_percent <= 100
    error_message = "web_deployment_minimum_healthy_percent must be between 0 and 100."
  }
}

variable "web_deployment_maximum_percent" {
  description = "web ECS service deployment_maximum_percent. Defaults to 200 (this module's original design intent). This staging environment's live service currently runs 100 (confirmed via aws ecs describe-services, 2026-08-05) — set to 100 in terraform.tfvars to match live exactly before any apply. See docs/ecs/state-adoption-plan.md §9.20."
  type        = number
  default     = 200

  validation {
    condition     = var.web_deployment_maximum_percent >= 100 && var.web_deployment_maximum_percent <= 200
    error_message = "web_deployment_maximum_percent must be between 100 and 200."
  }
  validation {
    condition     = var.web_deployment_maximum_percent >= var.web_deployment_minimum_healthy_percent
    error_message = "web_deployment_maximum_percent must not be lower than web_deployment_minimum_healthy_percent."
  }
}

variable "worker_deployment_minimum_healthy_percent" {
  description = "worker ECS service deployment_minimum_healthy_percent. Defaults to 100 (this module's original design intent). This staging environment's live service currently runs 0 (confirmed via aws ecs describe-services, 2026-08-05) — set to 0 in terraform.tfvars to match live exactly before any apply. See docs/ecs/state-adoption-plan.md §9.20."
  type        = number
  default     = 100

  validation {
    condition     = var.worker_deployment_minimum_healthy_percent >= 0 && var.worker_deployment_minimum_healthy_percent <= 100
    error_message = "worker_deployment_minimum_healthy_percent must be between 0 and 100."
  }
}

variable "worker_deployment_maximum_percent" {
  description = "worker ECS service deployment_maximum_percent. Defaults to 200 (this module's original design intent). This staging environment's live service currently runs 100 (confirmed via aws ecs describe-services, 2026-08-05) — set to 100 in terraform.tfvars to match live exactly before any apply. See docs/ecs/state-adoption-plan.md §9.20."
  type        = number
  default     = 200

  validation {
    condition     = var.worker_deployment_maximum_percent >= 100 && var.worker_deployment_maximum_percent <= 200
    error_message = "worker_deployment_maximum_percent must be between 100 and 200."
  }
  validation {
    condition     = var.worker_deployment_maximum_percent >= var.worker_deployment_minimum_healthy_percent
    error_message = "worker_deployment_maximum_percent must not be lower than worker_deployment_minimum_healthy_percent."
  }
}

variable "critical_worker_deployment_minimum_healthy_percent" {
  description = "critical_worker ECS service deployment_minimum_healthy_percent. Defaults to 100 (this module's original design intent). This staging environment's live service currently runs 0 (confirmed via aws ecs describe-services, 2026-08-05) — set to 0 in terraform.tfvars to match live exactly before any apply. See docs/ecs/state-adoption-plan.md §9.20."
  type        = number
  default     = 100

  validation {
    condition     = var.critical_worker_deployment_minimum_healthy_percent >= 0 && var.critical_worker_deployment_minimum_healthy_percent <= 100
    error_message = "critical_worker_deployment_minimum_healthy_percent must be between 0 and 100."
  }
}

variable "critical_worker_deployment_maximum_percent" {
  description = "critical_worker ECS service deployment_maximum_percent. Defaults to 200 (this module's original design intent). This staging environment's live service currently runs 100 (confirmed via aws ecs describe-services, 2026-08-05) — set to 100 in terraform.tfvars to match live exactly before any apply. See docs/ecs/state-adoption-plan.md §9.20."
  type        = number
  default     = 200

  validation {
    condition     = var.critical_worker_deployment_maximum_percent >= 100 && var.critical_worker_deployment_maximum_percent <= 200
    error_message = "critical_worker_deployment_maximum_percent must be between 100 and 200."
  }
  validation {
    condition     = var.critical_worker_deployment_maximum_percent >= var.critical_worker_deployment_minimum_healthy_percent
    error_message = "critical_worker_deployment_maximum_percent must not be lower than critical_worker_deployment_minimum_healthy_percent."
  }
}

variable "scheduler_deployment_minimum_healthy_percent" {
  description = "scheduler ECS service deployment_minimum_healthy_percent. Defaults to 100 (this module's original design intent). This staging environment's live service currently runs 0 (confirmed via aws ecs describe-services, 2026-08-05) — set to 0 in terraform.tfvars to match live exactly before any apply. Note: the staging root main.tf previously hardcoded this role's override as a literal 0 directly in the module call; it is now sourced from this variable instead so all four roles are modeled the same explicit way. See docs/ecs/state-adoption-plan.md §9.20."
  type        = number
  default     = 100

  validation {
    condition     = var.scheduler_deployment_minimum_healthy_percent >= 0 && var.scheduler_deployment_minimum_healthy_percent <= 100
    error_message = "scheduler_deployment_minimum_healthy_percent must be between 0 and 100."
  }
}

variable "scheduler_deployment_maximum_percent" {
  description = "scheduler ECS service deployment_maximum_percent. Defaults to 200 (this module's original design intent). This staging environment's live service currently runs 100 (confirmed via aws ecs describe-services, 2026-08-05) — set to 100 in terraform.tfvars to match live exactly before any apply. Note: the staging root main.tf previously hardcoded this role's override as a literal 100 directly in the module call; it is now sourced from this variable instead so all four roles are modeled the same explicit way. See docs/ecs/state-adoption-plan.md §9.20."
  type        = number
  default     = 200

  validation {
    condition     = var.scheduler_deployment_maximum_percent >= 100 && var.scheduler_deployment_maximum_percent <= 200
    error_message = "scheduler_deployment_maximum_percent must be between 100 and 200."
  }
  validation {
    condition     = var.scheduler_deployment_maximum_percent >= var.scheduler_deployment_minimum_healthy_percent
    error_message = "scheduler_deployment_maximum_percent must not be lower than scheduler_deployment_minimum_healthy_percent."
  }
}

# ---------------------------------------------------------------------------
# Service-level tags — the ecs_service module's own `tags` input already
# exists (default {}, this module's original new-environment design
# intent, preserved unchanged here); these four root variables wire it per
# role rather than adding a second, parallel tagging mechanism. This
# staging environment's live `web` service carries five explicit tags
# (confirmed via aws ecs describe-services --include TAGS, 2026-08-05):
# SourceCommit, Environment=staging, ManagedBy=manual-reviewed-deployment,
# ImageDigest, Application=FirmsBase. `worker`/`critical-worker`/
# `scheduler` carry no service-level tags at all live (confirmed the same
# way). NOTE: this environment's AWS provider (see versions.tf) has its
# own `default_tags` block (Project, Environment, ManagedBy, Mission) that
# applies to every resource this provider manages, including all four ECS
# services — that is a provider-wide, cross-cutting configuration, not
# specific to these four services, and is unchanged and out of scope here.
# Because of it, even an exact live-tag `var.tags` value cannot make the
# *effective* apply-time tag set match live exactly: Project/Mission would
# still be added on top for every service (including worker/critical-
# worker/scheduler, which have zero live tags), though Environment/
# ManagedBy correctly resolve to each service's resource-level value where
# one is supplied (resource-level tags win per-key over default_tags). See
# docs/ecs/state-adoption-plan.md §9.20 for the full accounting; reconciling
# default_tags itself is a separate, later, explicitly reviewed decision.
# ---------------------------------------------------------------------------

variable "web_tags" {
  description = "web ECS service tags, wired into the ecs_service module's existing `tags` input. Defaults to {} (this module's original design intent). This staging environment's live service carries five tags (confirmed via aws ecs describe-services --include TAGS, 2026-08-05) — set in terraform.tfvars to match live exactly, including the live ImageDigest tag's value, which is deliberately preserved as-is (it does not match the currently running task definition's actual image digest — a pre-existing, stale metadata inconsistency on the live resource itself, not something this adoption pass corrects; see docs/ecs/state-adoption-plan.md §9.20)."
  type        = map(string)
  default     = {}
}

variable "worker_tags" {
  description = "worker ECS service tags, wired into the ecs_service module's existing `tags` input. Defaults to {} (this module's original design intent). This staging environment's live service currently carries no service-level tags (confirmed via aws ecs describe-services --include TAGS, 2026-08-05) — the {} default already matches live; no override is required in terraform.tfvars."
  type        = map(string)
  default     = {}
}

variable "critical_worker_tags" {
  description = "critical_worker ECS service tags, wired into the ecs_service module's existing `tags` input. Defaults to {} (this module's original design intent). This staging environment's live service currently carries no service-level tags (confirmed via aws ecs describe-services --include TAGS, 2026-08-05) — the {} default already matches live; no override is required in terraform.tfvars."
  type        = map(string)
  default     = {}
}

variable "scheduler_tags" {
  description = "scheduler ECS service tags, wired into the ecs_service module's existing `tags` input. Defaults to {} (this module's original design intent). This staging environment's live service currently carries no service-level tags (confirmed via aws ecs describe-services --include TAGS, 2026-08-05) — the {} default already matches live; no override is required in terraform.tfvars."
  type        = map(string)
  default     = {}
}

variable "ses_consumer_cpu" {
  description = "ses-consumer task CPU units (Fargate). 256 (the smallest Fargate size) — an SQS long-poll loop plus a handful of DB writes per event is not CPU-intensive, matching the scheduler role's own sizing."
  type        = number
  default     = 256
}

variable "ses_consumer_memory" {
  description = "ses-consumer task memory in MiB (Fargate). 512 — matches the scheduler role's own sizing for the same reason (single lightweight PHP process, no request concurrency)."
  type        = number
  default     = 512
}

variable "ses_consumer_stop_timeout" {
  description = "ses-consumer ECS stopTimeout. Must always exceed the SqsClient's own derived HTTP request timeout (var.ses_events_wait_time_seconds + 10s margin — see app/Providers/AppServiceProvider.php) plus real headroom for the consumer's own shutdown-flag check and any in-flight message's processing/delete — otherwise ECS could SIGKILL the task before its own AwsException-driven clean exit ever fires. 45s default: 20s (default wait_time_seconds) + 10s (client margin) + 15s (processing/shutdown headroom), comfortably under ECS's 120s ceiling."
  type        = number
  default     = 45

  validation {
    condition     = var.ses_consumer_stop_timeout > 0 && var.ses_consumer_stop_timeout <= 120
    error_message = "ses_consumer_stop_timeout must be positive and at most 120 (ECS's own stopTimeout ceiling)."
  }

  validation {
    # Terraform 1.9+ allows a validation condition to reference other
    # input variables. Mirrors the exact relationship
    # AppServiceProvider's SqsClient binding establishes at runtime
    # (derived HTTP timeout = wait_time_seconds + 10) plus a minimum
    # 5s of real headroom, so this fails plan/apply rather than
    # silently shipping a stopTimeout that could force a SIGKILL.
    condition     = var.ses_consumer_stop_timeout > (var.ses_events_wait_time_seconds + 10 + 5)
    error_message = "ses_consumer_stop_timeout must exceed ses_events_wait_time_seconds + 10 (the SqsClient's derived HTTP request timeout) by at least 5 seconds of headroom, so ECS never needs to SIGKILL the task before its own clean exit."
  }
}

# ---------------------------------------------------------------------------
# Live-infrastructure-adoption overrides — see docs/ecs/state-adoption-plan.md.
# This environment's AWS resources predate this Terraform config; the
# variables below let the computed identifiers/config match what's already
# running instead of forcing a rename/replacement. All default to null
# (falling back to this file's existing name_prefix-derived computation) so
# a brand-new environment that has never had manual infrastructure is
# completely unaffected.
# ---------------------------------------------------------------------------

variable "ecs_cluster_name" {
  description = "Override for the ECS cluster name. Null (default) falls back to var.name_prefix, matching a brand-new environment. This staging environment's live cluster is \"firmsbase-staging-cluster\", not \"firmsbase-staging\" (what name_prefix alone computes) — ECS cluster names cannot be changed post-creation, so this must be set correctly before any import, never used to rename the live cluster. See docs/ecs/state-adoption-plan.md §3B."
  type        = string
  default     = null
}

variable "ecs_capacity_providers" {
  description = "Capacity providers associated with the ECS cluster. Defaults to [\"FARGATE\", \"FARGATE_SPOT\"] — original module design, unaffected for a brand-new environment. This staging environment's live cluster currently has NO capacity providers associated at all (confirmed via aws ecs describe-clusters — capacityProviders: []) — see docs/ecs/state-adoption-plan.md §9.10/§9.11. Set to [] for live-compatible adoption. Associating capacity providers with the live cluster is a separate, explicitly reviewed decision — this variable only lets the resource address represent the live (empty) association; it does not itself authorize changing it."
  type        = list(string)
  default     = ["FARGATE", "FARGATE_SPOT"]
}

variable "ecs_default_capacity_provider" {
  description = "The capacity_provider used in the cluster's default_capacity_provider_strategy, when var.ecs_capacity_providers is non-empty. Ignored when var.ecs_capacity_providers is empty (the live-adoption case) — see docs/ecs/state-adoption-plan.md §9.10/§9.11."
  type        = string
  default     = "FARGATE"
}

variable "ecs_container_insights_enabled" {
  description = "Whether the ECS cluster's containerInsights setting is enabled. Defaults to true — this module's original design intent, unaffected for a brand-new environment. This staging environment's live cluster actually has it disabled (confirmed via aws ecs describe-clusters) — see docs/ecs/state-adoption-plan.md §9.10/§9.11. Set to false for live-compatible adoption; enabling Container Insights on the live cluster later is a separate decision requiring its own cost and observability review, not a byproduct of import."
  type        = bool
  default     = true
}

variable "ecr_repository_name" {
  description = "Override for the ECR repository name. Null (default) falls back to \"firmsbase-app\" (this file's prior hardcoded value). This staging environment's live repository is \"firmsbase-staging\" — ECR repository renames are destructive (all existing image tags/digests are lost), so this must be set correctly before any import, never used to rename the live repository. See docs/ecs/state-adoption-plan.md §3B."
  type        = string
  default     = null
}

variable "elasticache_subnet_group_name" {
  description = "Override for the ElastiCache subnet group name. Null (default) falls back to \"<name_prefix>-redis\". This staging environment's live subnet group (referenced by the live replication group) is \"firmsbase-staging-cache-subnets\". See docs/ecs/state-adoption-plan.md §3B."
  type        = string
  default     = null
}

variable "elasticache_subnet_ids" {
  description = "Override for the ElastiCache subnet group's subnet membership. Null (default) falls back to var.private_subnet_ids, preserving original behavior for a brand-new environment. This staging environment's live subnet group actually registers 6 subnets across every AZ in the VPC (confirmed via aws elasticache describe-cache-subnet-groups), not just the 2 ECS uses for task placement (var.private_subnet_ids) — conflating the two previously left this resource address unable to represent live's real membership. See docs/ecs/state-adoption-plan.md §9.10/§9.11/§9.15."
  type        = list(string)
  default     = null

  validation {
    condition     = var.elasticache_subnet_ids == null || (length(var.elasticache_subnet_ids) > 0 && length(var.elasticache_subnet_ids) == length(toset(var.elasticache_subnet_ids)))
    error_message = "elasticache_subnet_ids must be null, or a nonempty list of unique subnet IDs (no duplicates)."
  }
}

variable "elasticache_engine" {
  description = "ElastiCache engine. Defaults to \"redis\" (this module's original design), but this staging environment's live replication group actually runs Valkey (confirmed via aws elasticache describe-replication-groups .Engine = \"valkey\"), not Redis — the engine attribute cannot be changed in place; setting it wrong here plans a full, data-losing replacement of the live cache. Must be set to \"valkey\" for this environment before import. See docs/ecs/state-adoption-plan.md §3B/§9."
  type        = string
  default     = "redis"

  validation {
    condition     = contains(["redis", "valkey"], var.elasticache_engine)
    error_message = "elasticache_engine must be \"redis\" or \"valkey\" — those are the only engines aws_elasticache_replication_group supports."
  }
}

variable "elasticache_parameter_group_name" {
  description = "ElastiCache parameter group. Defaults to \"default.redis7\" (this module's original design). This staging environment's live replication group uses \"default.valkey7\" (a Valkey-family parameter group — must match var.elasticache_engine, they cannot disagree). See docs/ecs/state-adoption-plan.md §3B."
  type        = string
  default     = "default.redis7"
}

variable "elasticache_engine_version" {
  description = "ElastiCache engine version. Defaults to \"7.1\" (this module's original Redis-line design), preserving existing behavior for a brand-new environment. This staging environment's live replication group actually runs Valkey, exact reported version 7.2.6 (confirmed via aws elasticache describe-cache-clusters .EngineVersion) — but must be set to \"7.2\" (major.minor only) for this environment before import: AWS's aws_elasticache_replication_group requires major.minor format for Redis v6+/Valkey engine_version, and rejects a major.minor.patch value like \"7.2.6\" outright (confirmed via a real provider validation error during this correction). A \"7.1\" Redis-line version string is meaningless once var.elasticache_engine is \"valkey\". See docs/ecs/state-adoption-plan.md §9.4 and docs/ecs/staging-variable-inventory.md."
  type        = string
  default     = "7.1"
}

variable "elasticache_security_group_name" {
  description = "Override for the ElastiCache module's Redis security group name. Null (default) falls back to the module's own name_prefix-generated pattern, fine for a brand-new environment. This staging environment's live security group has a fixed, pre-existing name \"firmsbase-staging-redis-sg\" (confirmed via aws ec2 describe-security-groups) — name/name_prefix are ForceNew on aws_security_group (the EC2 API has no in-place rename), so leaving this null against an already-imported live security group plans a disruptive replacement of a security group actively in use by the running ElastiCache cluster. See elasticache_security_group_description below (same ForceNew rationale) and docs/ecs/state-adoption-plan.md."
  type        = string
  default     = null
}

variable "elasticache_security_group_description" {
  description = "Override for the ElastiCache module's Redis security group description. Null (default) falls back to the module's own description, fine for a brand-new environment. description is ForceNew on aws_security_group (same reason as elasticache_security_group_name above — no in-place UpdateSecurityGroupDescription call exists). This staging environment's live description is \"Valkey access from FirmsBase staging ECS tasks\" (confirmed via aws ec2 describe-security-groups) — leaving this null against an already-imported live security group plans a disruptive replacement."
  type        = string
  default     = null
}

variable "ecs_tasks_security_group_name" {
  description = "Override for the security_groups module's ECS-tasks security group name. Null (default) falls back to the module's own name_prefix-generated pattern, fine for a brand-new environment. This staging environment's live security group has a fixed, pre-existing name \"firmsbase-staging-ecs-sg\" (confirmed via aws ec2 describe-security-groups) — name/name_prefix are ForceNew on aws_security_group (the EC2 API has no in-place rename), so leaving this null against an already-imported live security group plans a disruptive replacement of a security group actively referenced by both the RDS ingress rule and the Redis ingress rule. See ecs_tasks_security_group_description below (same ForceNew rationale) and docs/ecs/state-adoption-plan.md."
  type        = string
  default     = null
}

variable "ecs_tasks_security_group_description" {
  description = "Override for the security_groups module's ECS-tasks security group description. Null (default) falls back to the module's own description, fine for a brand-new environment. description is ForceNew on aws_security_group (same reason as ecs_tasks_security_group_name above — no in-place UpdateSecurityGroupDescription call exists). This staging environment's live description is \"FirmsBase staging ECS tasks\" (confirmed via aws ec2 describe-security-groups) — leaving this null against an already-imported live security group plans a disruptive replacement."
  type        = string
  default     = null
}

variable "elasticache_subnet_group_description" {
  description = "Override for the ElastiCache subnet group's description. Null (default) leaves the module's own argument unset, which the AWS provider schema then defaults to \"Managed by Terraform\" — fine for a brand-new environment. description is safely updatable in place (never ForceNew), but this staging environment's live subnet group has a real, human-written description, \"Subnets for FirmsBase staging Valkey\" (confirmed via aws elasticache describe-cache-subnet-groups) — leaving this null would propose silently overwriting it with the generic placeholder on every plan."
  type        = string
  default     = null
}

variable "elasticache_replication_group_description" {
  description = "Override for the replication group's description. Null (default) falls back to the module's own description, fine for a brand-new environment. description is safely updatable in place (never ForceNew), but this staging environment's live replication group has a real, human-written description, \"Valkey for FirmsBase staging sessions, cache, and queues\" (confirmed via aws elasticache describe-replication-groups) — leaving this null would propose silently overwriting it on every plan."
  type        = string
  default     = null
}

variable "elasticache_snapshot_retention_limit" {
  description = "Number of days to retain automatic ElastiCache snapshots. Defaults to 0 (disabled) — appropriate for a brand-new staging environment, matching the elasticache module's own \"no durable business data in Redis\" design. This staging environment's live replication group already has automatic backups enabled with SnapshotRetentionLimit=1 (confirmed via aws elasticache describe-replication-groups) — leaving this at the default 0 against an already-imported live replication group proposes a real, live mutation disabling backups, not a cosmetic diff."
  type        = number
  default     = 0
}

variable "iam_task_execution_role_name" {
  description = "Override for the shared ECS task-execution IAM role name. Null (default) falls back to \"<name_prefix>-task-execution\". This staging environment's live execution role is \"firmsbase-staging-ecs-execution-role\" — a naming fix alone is NOT sufficient to make this role import-clean; the live role's permissions come from the AWS-managed AmazonECSTaskExecutionRolePolicy plus one narrow inline policy, while this module builds one broader custom inline policy with no managed-policy attachment. That permission-shape reconciliation is a separate, explicit human decision — see docs/ecs/state-adoption-plan.md §3B/§8 — this variable only fixes the name, not the policy shape."
  type        = string
  default     = null
}

variable "iam_task_execution_policy_name" {
  description = "The name of the task-execution role's inline policy. No default, deliberately — mirrors modules/iam's own task_execution_policy_name (no default, since aws_iam_role_policy's name is effectively immutable and a wrong default would silently set up a replacement rather than fail loudly). This staging environment's live inline policy is named \"FirmsBaseStagingSecretsAccess\" (confirmed via aws iam get-role-policy), not the module's previous hardcoded \"<name_prefix>-task-execution\" — see docs/ecs/state-adoption-plan.md §9.10/§9.11. Aligns identity only; the policy-content/permission-shape migration referenced above for iam_task_execution_role_name remains a separate decision."
  type        = string
}

variable "iam_task_execution_managed_policy_arn" {
  description = "The AWS-managed policy ARN attached to the task-execution role via a separate, non-exclusive aws_iam_role_policy_attachment. No default, deliberately — mirrors iam_task_execution_policy_name's own no-default pattern above. This staging environment's live role has exactly one attached managed policy (confirmed via aws iam list-attached-role-policies): arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy. See docs/ecs/state-adoption-plan.md §9.18."
  type        = string

  validation {
    condition     = can(regex("^arn:aws:iam::(aws|[0-9]{12}):policy/.+$", var.iam_task_execution_managed_policy_arn))
    error_message = "iam_task_execution_managed_policy_arn must be a valid IAM policy ARN (AWS-managed or customer-managed)."
  }
}

variable "iam_task_execution_secrets_policy_sid" {
  description = "The Sid of the task-execution role's inline secrets-read statement. No default, deliberately — mirrors iam_task_execution_policy_name's own no-default pattern above. This staging environment's live inline policy's sole statement has Sid \"ReadFirmsBaseStagingSecrets\" (confirmed via aws iam get-role-policy) — a prior import mission wired this module's previous hardcoded \"ReadTaskSecrets\" instead, which never matched live. See docs/ecs/state-adoption-plan.md §9.19."
  type        = string
}

variable "aws_account_id" {
  description = "AWS account ID this staging environment runs in. Wired only into module.iam's shared assume-role trust-policy scoping (aws:SourceAccount/aws:SourceArn confused-deputy conditions) — this staging environment's live execution role AND its live generic task role (firmsbase-staging-ecs-execution-role, firmsbase-staging-ecs-task-role) both carry the identical StringEquals aws:SourceAccount=603013471426 / ArnLike aws:SourceArn=arn:aws:ecs:us-east-1:603013471426:* conditions (confirmed via aws iam get-role on both, 2026-08-05), so this one value is shared, not role-specific. See docs/ecs/state-adoption-plan.md §9.17."
  type        = string

  validation {
    condition     = can(regex("^[0-9]{12}$", var.aws_account_id))
    error_message = "aws_account_id must be exactly 12 digits."
  }
}

variable "iam_task_execution_role_description" {
  description = "The task-execution role's description. No default, deliberately — mirrors iam_task_execution_policy_name's own no-default pattern above: this module previously declared no description at all, so a default here risks silently applying a description that doesn't match live rather than failing loudly. This staging environment's live execution role description is \"Execution role for FirmsBase staging ECS tasks\" (confirmed via aws iam get-role, 2026-08-05). See docs/ecs/state-adoption-plan.md §9.17."
  type        = string
}

# ---------------------------------------------------------------------------
# Public-IP / NAT-egress safety invariant — see docs/ecs/state-adoption-plan.md
# §9.1. This staging VPC is the AWS account's DEFAULT VPC: every subnet is
# public (MapPublicIpOnLaunch = true) and there is no NAT gateway anywhere in
# it (confirmed via the sole route table having only a direct Internet
# Gateway route). assignPublicIp = ENABLED is therefore the ONLY way any ECS
# task in this environment reaches the internet at all today — for ECR
# pulls, Secrets Manager, CloudWatch Logs, SES, and SQS alike. Disabling it
# without real private-subnet + NAT egress in place would cut off all
# outbound connectivity for every service simultaneously.
# ---------------------------------------------------------------------------

variable "private_egress_ready" {
  description = "Set to true ONLY after this VPC has real private subnets with verified NAT gateway (or equivalent) egress provisioned — see nat_gateway_ids below, which this variable's validation requires be non-empty before private_egress_ready can be true. While false (the default — matching today's live reality), every ECS service is forced to assign_public_ip = true. Do not flip this to true as a config toggle alone; it must follow, never precede, real NAT infrastructure existing and being verified reachable."
  type        = bool
  default     = false
}

variable "nat_gateway_ids" {
  description = "The real NAT gateway ID(s) this environment's private subnets route egress through. Not consumed directly by any resource in this repository today (the networking module remains data-source-only by design — see infrastructure/ecs/modules/networking), so this variable's only job is to make \"NAT egress genuinely exists\" a checkable fact tied to private_egress_ready, rather than a bare boolean assertion that could be flipped by mistake."
  type        = list(string)
  default     = []

  validation {
    # Terraform 1.9+ cross-variable validation — same pattern already used
    # by ses_consumer_stop_timeout above.
    condition     = !var.private_egress_ready || length(var.nat_gateway_ids) > 0
    error_message = "private_egress_ready = true requires at least one nat_gateway_ids entry. Do not set private_egress_ready = true without real, verified NAT egress in place — see docs/ecs/state-adoption-plan.md §9.1 (this VPC currently has no NAT gateway at all; disabling assign_public_ip without one cuts off all outbound connectivity for every ECS service)."
  }
}

# ---------------------------------------------------------------------------
# ALB target-group health-check adoption overrides — see
# docs/ecs/state-adoption-plan.md §9.5/§11. All default to this module's
# original design values, so a brand-new environment is unaffected. THIS
# staging environment's live target group differs on all three fields
# (confirmed via aws elbv2 describe-target-groups/describe-target-group-
# attributes) — see terraform.tfvars.example for the exact live-matching
# override values, which must be supplied before
# module.alb.aws_lb_target_group.web can be imported.
# ---------------------------------------------------------------------------

variable "alb_health_check_path" {
  description = "See infrastructure/ecs/modules/alb's readiness_health_check_path. Default \"/readyz\" matches this module's original readiness-check design; this staging environment's live target group actually probes \"/up\" (liveness) — see docs/ecs/state-adoption-plan.md §9.5."
  type        = string
  default     = "/readyz"
}

variable "alb_health_check_interval_seconds" {
  description = "See infrastructure/ecs/modules/alb's health_check_interval_seconds. Default 15 matches this module's original design; this staging environment's live target group actually uses 30 — see docs/ecs/state-adoption-plan.md §9.5."
  type        = number
  default     = 15
}

variable "alb_health_check_matcher" {
  description = "See infrastructure/ecs/modules/alb's health_check_matcher. Default \"200\" matches this module's original design; this staging environment's live target group actually uses \"200-399\" — see docs/ecs/state-adoption-plan.md §9.5."
  type        = string
  default     = "200"

  validation {
    condition     = can(regex("^[0-9]{3}(-[0-9]{3})?(,[0-9]{3}(-[0-9]{3})?)*$", var.alb_health_check_matcher))
    error_message = "alb_health_check_matcher must be an ALB-compatible HTTP code or range such as 200 or 200-399."
  }
}
