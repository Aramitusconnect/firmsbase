# Proves task_execution_role_name's naming-override fallback resolves the
# way docs/ecs/state-adoption-plan.md §3B claims. Optional pass-through
# variables (s3_documents_bucket_arn, ses_events_queue_arn,
# ses_sending_identity_arn/ses_authorized_from_address) are left null so
# the count-gated policy resources never get created — only
# ecs_tasks_assume_role, task_execution, and task_metrics (unconditional)
# need their JSON overridden below; a blanket mock_provider can't compute
# real aws_iam_policy_document JSON, so this file supplies fixed valid
# policy JSON instead of exercising that logic (out of scope here).

mock_provider "aws" {}

override_data {
  target = data.aws_iam_policy_document.ecs_tasks_assume_role
  values = {
    json = "{\"Version\":\"2012-10-17\",\"Statement\":[]}"
  }
}

override_data {
  target = data.aws_iam_policy_document.task_execution
  values = {
    json = "{\"Version\":\"2012-10-17\",\"Statement\":[]}"
  }
}

override_data {
  target = data.aws_iam_policy_document.task_metrics
  values = {
    json = "{\"Version\":\"2012-10-17\",\"Statement\":[]}"
  }
}

variables {
  name_prefix                     = "firmsbase-staging"
  aws_account_id                  = "603013471426"
  aws_region                      = "us-east-1"
  task_execution_role_description = "Execution role for FirmsBase staging ECS tasks"
  ecr_repository_arn              = "arn:aws:ecr:us-east-1:603013471426:repository/firmsbase-staging"
  log_group_arns                  = ["arn:aws:logs:us-east-1:603013471426:log-group:/ecs/firmsbase-staging/web:*"]
  secret_arns                     = []
  kms_key_arn                     = null
  # task_execution_policy_name has no default (see variables.tf) — every
  # caller must set it explicitly. This file isn't exercising this
  # variable's own behavior (see task_execution_policy_name.tftest.hcl),
  # so it's set to the module's previous hardcoded value here.
  task_execution_policy_name = "firmsbase-staging-task-execution"
  # kms_encryption_enabled/s3_documents_enabled have no default (see
  # variables.tf) — every caller must set them explicitly. This file isn't
  # exercising either grant, so both are explicitly false, matching
  # kms_key_arn/s3_documents_bucket_arn both being null here.
  kms_encryption_enabled      = false
  s3_documents_bucket_arn     = null
  s3_documents_enabled        = false
  ses_events_queue_arn        = null
  ses_sending_identity_arn    = null
  ses_authorized_from_address = null
}

run "task_execution_role_defaults_to_original_computation" {
  command = plan

  assert {
    condition     = aws_iam_role.task_execution.name == "firmsbase-staging-task-execution"
    error_message = "Without task_execution_role_name set, must fall back to \"<name_prefix>-task-execution\" — original behavior must not change for a brand-new environment."
  }
}

run "task_execution_role_resolves_to_live_value_when_overridden" {
  command = plan

  variables {
    task_execution_role_name = "firmsbase-staging-ecs-execution-role"
  }

  assert {
    condition     = aws_iam_role.task_execution.name == "firmsbase-staging-ecs-execution-role"
    error_message = "Must resolve to the exact live execution role name — see docs/ecs/state-adoption-plan.md §3B (naming fix only; policy-shape reconciliation is a separate documented decision, not covered by this variable)."
  }
}
