# Proves var.task_execution_role_description resolves the way
# docs/ecs/state-adoption-plan.md §9.17 claims: this module previously
# declared no description at all on aws_iam_role.task_execution, but the
# live execution role's description is "Execution role for FirmsBase
# staging ECS tasks" (confirmed via aws iam get-role). description is a
# plain attribute reference (var.task_execution_role_description), not a
# data-source-computed json value, so — unlike the assume-role trust
# policy's actual condition content (see
# tests/Feature/Ecs/StagingIamExecutionRoleTrustPolicyTest.php for why
# that must be proven via a source-text test instead) — it can be proven
# directly here under mock_provider.
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
  name_prefix                        = "firmsbase-staging"
  aws_account_id                     = "603013471426"
  aws_region                         = "us-east-1"
  task_execution_managed_policy_arn  = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
  task_execution_secret_arns         = ["arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/mock-secret"]
  task_execution_secrets_policy_sid  = "ReadFirmsBaseStagingSecrets"
  task_execution_kms_decrypt_enabled = false
  kms_key_arn                        = null
  kms_encryption_enabled             = false
  s3_documents_bucket_arn            = null
  s3_documents_enabled               = false
  ses_events_queue_arn               = null
  ses_sending_identity_arn           = null
  ses_authorized_from_address        = null
  task_execution_policy_name         = "firmsbase-staging-task-execution"
  # task_execution_role_description is the variable under test — no
  # shared default here (it has no default in variables.tf); each run
  # below sets it explicitly.
  task_execution_role_description = "some description"
}

run "description_resolves_to_the_value_supplied" {
  command = plan

  assert {
    condition     = aws_iam_role.task_execution.description == "some description"
    error_message = "aws_iam_role.task_execution.description must equal exactly what task_execution_role_description supplies."
  }
}

run "description_resolves_to_the_confirmed_live_value_when_set_to_it" {
  command = plan

  variables {
    task_execution_role_description = "Execution role for FirmsBase staging ECS tasks"
  }

  assert {
    condition     = aws_iam_role.task_execution.description == "Execution role for FirmsBase staging ECS tasks"
    error_message = "With task_execution_role_description set to the confirmed live value, aws_iam_role.task_execution.description must resolve to it exactly."
  }
}

run "empty_description_is_rejected" {
  command = plan

  variables {
    task_execution_role_description = "   "
  }

  expect_failures = [
    var.task_execution_role_description,
  ]
}

run "resource_address_and_name_are_unaffected_by_the_description" {
  command = plan

  variables {
    task_execution_role_description = "Execution role for FirmsBase staging ECS tasks"
    task_execution_role_name        = "firmsbase-staging-ecs-execution-role"
  }

  assert {
    condition     = aws_iam_role.task_execution.name == "firmsbase-staging-ecs-execution-role"
    error_message = "Setting the description must not affect the role's name or resource address (aws_iam_role.task_execution)."
  }
}
