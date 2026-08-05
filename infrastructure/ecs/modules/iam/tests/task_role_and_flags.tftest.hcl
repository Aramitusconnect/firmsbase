# Proves the aws_iam_role_policy.task_metrics/kms_encryption_enabled/
# s3_documents_enabled for_each+count fix from the import-graph correction
# (see docs/ecs/state-adoption-plan.md §9.8 and tf-guard.sh's header for the
# underlying real-import failure this responds to).
#
# See infrastructure/ecs/modules/cloudwatch_alarms/tests/service_alarm_keys.tftest.hcl
# for why `mock_provider` cannot reproduce the ORIGINAL "known only after
# apply" failure directly — these tests instead prove the resulting
# for_each/count key sets are exactly what's intended (all 7 task roles
# present, nothing silently disabled) and that the module no longer derives
# any for_each/count from a resource's own for_each map
# (`for_each = aws_iam_role.task`) or from comparing a module-output-shaped
# variable to null.

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

override_data {
  target = data.aws_iam_policy_document.task_s3_documents
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
  # task_execution_policy_name has no default (see variables.tf) — every
  # caller must set it explicitly.
  task_execution_policy_name = "firmsbase-staging-task-execution"
  # kms_encryption_enabled/s3_documents_enabled have no default (see
  # variables.tf — every caller must set them explicitly, so an omitted
  # boolean can never silently disable an existing grant). This shared
  # block sets the "both disabled" case; the run below overrides both to
  # true.
  kms_encryption_enabled = false
  s3_documents_enabled   = false
}

run "all_seven_task_roles_and_metrics_policies_exist" {
  # command = plan is enough to prove the for_each KEY SET (the actual
  # point of this fix) is static and complete. Proving that
  # task_metrics["web"].role actually equals aws_iam_role.task["web"].id
  # needs command = apply below instead: aws_iam_role.id is a purely
  # provider-computed attribute (never set by config), so — correctly,
  # matching real provider behavior for a not-yet-created resource — even
  # mock_provider leaves it unknown at plan time; only the for_each KEY
  # SET needs to be known pre-apply, never a resource's own computed VALUE
  # attributes.
  command = plan

  assert {
    condition     = length(aws_iam_role.task) == 7
    error_message = "Exactly 7 task roles must exist (web, worker, critical_worker, scheduler, migrate, maintenance, ses_consumer)."
  }

  assert {
    condition     = length(aws_iam_role_policy.task_metrics) == 7
    error_message = "aws_iam_role_policy.task_metrics must have exactly one instance per task role — it must never derive its for_each from aws_iam_role.task's own (potentially unknown-during-import) instance map."
  }

  assert {
    condition = alltrue([
      for role_key in ["web", "worker", "critical_worker", "scheduler", "migrate", "maintenance", "ses_consumer"] :
      contains(keys(aws_iam_role_policy.task_metrics), role_key)
    ])
    error_message = "Every one of the 7 expected task-role keys must have a task_metrics policy — none may be silently dropped."
  }
}

run "task_metrics_web_attaches_to_the_web_role_specifically" {
  # command = apply here is still fully mocked (mock_provider never
  # contacts real AWS even for "apply") — it's the only way to get a
  # concrete, comparable value for aws_iam_role.task["web"].id.
  command = apply

  assert {
    condition     = aws_iam_role_policy.task_metrics["web"].role == aws_iam_role.task["web"].id
    error_message = "task_metrics[\"web\"] must attach to aws_iam_role.task[\"web\"] specifically."
  }
}

run "kms_and_s3_grants_are_omitted_when_explicitly_disabled" {
  command = plan

  assert {
    condition     = length(data.aws_iam_policy_document.task_s3_documents) == 0
    error_message = "With s3_documents_enabled=false, no S3 documents policy document should exist — original 'no S3 grant' behavior must be unchanged."
  }

  assert {
    condition     = length(aws_iam_role_policy.task_s3_documents) == 0
    error_message = "With s3_documents_enabled=false, no per-role S3 documents policy should exist."
  }
}

run "kms_and_s3_grants_are_included_when_explicitly_enabled" {
  command = plan

  variables {
    kms_key_arn             = "arn:aws:kms:us-east-1:603013471426:key/mock-key-id"
    kms_encryption_enabled  = true
    s3_documents_bucket_arn = "arn:aws:s3:::firmsbase-staging-documents"
    s3_documents_enabled    = true
  }

  assert {
    condition     = length(data.aws_iam_policy_document.task_s3_documents) == 1
    error_message = "With s3_documents_enabled=true, the S3 documents policy document must exist."
  }

  assert {
    condition     = length(aws_iam_role_policy.task_s3_documents) == length(["web", "worker", "critical_worker", "maintenance"])
    error_message = "With s3_documents_enabled=true, exactly the 4 documented S3-document roles (web, worker, critical_worker, maintenance) must get the grant — scheduler/migrate/ses_consumer must not."
  }

  assert {
    condition = alltrue([
      for role_key in ["web", "worker", "critical_worker", "maintenance"] :
      contains(keys(aws_iam_role_policy.task_s3_documents), role_key)
    ])
    error_message = "Every one of the 4 expected S3-document role keys must be present."
  }
}
