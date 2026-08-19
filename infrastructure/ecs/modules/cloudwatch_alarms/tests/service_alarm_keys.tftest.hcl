# Proves the ecs_service_running_count/ecs_service_cpu_high/
# ses_consumer_errors*/for_each+count fix from the import-graph correction
# (see docs/ecs/state-adoption-plan.md and
# infrastructure/ecs/environments/staging/scripts/tf-guard.sh's header for
# the underlying real-import failure this responds to).
#
# IMPORTANT LIMITATION, stated plainly: `mock_provider` synthesizes a KNOWN
# value for every computed attribute, even for a resource that has never
# been created — this is fundamentally different from a REAL provider's
# create-plan (or `terraform import`'s broader graph evaluation), which can
# leave even plain input-echoed attributes marked "known after apply" for a
# not-yet-existing resource. That difference is exactly why the original
# bug (`var.ses_consumer_service_name == null` collapsing the whole
# for_each to "unknown" when the value came from a not-yet-created ECS
# service) could never be reproduced under `terraform test` + mock_provider
# in the first place — mock_provider always hands back a known string. This
# file therefore cannot re-create the original failure; what it proves
# instead is the STRUCTURAL property the fix relies on: the for_each/count
# KEY SET here now depends only on the literal var.ses_consumer_enabled
# boolean, and the resulting instance keys/counts are exactly what's
# intended (all expected keys present, no role silently dropped). The
# corresponding source-level proof that no `== null` check on a
# module-output-derived variable determines these keys anymore lives in
# tests/Feature/Ecs/StagingImportGraphSafetyTest.php.
#
# Run with: terraform test (from infrastructure/ecs/modules/cloudwatch_alarms)

mock_provider "aws" {}

variables {
  name_prefix                  = "firmsbase-staging"
  sns_topic_arn                = "arn:aws:sns:us-east-1:603013471426:firmsbase-staging-alarms"
  alb_arn_suffix               = "app/firmsbase-staging-alb/79a16ccaf391d71b"
  target_group_arn_suffix      = "targetgroup/firmsbase-staging-tg/1830c01b9aaac37d"
  ecs_cluster_name             = "firmsbase-staging-cluster"
  web_service_name             = "firmsbase-staging-web"
  general_worker_service_name  = "firmsbase-staging-worker"
  critical_worker_service_name = "firmsbase-staging-critical-worker"
  rds_instance_id              = "firmsbase-staging-db"
  redis_cluster_id             = "firmsbase-staging-redis"
  # ses_consumer_enabled has no default (see variables.tf — every caller
  # must set it explicitly, so an omitted boolean can never silently
  # disable existing ses-consumer alarms). This shared block sets the
  # "disabled" case; the run below overrides it to true.
  ses_consumer_enabled = false
}

run "ses_consumer_enabled_includes_all_four_service_alarm_keys" {
  command = plan

  variables {
    ses_consumer_enabled        = true
    ses_consumer_service_name   = "firmsbase-staging-ses-consumer"
    ses_consumer_log_group_name = "/ecs/firmsbase-staging/ses-consumer"
  }

  assert {
    condition     = length(aws_cloudwatch_metric_alarm.ecs_service_running_count) == 4
    error_message = "With ses_consumer_enabled=true, exactly 4 running-count alarms must exist (web, general_worker, critical_worker, ses_consumer)."
  }

  assert {
    condition     = length(aws_cloudwatch_metric_alarm.ecs_service_cpu_high) == 4
    error_message = "With ses_consumer_enabled=true, exactly 4 CPU alarms must exist."
  }

  assert {
    condition     = aws_cloudwatch_metric_alarm.ecs_service_running_count["ses_consumer"].alarm_name == "firmsbase-staging-ses_consumer-running-count-low"
    error_message = "The ses_consumer key must exist and resolve to the expected alarm name — it must never be silently dropped."
  }

  assert {
    condition     = length(aws_cloudwatch_log_metric_filter.ses_consumer_errors) == 1
    error_message = "With ses_consumer_enabled=true, the ses-consumer error-log metric filter must exist."
  }

  assert {
    condition     = length(aws_cloudwatch_metric_alarm.ses_consumer_errors_high) == 1
    error_message = "With ses_consumer_enabled=true, the ses-consumer errors-high alarm must exist."
  }
}

run "ses_consumer_disabled_omits_exactly_the_ses_consumer_key" {
  command = plan

  # Inherits ses_consumer_enabled = false from the shared block above.
  # ses_consumer_service_name/ses_consumer_log_group_name are deliberately
  # left at their own default (null) too, matching a caller that has no
  # ses-consumer at all.

  assert {
    condition     = length(aws_cloudwatch_metric_alarm.ecs_service_running_count) == 3
    error_message = "With ses_consumer_enabled=false (default), exactly 3 running-count alarms must exist (web, general_worker, critical_worker only) — original 'no ses-consumer' behavior must be unchanged."
  }

  assert {
    condition     = length(aws_cloudwatch_metric_alarm.ecs_service_cpu_high) == 3
    error_message = "With ses_consumer_enabled=false (default), exactly 3 CPU alarms must exist."
  }

  assert {
    condition     = length(aws_cloudwatch_log_metric_filter.ses_consumer_errors) == 0
    error_message = "With ses_consumer_enabled=false (default), no ses-consumer error-log metric filter should exist."
  }

  assert {
    condition     = length(aws_cloudwatch_metric_alarm.ses_consumer_errors_high) == 0
    error_message = "With ses_consumer_enabled=false (default), no ses-consumer errors-high alarm should exist."
  }
}

run "web_general_worker_critical_worker_keys_always_present_regardless_of_ses_consumer" {
  command = plan

  assert {
    condition = alltrue([
      contains(keys(aws_cloudwatch_metric_alarm.ecs_service_running_count), "web"),
      contains(keys(aws_cloudwatch_metric_alarm.ecs_service_running_count), "general_worker"),
      contains(keys(aws_cloudwatch_metric_alarm.ecs_service_running_count), "critical_worker"),
    ])
    error_message = "web/general_worker/critical_worker must never be silently disabled regardless of the ses_consumer_enabled setting."
  }
}
