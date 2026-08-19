# Proves the permissions-boundary wiring, offline.
#
# The boundary attached to each role IS the security decision — a role wired to
# the wrong ceiling looks completely normal in a plan unless somebody reads all
# eight pairings. These runs assert the pairings mechanically instead.
#
# permissions_boundary is a configured argument (never a computed attribute), so
# command = plan resolves it to a real string even under mock_provider, and
# these assertions need no AWS credentials and create nothing.
mock_provider "aws" {}

# mock_provider cannot compute aws_iam_policy_document, so every one this
# module renders must be overridden with real JSON or the role resources fail
# validation before any boundary assertion is reached. Same pattern as
# tests/task_role_and_flags.tftest.hcl.
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

override_data {
  target = data.aws_iam_policy_document.task_ses_consumer_sqs
  values = {
    json = "{\"Version\":\"2012-10-17\",\"Statement\":[]}"
  }
}

override_data {
  target = data.aws_iam_policy_document.task_web_ses_send
  values = {
    json = "{\"Version\":\"2012-10-17\",\"Statement\":[]}"
  }
}

variables {
  name_prefix                        = "firmsbase-production"
  aws_account_id                     = "603013471426"
  aws_region                         = "us-east-1"
  kms_key_arn                        = "arn:aws:kms:us-east-1:603013471426:key/mock-key-id"
  task_execution_policy_name         = "firmsbase-production-task-execution-secrets"
  task_execution_role_description    = "mock"
  task_execution_secret_arns         = ["arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/production/mock-secret"]
  task_execution_managed_policy_arn  = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
  task_execution_secrets_policy_sid  = "MockSecrets"
  task_execution_kms_decrypt_enabled = true

  # kms_encryption_enabled/s3_documents_enabled have no default by design, so
  # every caller states them explicitly. Irrelevant to boundaries — set to the
  # "both off" case to keep these runs focused on the boundary wiring alone.
  kms_encryption_enabled = false
  s3_documents_enabled   = false
}

# The imported staging roles carry no boundary. Supplying one there would be a
# live mutation on an existing role, so absence has to stay the default.
run "no_boundary_is_attached_by_default" {
  command = plan

  assert {
    condition     = aws_iam_role.task_execution.permissions_boundary == null
    error_message = "The execution role must have no permissions boundary unless one is explicitly supplied."
  }

  assert {
    condition = alltrue([
      for key, role in aws_iam_role.task : role.permissions_boundary == null
    ])
    error_message = "Task roles must have no permissions boundary unless one is explicitly supplied."
  }
}

run "production_boundaries_land_on_exactly_the_intended_roles" {
  command = plan

  variables {
    task_execution_permissions_boundary_arn = "arn:aws:iam::603013471426:policy/firmsbase-production-execution-boundary"

    task_permissions_boundary_arns = {
      web             = "arn:aws:iam::603013471426:policy/firmsbase-production-task-boundary"
      worker          = "arn:aws:iam::603013471426:policy/firmsbase-production-task-boundary"
      critical_worker = "arn:aws:iam::603013471426:policy/firmsbase-production-task-boundary"
      scheduler       = "arn:aws:iam::603013471426:policy/firmsbase-production-task-boundary"
      maintenance     = "arn:aws:iam::603013471426:policy/firmsbase-production-task-boundary"
      ses_consumer    = "arn:aws:iam::603013471426:policy/firmsbase-production-task-boundary"
      migrate         = "arn:aws:iam::603013471426:policy/firmsbase-production-migrator-boundary"
    }
  }

  # The ECS agent's role — image pull, log write, secret read. Never the
  # application boundary, which permits none of those.
  assert {
    condition     = aws_iam_role.task_execution.permissions_boundary == "arn:aws:iam::603013471426:policy/firmsbase-production-execution-boundary"
    error_message = "firmsbase-production-task-execution must be bounded by firmsbase-production-execution-boundary."
  }

  # The one role holding DDL authority gets its own ceiling, so widening the
  # application boundary can never widen schema authority.
  assert {
    condition     = aws_iam_role.task["migrate"].permissions_boundary == "arn:aws:iam::603013471426:policy/firmsbase-production-migrator-boundary"
    error_message = "firmsbase-production-task-migrate must be bounded by firmsbase-production-migrator-boundary."
  }

  # Asserted as "every role EXCEPT migrate", not as a list of six names, so a
  # newly added task role is caught here rather than defaulting into silence.
  assert {
    condition = alltrue([
      for key, role in aws_iam_role.task :
      role.permissions_boundary == "arn:aws:iam::603013471426:policy/firmsbase-production-task-boundary"
      if key != "migrate"
    ])
    error_message = "Every application task role except migrate must be bounded by firmsbase-production-task-boundary."
  }

  # Guards the separation itself: the three boundaries must stay distinct.
  assert {
    condition = length(toset([
      aws_iam_role.task_execution.permissions_boundary,
      aws_iam_role.task["migrate"].permissions_boundary,
      aws_iam_role.task["web"].permissions_boundary,
    ])) == 3
    error_message = "execution, migrator and task boundaries must be three distinct policies."
  }

  # All eight roles bounded — no role slips through unbounded.
  assert {
    condition = length([
      for b in concat([aws_iam_role.task_execution.permissions_boundary],
      [for _, r in aws_iam_role.task : r.permissions_boundary]) : b if b != null
    ]) == 8
    error_message = "All 8 production roles must carry a permissions boundary."
  }
}

# A partial map must fail the plan rather than leave some role unbounded.
run "a_task_role_missing_from_the_boundary_map_is_rejected" {
  command = plan

  variables {
    task_permissions_boundary_arns = {
      web = "arn:aws:iam::603013471426:policy/firmsbase-production-task-boundary"
    }
  }

  expect_failures = [var.task_permissions_boundary_arns]
}

run "a_boundary_value_that_is_not_a_policy_arn_is_rejected" {
  command = plan

  variables {
    task_execution_permissions_boundary_arn = "firmsbase-production-execution-boundary"
  }

  expect_failures = [var.task_execution_permissions_boundary_arn]
}
