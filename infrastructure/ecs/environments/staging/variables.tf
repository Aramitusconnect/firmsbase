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

variable "iam_task_execution_role_name" {
  description = "Override for the shared ECS task-execution IAM role name. Null (default) falls back to \"<name_prefix>-task-execution\". This staging environment's live execution role is \"firmsbase-staging-ecs-execution-role\" — a naming fix alone is NOT sufficient to make this role import-clean; the live role's permissions come from the AWS-managed AmazonECSTaskExecutionRolePolicy plus one narrow inline policy, while this module builds one broader custom inline policy with no managed-policy attachment. That permission-shape reconciliation is a separate, explicit human decision — see docs/ecs/state-adoption-plan.md §3B/§8 — this variable only fixes the name, not the policy shape."
  type        = string
  default     = null
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
