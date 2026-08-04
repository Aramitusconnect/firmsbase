# Proves var.task_execution_policy_name resolves the way
# docs/ecs/state-adoption-plan.md §9.10/§9.11 claims: this module
# previously hardcoded the inline policy's name to
# "${name_prefix}-task-execution", but this staging environment's live
# inline policy is actually named "FirmsBaseStagingSecretsAccess"
# (confirmed via aws iam get-role-policy) — aws_iam_role_policy's name is
# effectively immutable, so getting this wrong sets up a replacement on
# the next plan, not merely a content diff. This variable aligns identity
# only; it intentionally proves nothing about policy content/permission
# shape, which remains a separate, documented decision (see naming.tftest.hcl).
#
# Run with: terraform test (from infrastructure/ecs/modules/iam)

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
  name_prefix                 = "firmsbase-staging"
  ecr_repository_arn          = "arn:aws:ecr:us-east-1:603013471426:repository/firmsbase-staging"
  log_group_arns              = ["arn:aws:logs:us-east-1:603013471426:log-group:/ecs/firmsbase-staging/web:*"]
  secret_arns                 = []
  kms_key_arn                 = null
  kms_encryption_enabled      = false
  s3_documents_bucket_arn     = null
  s3_documents_enabled        = false
  ses_events_queue_arn        = null
  ses_sending_identity_arn    = null
  ses_authorized_from_address = null
  # task_execution_policy_name is the variable under test — no shared
  # default here (it has no default in variables.tf); each run below sets
  # it explicitly.
  task_execution_policy_name = "firmsbase-staging-task-execution"
}

run "resolves_to_the_value_supplied" {
  command = plan

  assert {
    condition     = aws_iam_role_policy.task_execution.name == "firmsbase-staging-task-execution"
    error_message = "aws_iam_role_policy.task_execution.name must equal exactly what task_execution_policy_name supplies."
  }
}

run "resolves_to_the_confirmed_live_value_when_set_to_it" {
  command = plan

  variables {
    task_execution_policy_name = "FirmsBaseStagingSecretsAccess"
  }

  assert {
    condition     = aws_iam_role_policy.task_execution.name == "FirmsBaseStagingSecretsAccess"
    error_message = "With task_execution_policy_name set to the confirmed live value, aws_iam_role_policy.task_execution.name must resolve to it exactly — this is what makes the resource address importable against the live inline policy without a delete+recreate."
  }
}

run "resource_address_is_unaffected_by_the_policy_name" {
  # command = apply is needed here (still 100% mocked, zero real AWS
  # contact): aws_iam_role.task_execution.id is a purely provider-computed
  # attribute (never set by config), so it remains unknown at plan time
  # even under mock_provider — matching this repo's established pattern
  # (see naming.tftest.hcl / task_role_and_flags.tftest.hcl).
  command = apply

  variables {
    task_execution_policy_name = "FirmsBaseStagingSecretsAccess"
  }

  assert {
    condition     = aws_iam_role_policy.task_execution.role == aws_iam_role.task_execution.id
    error_message = "Changing the policy's name must not change which role it attaches to, nor its own resource address (aws_iam_role_policy.task_execution) — only the name attribute changes."
  }
}
